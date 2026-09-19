<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use ClaudeProjectManager\Services\Logger;
use Psr\Log\LogLevel;

/**
 * Tests for PSR-3 compliant Logger
 * Following TDD: Tests written BEFORE implementation
 */
class LoggerTest extends TestCase
{
    private string $testLogPath;

    protected function setUp(): void
    {
        $this->testLogPath = sys_get_temp_dir() . '/cpm_test_log_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogPath)) {
            unlink($this->testLogPath);
        }

        // Clean up test log directory
        $logDir = dirname($this->testLogPath);
        if (is_dir($logDir) && basename($logDir) !== 'tmp') {
            @rmdir($logDir);
        }
    }

    /** @test */
    public function it_creates_log_directory_if_not_exists(): void
    {
        $logPath = sys_get_temp_dir() . '/cpm_test_' . uniqid() . '/app.log';
        $logger = new Logger($logPath);

        $this->assertDirectoryExists(dirname($logPath));

        // Cleanup
        unlink($logPath);
        rmdir(dirname($logPath));
    }

    /** @test */
    public function it_writes_info_level_messages(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO);
        $logger->info('Test message');

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringContainsString('INFO:', $logContent);
        $this->assertStringContainsString('Test message', $logContent);
    }

    /** @test */
    public function it_writes_error_level_messages(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO);
        $logger->error('Error occurred');

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringContainsString('ERROR:', $logContent);
        $this->assertStringContainsString('Error occurred', $logContent);
    }

    /** @test */
    public function it_includes_context_data_in_logs(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO);
        $logger->info('Operation completed', [
            'file' => 'test.php',
            'function' => 'testFunc'
        ]);

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringContainsString('test.php', $logContent);
        $this->assertStringContainsString('testFunc', $logContent);
    }

    /** @test */
    public function it_respects_minimum_log_level(): void
    {
        // Set minimum level to WARNING
        $logger = new Logger($this->testLogPath, LogLevel::WARNING);

        $logger->debug('Debug message');  // Should NOT be logged
        $logger->info('Info message');    // Should NOT be logged
        $logger->warning('Warning message'); // SHOULD be logged
        $logger->error('Error message');  // SHOULD be logged

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringNotContainsString('Debug message', $logContent);
        $this->assertStringNotContainsString('Info message', $logContent);
        $this->assertStringContainsString('Warning message', $logContent);
        $this->assertStringContainsString('Error message', $logContent);
    }

    /** @test */
    public function it_can_be_disabled(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO, false);
        $logger->info('This should not be logged');

        $this->assertFileDoesNotExist($this->testLogPath);
    }

    /** @test */
    public function it_includes_timestamp_in_logs(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO);
        $logger->info('Test message');

        $logContent = file_get_contents($this->testLogPath);

        // Check for timestamp pattern [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $logContent);
    }

    /** @test */
    public function it_supports_all_psr3_log_levels(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::DEBUG);

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');
        $logger->critical('Critical message');

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringContainsString('DEBUG:', $logContent);
        $this->assertStringContainsString('INFO:', $logContent);
        $this->assertStringContainsString('WARNING:', $logContent);
        $this->assertStringContainsString('ERROR:', $logContent);
        $this->assertStringContainsString('CRITICAL:', $logContent);
    }

    /** @test */
    public function it_appends_to_existing_log_file(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO);

        $logger->info('First message');
        $logger->info('Second message');

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringContainsString('First message', $logContent);
        $this->assertStringContainsString('Second message', $logContent);

        // Check that both messages exist (not overwritten)
        $this->assertGreaterThanOrEqual(2, substr_count($logContent, 'INFO:'));
    }

    /** @test */
    public function it_handles_empty_context_gracefully(): void
    {
        $logger = new Logger($this->testLogPath, LogLevel::INFO);
        $logger->info('Message without context', []);

        $logContent = file_get_contents($this->testLogPath);

        $this->assertStringContainsString('Message without context', $logContent);
        $this->assertStringNotContainsString('{}', $logContent);
    }
}
