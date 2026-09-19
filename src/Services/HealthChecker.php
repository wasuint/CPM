<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Database\SchemaValidator;
use ClaudeProjectManager\Database\SchemaPathResolver;

/**
 * Health check system for CPM
 * Proactively detects issues before they break workflows
 */
class HealthChecker
{
    private DatabaseManager $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Run all health checks and return results
     */
    public function runAllChecks(): array
    {
        return [
            'database_files' => $this->checkDatabaseFiles(),
            'schema_validity' => $this->checkSchemaValidity(),
            'progress_data' => $this->checkProgressData(),
            'inventory_data' => $this->checkInventoryData(),
            'disk_space' => $this->checkDiskSpace(),
            'file_permissions' => $this->checkFilePermissions(),
        ];
    }

    /**
     * Check if all required database files exist
     */
    public function checkDatabaseFiles(): array
    {
        $requiredFiles = ['project', 'inventory', 'progress', 'sessions', 'tasks'];
        $missing = [];

        foreach ($requiredFiles as $file) {
            $path = $this->database->getDatabasePath() . '/' . $file . '.json';
            if (!file_exists($path)) {
                $missing[] = $file;
            }
        }

        return [
            'name' => 'Database Files',
            'status' => empty($missing) ? 'ok' : 'error',
            'message' => empty($missing)
                ? 'All required database files present'
                : 'Missing files: ' . implode(', ', $missing),
            'fix' => empty($missing) ? null : 'Run: cpm init --force'
        ];
    }

    /**
     * Validate progress data against schema
     */
    public function checkSchemaValidity(): array
    {
        try {
            $schemaPath = SchemaPathResolver::fromDatabasePath($this->database->getDatabasePath());
            $validator = new SchemaValidator($schemaPath);
            $progressData = $this->database->read('progress');
            $result = $validator->validateProgressData($progressData);

            return [
                'name' => 'Schema Validation',
                'status' => $result->isValid() ? 'ok' : 'warning',
                'message' => $result->isValid()
                    ? 'Progress data is schema-compliant'
                    : count($result->getErrors()) . ' validation errors found',
                'fix' => $result->isValid() ? null : 'Run: cpm progress repair --auto-fix'
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Schema Validation',
                'status' => 'error',
                'message' => 'Validation failed: ' . $e->getMessage(),
                'fix' => 'Run: cpm progress repair --auto-fix'
            ];
        }
    }

    /**
     * Check progress data integrity
     */
    public function checkProgressData(): array
    {
        try {
            $progressData = $this->database->read('progress');

            $functionCount = count((array)($progressData['by_function'] ?? []));
            $fileCount = count((array)($progressData['by_file'] ?? []));

            $hasStats = isset($progressData['global_stats']);

            if (!$hasStats) {
                return [
                    'name' => 'Progress Data',
                    'status' => 'warning',
                    'message' => 'Missing global_stats',
                    'fix' => 'Run: cpm progress repair --auto-fix'
                ];
            }

            return [
                'name' => 'Progress Data',
                'status' => 'ok',
                'message' => sprintf('%d functions tracked across %d files', $functionCount, $fileCount),
                'fix' => null
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Progress Data',
                'status' => 'error',
                'message' => 'Cannot read progress data: ' . $e->getMessage(),
                'fix' => 'Run: cpm init --force'
            ];
        }
    }

    /**
     * Check inventory data integrity
     */
    public function checkInventoryData(): array
    {
        try {
            $inventory = $this->database->read('inventory');

            $functionCount = count($inventory['functions'] ?? []);
            $classCount = count($inventory['classes'] ?? []);

            if ($functionCount === 0 && $classCount === 0) {
                return [
                    'name' => 'Inventory Data',
                    'status' => 'warning',
                    'message' => 'No functions or classes tracked',
                    'fix' => 'Run: cpm monitor to analyze codebase'
                ];
            }

            return [
                'name' => 'Inventory Data',
                'status' => 'ok',
                'message' => sprintf('%d functions, %d classes tracked', $functionCount, $classCount),
                'fix' => null
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Inventory Data',
                'status' => 'error',
                'message' => 'Cannot read inventory: ' . $e->getMessage(),
                'fix' => 'Run: cpm init --force'
            ];
        }
    }

    /**
     * Check available disk space
     */
    public function checkDiskSpace(): array
    {
        $cpmPath = dirname($this->database->getDatabasePath());
        $freeSpace = disk_free_space($cpmPath);
        $totalSpace = disk_total_space($cpmPath);
        $percentFree = ($freeSpace / $totalSpace) * 100;

        $status = 'ok';
        if ($percentFree < 5) {
            $status = 'error';
        } elseif ($percentFree < 10) {
            $status = 'warning';
        }

        return [
            'name' => 'Disk Space',
            'status' => $status,
            'message' => sprintf(
                '%.1f%% free (%.2f GB / %.2f GB)',
                $percentFree,
                $freeSpace / 1024 / 1024 / 1024,
                $totalSpace / 1024 / 1024 / 1024
            ),
            'fix' => $status === 'ok' ? null : 'Free up disk space or clean old backups'
        ];
    }

    /**
     * Check file permissions on database files
     */
    public function checkFilePermissions(): array
    {
        $dbPath = $this->database->getDatabasePath();
        $issues = [];

        if (!is_readable($dbPath)) {
            $issues[] = 'Database directory not readable';
        }

        if (!is_writable($dbPath)) {
            $issues[] = 'Database directory not writable';
        }

        $files = ['project.json', 'inventory.json', 'progress.json', 'sessions.json', 'tasks.json'];
        foreach ($files as $file) {
            $path = $dbPath . '/' . $file;
            if (file_exists($path) && !is_writable($path)) {
                $issues[] = "$file not writable";
            }
        }

        return [
            'name' => 'File Permissions',
            'status' => empty($issues) ? 'ok' : 'warning',
            'message' => empty($issues)
                ? 'All files have correct permissions'
                : implode(', ', $issues),
            'fix' => empty($issues) ? null : 'Run: chmod -R u+rw ' . $dbPath
        ];
    }

    /**
     * Determine if system is healthy (no errors)
     */
    public function isHealthy(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get summary statistics from checks
     */
    public function getSummary(array $checks): array
    {
        $errorCount = 0;
        $warningCount = 0;
        $okCount = 0;

        foreach ($checks as $check) {
            switch ($check['status']) {
                case 'error':
                    $errorCount++;
                    break;
                case 'warning':
                    $warningCount++;
                    break;
                case 'ok':
                    $okCount++;
                    break;
            }
        }

        return [
            'total_checks' => count($checks),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'ok_count' => $okCount,
        ];
    }
}
