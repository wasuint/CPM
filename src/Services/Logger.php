<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 compliant logger for CPM
 * Provides simple file-based logging with level filtering
 */
class Logger implements LoggerInterface
{
    private string $logPath;
    private string $minLevel;
    private bool $enabled;

    /**
     * @param string $logPath Path to log file
     * @param string $minLevel Minimum log level (default: INFO)
     * @param bool $enabled Whether logging is enabled (default: true)
     */
    public function __construct(string $logPath, string $minLevel = LogLevel::INFO, bool $enabled = true)
    {
        $this->logPath = $logPath;
        $this->minLevel = $minLevel;
        $this->enabled = $enabled;

        // Ensure log directory exists
        if ($this->enabled && !is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0755, true);
        }
    }

    /**
     * Log a message at any level
     */
    public function log($level, $message, array $context = []): void
    {
        if (!$this->enabled || !$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logLine = sprintf("[%s] %s: %s%s\n", $timestamp, strtoupper($level), $message, $contextStr);

        file_put_contents($this->logPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Check if a log level should be logged based on minimum level
     */
    private function shouldLog(string $level): bool
    {
        $levels = [
            LogLevel::DEBUG => 0,
            LogLevel::INFO => 1,
            LogLevel::NOTICE => 2,
            LogLevel::WARNING => 3,
            LogLevel::ERROR => 4,
            LogLevel::CRITICAL => 5,
            LogLevel::ALERT => 6,
            LogLevel::EMERGENCY => 7,
        ];

        return ($levels[$level] ?? 0) >= ($levels[$this->minLevel] ?? 1);
    }
}
