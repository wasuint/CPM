<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use ClaudeProjectManager\Database\FileOperations;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles concurrent file access with advanced retry and conflict resolution
 */
class ConcurrentFileHandler
{
    private FileOperations $fileOps;
    private LoggerInterface $logger;
    private array $config;
    
    public function __construct(
        FileOperations $fileOps, 
        ?LoggerInterface $logger = null
    ) {
        $this->fileOps = $fileOps;
        $this->logger = $logger ?? new NullLogger();
        $this->config = [
            'max_retries' => 5,
            'retry_delay_ms' => 100,
            'backoff_multiplier' => 2,
            'max_delay_ms' => 5000,
            'stale_lock_timeout' => 30, // seconds
        ];
    }
    
    /**
     * Safely write to a file with retry logic and conflict resolution
     */
    public function safeWrite(string $filePath, string $content, string $context = 'unknown'): bool
    {
        $attempt = 0;
        $delay = $this->config['retry_delay_ms'];
        
        while ($attempt < $this->config['max_retries']) {
            $attempt++;
            
            try {
                // Check for stale locks
                $this->checkStaleLocks($filePath);
                
                // Attempt write
                $this->fileOps->writeFile($filePath, $content);
                
                $this->logger->info("Successfully wrote to file", [
                    'file' => $filePath,
                    'context' => $context,
                    'attempt' => $attempt
                ]);
                
                return true;
                
            } catch (\RuntimeException $e) {
                $this->logger->warning("Write attempt failed", [
                    'file' => $filePath,
                    'context' => $context,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt >= $this->config['max_retries']) {
                    $this->logger->error("All write attempts failed", [
                        'file' => $filePath,
                        'context' => $context,
                        'attempts' => $attempt
                    ]);
                    throw $e;
                }
                
                // Exponential backoff with jitter
                $jitter = rand(0, (int)($delay * 0.1));
                usleep(($delay + $jitter) * 1000);
                
                // Increase delay for next attempt
                $delay = min($delay * $this->config['backoff_multiplier'], $this->config['max_delay_ms']);
            }
        }
        
        return false;
    }
    
    /**
     * Safely read from a file with retry logic
     */
    public function safeRead(string $filePath, string $context = 'unknown'): ?string
    {
        $attempt = 0;
        $delay = $this->config['retry_delay_ms'];
        
        while ($attempt < $this->config['max_retries']) {
            $attempt++;
            
            try {
                $content = $this->fileOps->readFile($filePath);
                
                $this->logger->debug("Successfully read file", [
                    'file' => $filePath,
                    'context' => $context,
                    'attempt' => $attempt
                ]);
                
                return $content;
                
            } catch (\RuntimeException $e) {
                $this->logger->warning("Read attempt failed", [
                    'file' => $filePath,
                    'context' => $context,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt >= $this->config['max_retries']) {
                    $this->logger->error("All read attempts failed", [
                        'file' => $filePath,
                        'context' => $context,
                        'attempts' => $attempt
                    ]);
                    return null;
                }
                
                usleep($delay * 1000);
                $delay = min($delay * $this->config['backoff_multiplier'], $this->config['max_delay_ms']);
            }
        }
        
        return null;
    }
    
    /**
     * Check for and clean up stale lock files
     */
    private function checkStaleLocks(string $filePath): void
    {
        $lockFile = $filePath . '.lock';
        
        if (!file_exists($lockFile)) {
            return;
        }
        
        $lockAge = time() - filemtime($lockFile);
        
        if ($lockAge > $this->config['stale_lock_timeout']) {
            $this->logger->warning("Removing stale lock file", [
                'file' => $lockFile,
                'age_seconds' => $lockAge
            ]);
            
            @unlink($lockFile);
        }
    }
    
    /**
     * Perform an atomic update with merge strategy for conflicts
     */
    public function atomicUpdate(string $filePath, callable $updateFunction, string $context = 'unknown'): bool
    {
        // Hold an exclusive lock on a sidecar file for the WHOLE
        // read-modify-write cycle. Without it two concurrent updates read the
        // same state and the second write silently discards the first one
        // (lost issue entries, duplicate IDs). flock releases automatically
        // if the process dies, so no stale-lock cleanup is needed here.
        $lockHandle = @fopen($filePath . '.txlock', 'c');
        if ($lockHandle !== false && !$this->acquireTransactionLock($lockHandle)) {
            fclose($lockHandle);
            throw new \RuntimeException("Could not acquire transaction lock for {$filePath} within timeout");
        }

        try {
            return $this->doAtomicUpdate($filePath, $updateFunction, $context);
        } finally {
            if ($lockHandle !== false) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    /**
     * Block until the transaction lock is acquired or the timeout expires.
     *
     * @param resource $lockHandle
     */
    private function acquireTransactionLock($lockHandle): bool
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return true;
            }
            usleep(50000);
        }

        return false;
    }

    private function doAtomicUpdate(string $filePath, callable $updateFunction, string $context): bool
    {
        $attempt = 0;
        $delay = $this->config['retry_delay_ms'];

        while ($attempt < $this->config['max_retries']) {
            $attempt++;
            
            try {
                // Read current content
                $currentContent = $this->safeRead($filePath, $context);
                
                if ($currentContent === null && file_exists($filePath)) {
                    // File exists but can't be read - wait and retry
                    usleep($delay * 1000);
                    $delay = min($delay * $this->config['backoff_multiplier'], $this->config['max_delay_ms']);
                    continue;
                }
                
                // Apply update function
                $newContent = $updateFunction($currentContent);
                
                // Write back
                if ($this->safeWrite($filePath, $newContent, $context)) {
                    return true;
                }
                
            } catch (\Exception $e) {
                $this->logger->error("Atomic update failed", [
                    'file' => $filePath,
                    'context' => $context,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt >= $this->config['max_retries']) {
                    throw $e;
                }
            }
            
            usleep($delay * 1000);
            $delay = min($delay * $this->config['backoff_multiplier'], $this->config['max_delay_ms']);
        }
        
        return false;
    }
    
    /**
     * Get information about file access patterns for debugging
     */
    public function getAccessStats(string $filePath): array
    {
        $lockFile = $filePath . '.lock';
        
        return [
            'file_exists' => file_exists($filePath),
            'file_readable' => is_readable($filePath),
            'file_writable' => is_writable($filePath),
            'lock_exists' => file_exists($lockFile),
            'lock_age' => file_exists($lockFile) ? time() - filemtime($lockFile) : null,
            'file_size' => file_exists($filePath) ? filesize($filePath) : null,
            'last_modified' => file_exists($filePath) ? date('c', filemtime($filePath)) : null,
        ];
    }
    
    /**
     * Configure handler settings
     */
    public function configure(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }
}