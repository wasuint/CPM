<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use ClaudeProjectManager\Services\MonitorPidGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The monitor PID file lives inside the analysed project and can be committed
 * by a hostile repository, so its content must never reach posix_kill() or
 * taskkill unless it names a single, verified CPM monitor of this project.
 */
final class MonitorPidGuardTest extends TestCase
{
    private string $procRoot;
    private string $project;
    private string $otherProject;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cpm-guard-' . uniqid();
        $this->procRoot = $base . '/proc';
        $this->project = $base . '/project';
        $this->otherProject = $base . '/other';
        mkdir($this->procRoot, 0700, true);
        mkdir($this->project, 0700, true);
        mkdir($this->otherProject, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->procRoot)));
    }

    public static function invalidPidProvider(): array
    {
        return [
            'minus one (all processes)' => ['-1'],
            'negative group'            => ['-1234'],
            'zero (own process group)'  => ['0'],
            'init'                      => ['1'],
            'leading zero'              => ['0123'],
            'plus sign'                 => ['+42'],
            'exponent'                  => ['1e3'],
            'hex'                       => ['0x1F'],
            'decimal'                   => ['42.0'],
            'empty'                     => [''],
            'embedded space'            => ['4 2'],
            'shell injection'           => ['42; rm -rf ~'],
            'overflow'                  => ['99999999999999999999999'],
        ];
    }

    #[DataProvider('invalidPidProvider')]
    public function test_parse_rejects_dangerous_or_malformed_pids(string $raw): void
    {
        $this->assertNull(MonitorPidGuard::parse($raw));
    }

    public function test_parse_accepts_plain_positive_pid_with_surrounding_whitespace(): void
    {
        $this->assertSame(4242, MonitorPidGuard::parse(" 4242\n"));
    }

    public static function daemonCmdlineProvider(): array
    {
        return [
            'php + cpm'            => [['/usr/bin/php', '/opt/cpm/bin/cpm', 'monitor', '--continuous', '--interval=30']],
            'php + claude-project' => [['php8.4', 'vendor/bin/claude-project', 'monitor', '--continuous', '--interval=5']],
            'php + cpm.php'        => [['php', '/x/cpm.php', 'monitor', '--continuous']],
            'script as argv0'      => [['/opt/cpm/bin/cpm', 'monitor', '--continuous', '--interval=30']],
        ];
    }

    #[DataProvider('daemonCmdlineProvider')]
    public function test_verifies_the_daemon_command_line_in_this_project(array $argv): void
    {
        $this->fakeProcess(4242, $argv, $this->project);

        $this->assertTrue(MonitorPidGuard::isCpmMonitor(4242, $this->project, $this->procRoot));
    }

    public static function lookalikeCmdlineProvider(): array
    {
        return [
            'shell wrapper'      => [['bash', '-c', 'cd x && cpm monitor --continuous']],
            'watch'              => [['watch', 'cpm', 'monitor', '--continuous']],
            'grep'               => [['grep', '-r', 'cpm', 'monitor']],
            'status, not daemon' => [['php', '/opt/cpm/bin/cpm', 'monitor', '--status']],
            'other subcommand'   => [['php', '/opt/cpm/bin/cpm', 'status', 'monitor', '--continuous']],
            'lookalike basename' => [['php', '/opt/notcpm', 'monitor', '--continuous']],
            'unrelated'          => [['/usr/bin/bash', '-l']],
        ];
    }

    #[DataProvider('lookalikeCmdlineProvider')]
    public function test_refuses_lookalike_command_lines(array $argv): void
    {
        $this->fakeProcess(4243, $argv, $this->project);

        $this->assertFalse(MonitorPidGuard::isCpmMonitor(4243, $this->project, $this->procRoot));
    }

    public function test_cwd_reached_through_a_symlinked_path_still_matches(): void
    {
        // On macOS /var and /tmp are symlinks into /private, so a cwd reported
        // via an alias must be compared after resolving it, like the root.
        $alias = dirname($this->procRoot) . '/alias';
        symlink($this->project, $alias);
        $this->fakeProcess(4246, ['php', '/opt/cpm/bin/cpm', 'monitor', '--continuous'], $alias);

        $this->assertTrue(MonitorPidGuard::isCpmMonitor(4246, $this->project, $this->procRoot));
    }

    public function test_refuses_a_real_monitor_of_another_project(): void
    {
        $this->fakeProcess(4244, ['php', '/opt/cpm/bin/cpm', 'monitor', '--continuous'], $this->otherProject);

        $this->assertFalse(MonitorPidGuard::isCpmMonitor(4244, $this->project, $this->procRoot));
    }

    public function test_refuses_when_cmdline_cannot_be_read(): void
    {
        // A /proc tree without this PID: unverifiable, so "not a monitor".
        $this->assertFalse(MonitorPidGuard::isCpmMonitor(4245, $this->project, $this->procRoot));
    }

    public function test_refuses_invalid_pid_even_if_cmdline_matches(): void
    {
        $this->assertFalse(MonitorPidGuard::isCpmMonitor(1, $this->project, $this->procRoot));
        $this->assertFalse(MonitorPidGuard::isCpmMonitor(-1, $this->project, $this->procRoot));
    }

    public function test_falls_back_to_ps_when_proc_is_unavailable(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_executable('/bin/ps') && !is_executable('/usr/bin/ps')) {
            $this->markTestSkipped('Requires ps');
        }
        $script = $this->project . '/bin/cpm';
        mkdir(dirname($script));
        file_put_contents($script, "<?php sleep(30);\n");
        $proc = proc_open(
            [PHP_BINARY, $script, 'monitor', '--continuous', '--interval=60'],
            [],
            $pipes,
            $this->project
        );
        $this->assertIsResource($proc);
        try {
            usleep(200000);
            $pid = proc_get_status($proc)['pid'];
            // A procRoot that does not exist simulates macOS/BSD.
            $this->assertTrue(MonitorPidGuard::isCpmMonitor($pid, $this->project, $this->procRoot . '/absent'));
            $this->assertFalse(MonitorPidGuard::isCpmMonitor($pid, $this->otherProject, $this->procRoot . '/absent'));
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    }

    private function fakeProcess(int $pid, array $argv, string $cwd): void
    {
        mkdir($this->procRoot . '/' . $pid, 0700);
        file_put_contents($this->procRoot . '/' . $pid . '/cmdline', implode("\0", $argv) . "\0");
        symlink($cwd, $this->procRoot . '/' . $pid . '/cwd');
    }
}
