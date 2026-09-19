<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use ClaudeProjectManager\Commands\MonitorCommand;
use ClaudeProjectManager\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression tests for the monitor daemon-control JSON paths, which used to
 * fatal with "Argument #1 ($io) must be of type SymfonyStyle, null given"
 * because createStyleIfNeeded() returns null in --json mode.
 */
final class MonitorCommandDaemonJsonTest extends TestCase
{
    private string $previousCwd;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->previousCwd = getcwd() ?: '.';
        $this->tempDir = sys_get_temp_dir() . '/cpm-monitor-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        @unlink($this->tempDir . '/.cpm/logs/monitor.pid');
        @rmdir($this->tempDir . '/.cpm/logs');
        @rmdir($this->tempDir . '/.cpm');
        @rmdir($this->tempDir);
    }

    private function makeTester(): CommandTester
    {
        $sessionManager = $this->createMock(SessionManager::class);

        return new CommandTester(new MonitorCommand($sessionManager));
    }

    public function test_status_json_reports_not_running_without_fatal(): void
    {
        $tester = $this->makeTester();
        $exitCode = $tester->execute(['--status' => true, '--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame('monitor.status', $payload['action']);
        $this->assertFalse($payload['data']['running']);
    }

    public function test_stop_json_reports_no_daemon_without_fatal(): void
    {
        $tester = $this->makeTester();
        $exitCode = $tester->execute(['--stop' => true, '--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame('monitor.stop', $payload['action']);
        $this->assertFalse($payload['data']['stopped']);
    }

    public function test_status_json_reports_running_daemon_with_pid(): void
    {
        mkdir($this->tempDir . '/.cpm/logs', 0777, true);
        file_put_contents($this->tempDir . '/.cpm/logs/monitor.pid', (string) getmypid());

        $tester = $this->makeTester();
        $exitCode = $tester->execute(['--status' => true, '--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['running']);
        $this->assertSame(getmypid(), $payload['data']['pid']);
    }
}
