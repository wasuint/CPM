<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Database;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Handles low-level file operations with safety guarantees
 * Provides file locking, atomic writes, and backup functionality
 */
class FileOperations
{
    private Filesystem $filesystem;
    private int $lockTimeout;
    private string $tempDirectory;

    /**
     * Initializes file operations handler
     *
     * @param int $lockTimeout Maximum time to wait for file locks in seconds
     */
    public function __construct(int $lockTimeout = 30)
    {
        $this->filesystem = new Filesystem();
        $this->lockTimeout = $lockTimeout;
        $this->tempDirectory = sys_get_temp_dir() . '/claude-project-manager';
        
        if (!$this->filesystem->exists($this->tempDirectory)) {
            $this->filesystem->mkdir($this->tempDirectory, 0755);
        }
    }

    /**
     * Safely reads file content with shared locking
     *
     * @param string $filePath Full path to the file to read
     * @return string Raw file content
     * @throws \RuntimeException If file cannot be read or locked
     */
    public function readFile(string $filePath): string
    {
        if (!$this->filesystem->exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        $fileHandle = fopen($filePath, 'r');
        if ($fileHandle === false) {
            throw new \RuntimeException("Cannot open file for reading: {$filePath}");
        }

        try {
            if (!$this->acquireLock($fileHandle, LOCK_SH)) {
                throw new \RuntimeException("Cannot acquire shared lock on file: {$filePath}");
            }

            $content = stream_get_contents($fileHandle);
            if ($content === false) {
                throw new \RuntimeException("Cannot read file content: {$filePath}");
            }

            return $content;
        } finally {
            $this->releaseLock($fileHandle);
            fclose($fileHandle);
        }
    }

    /**
     * Safely writes content to file with atomic operation
     * Creates backup, writes to temporary file, then moves to final location
     *
     * @param string $filePath Full path to the target file
     * @param string $content Content to write to the file
     * @return void
     * @throws \RuntimeException If write operation fails
     */
    public function writeFile(string $filePath, string $content): void
    {
        $this->ensureDirectory(dirname($filePath));
        
        $backupPath = null;
        if ($this->filesystem->exists($filePath)) {
            $backupPath = $this->createBackup($filePath);
        }

        $tempPath = $this->generateTempPath($filePath);
        $tempFiles = [$tempPath];

        try {
            $tempHandle = fopen($tempPath, 'w');
            if ($tempHandle === false) {
                throw new \RuntimeException("Cannot create temporary file: {$tempPath}");
            }

            if (!$this->acquireLock($tempHandle, LOCK_EX)) {
                fclose($tempHandle);
                throw new \RuntimeException("Cannot acquire exclusive lock on temporary file: {$tempPath}");
            }

            $bytesWritten = fwrite($tempHandle, $content);
            if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
                throw new \RuntimeException("Failed to write complete content to temporary file: {$tempPath}");
            }

            fflush($tempHandle);
            $this->releaseLock($tempHandle);
            fclose($tempHandle);

            if (!$this->verifyIntegrity($tempPath, $content)) {
                throw new \RuntimeException("Content verification failed for temporary file: {$tempPath}");
            }

            // Atomic rename - this overwrites the target file atomically
            // No need to unlink first, as rename() is atomic on POSIX systems
            if (!rename($tempPath, $filePath)) {
                throw new \RuntimeException("Failed to atomically move temporary file to target: {$filePath}");
            }

        } catch (\Exception $e) {
            $this->cleanup($tempFiles);
            
            if ($backupPath && $this->filesystem->exists($backupPath)) {
                $this->filesystem->copy($backupPath, $filePath, true);
                $this->filesystem->remove($backupPath);
            }
            
            throw new \RuntimeException("Write operation failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Creates backup copy of a file with timestamp
     *
     * @param string $filePath Path to file to backup
     * @return string Path to the created backup file
     */
    public function createBackup(string $filePath): string
    {
        if (!$this->filesystem->exists($filePath)) {
            throw new \RuntimeException("Cannot backup non-existent file: {$filePath}");
        }

        $backupDir = dirname($filePath) . '/.backups';
        $this->ensureDirectory($backupDir);

        $filename = basename($filePath);
        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = $backupDir . '/' . $filename . '.backup.' . $timestamp;

        $this->filesystem->copy($filePath, $backupPath, true);

        $originalContent = $this->readFile($filePath);
        if (!$this->verifyIntegrity($backupPath, $originalContent)) {
            $this->filesystem->remove($backupPath);
            throw new \RuntimeException("Backup verification failed for: {$filePath}");
        }

        return $backupPath;
    }

    /**
     * Acquires file lock with timeout handling
     *
     * @param resource $fileHandle File handle to lock
     * @param int $lockType Lock type (LOCK_SH or LOCK_EX)
     * @return bool True if lock acquired successfully
     */
    private function acquireLock($fileHandle, int $lockType): bool
    {
        $startTime = time();
        $endTime = $startTime + $this->lockTimeout;
        
        while (time() <= $endTime) {
            if (flock($fileHandle, $lockType | LOCK_NB)) {
                return true;
            }
            
            usleep(100000); // Sleep 100ms between attempts
        }
        
        return false;
    }

    /**
     * Releases file lock safely
     *
     * @param resource $fileHandle File handle to unlock
     * @return void
     */
    private function releaseLock($fileHandle): void
    {
        if (is_resource($fileHandle)) {
            flock($fileHandle, LOCK_UN);
        }
    }

    /**
     * Verifies file integrity using checksum
     *
     * @param string $filePath Path to file to verify
     * @param string $expectedContent Expected file content
     * @return bool True if file content matches expected
     */
    private function verifyIntegrity(string $filePath, string $expectedContent): bool
    {
        if (!$this->filesystem->exists($filePath)) {
            return false;
        }

        $actualContent = file_get_contents($filePath);
        if ($actualContent === false) {
            return false;
        }

        return hash('sha256', $actualContent) === hash('sha256', $expectedContent);
    }

    /**
     * Cleans up temporary files and failed operations
     *
     * @param array $tempFiles List of temporary files to clean up
     * @return void
     */
    private function cleanup(array $tempFiles): void
    {
        foreach ($tempFiles as $tempFile) {
            try {
                if ($this->filesystem->exists($tempFile)) {
                    $this->filesystem->remove($tempFile);
                }
            } catch (\Exception $e) {
                // Ignore cleanup errors but continue with other files
                error_log("Failed to cleanup temporary file {$tempFile}: " . $e->getMessage());
            }
        }
    }

    /**
     * Ensures directory exists and is writable
     *
     * @param string $directoryPath Path to directory
     * @return void
     * @throws \RuntimeException If directory cannot be created or is not writable
     */
    private function ensureDirectory(string $directoryPath): void
    {
        if (!$this->filesystem->exists($directoryPath)) {
            try {
                $this->filesystem->mkdir($directoryPath, 0755);
            } catch (\Exception $e) {
                throw new \RuntimeException("Cannot create directory: {$directoryPath}", 0, $e);
            }
        }

        if (!is_writable($directoryPath)) {
            throw new \RuntimeException("Directory is not writable: {$directoryPath}");
        }
    }

    /**
     * Generates unique temporary filename
     *
     * @param string $originalPath Original file path for reference
     * @return string Unique temporary file path
     */
    private function generateTempPath(string $originalPath): string
    {
        $filename = basename($originalPath);
        $timestamp = microtime(true);
        $random = uniqid();
        
        do {
            $tempPath = $this->tempDirectory . '/' . $filename . '.tmp.' . $timestamp . '.' . $random;
            $random = uniqid(); // Generate new random if file exists
        } while ($this->filesystem->exists($tempPath));
        
        return $tempPath;
    }
}