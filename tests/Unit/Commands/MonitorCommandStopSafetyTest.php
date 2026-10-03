<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use ClaudeProjectManager\Commands\MonitorCommand;
use ClaudeProjectManager\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cpm monitor --stop` must only signal a verified CPM monitor process. A
 * monitor.pid committed by a hostile repository must not be able to make CPM
 * kill an arbitrary process of the user.
 *
 * The test uses a harmless `sleep` child as the victim. It deliberately never
 * writes "-1" or "0" into the PID file: against unfixed code that would
 * signal every process of the user, including the test runner.
 */
final class MonitorCommandStopSafetyTest extends TestCase
{
    private string $previousCwd;
    private string $tempDir;
    /** @var resource|null */
    private $victim = null;

    protected function setUp(): void
    {
        // Linux verifies through /proc; macOS and BSD through ps (and lsof).
        if (!function_exists('posix_kill') || (!is_dir('/proc/self') && !is_executable('/bin/ps'))) {
            $this->markTestSkipped('Requires POSIX signals and /proc or ps');
        }
        $this->previousCwd = getcwd() ?: '.';
        $this->tempDir = sys_get_temp_dir() . '/cpm-monitor-stop-' . uniqid();
        mkdir($this->tempDir . '/.cpm/logs', 0777, true);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->victim)) {
            proc_terminate($this->victim, 9);
            proc_close($this->victim);
        }
        chdir($this->previousCwd);
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    public function test_stop_refuses_to_signal_a_process_that_is_not_a_cpm_monitor(): void
    {
        $this->victim = proc_open(['sleep', '30'], [], $pipes);
        $this->assertIsResource($this->victim);
        $pid = proc_get_status($this->victim)['pid'];
        file_put_contents($this->tempDir . '/.cpm/logs/monitor.pid', (string) $pid);

        $tester = new CommandTester(new MonitorCommand($this->createMock(SessionManager::class)));
        $exitCode = $tester->execute(['--stop' => true, '--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertTrue(proc_get_status($this->victim)['running'], 'Unrelated process was killed');
    }

    public function test_stop_stops_a_real_daemon_command_line_in_this_project(): void
    {
        // Same argv shape and cwd as MonitorCommand::startDaemon() produces:
        // <php> <.../cpm> monitor --continuous --interval=N, cwd = project root.
        // The script is a stand-in that only sleeps, so the test stays hermetic.
        $script = $this->tempDir . '/bin/cpm';
        mkdir(dirname($script));
        file_put_contents($script, "<?php sleep(60);\n");
        $this->victim = proc_open(
            [PHP_BINARY, $script, 'monitor', '--continuous', '--interval=60'],
            [],
            $pipes,
            $this->tempDir
        );
        $this->assertIsResource($this->victim);
        usleep(200000);
        $pid = proc_get_status($this->victim)['pid'];
        file_put_contents($this->tempDir . '/.cpm/logs/monitor.pid', (string) $pid);

        $tester = new CommandTester(new MonitorCommand($this->createMock(SessionManager::class)));
        $exitCode = $tester->execute(['--stop' => true, '--json' => true]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertTrue(json_decode($tester->getDisplay(), true)['data']['stopped']);
        $this->assertFalse(proc_get_status($this->victim)['running'], 'Daemon is still running');
        $this->assertFileDoesNotExist($this->tempDir . '/.cpm/logs/monitor.pid');
    }

    public function test_stop_rejects_negative_pid_without_signalling(): void
    {
        // Only safe to run once the guard exists; otherwise skip rather than
        // risk posix_kill(-1, SIGTERM) against the whole user session.
        if (!class_exists(\ClaudeProjectManager\Services\MonitorPidGuard::class)) {
            $this->fail('MonitorPidGuard missing; refusing to exercise --stop with PID -1');
        }
        file_put_contents($this->tempDir . '/.cpm/logs/monitor.pid', '-1');

        $tester = new CommandTester(new MonitorCommand($this->createMock(SessionManager::class)));
        $exitCode = $tester->execute(['--stop' => true, '--json' => true]);

        $this->assertSame(1, $exitCode);
        $payload = json_decode($tester->getDisplay(), true);
        $this->assertFalse($payload['success']);
    }
}
