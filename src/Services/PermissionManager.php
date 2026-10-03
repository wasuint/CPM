<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\Installer;

/**
 * Manages permissions for CPM files and directories
 * Prevents and fixes permission issues that block Claude Code access
 */
class PermissionManager
{
    private ConfigManager $config;
    private string $projectRoot;
    private string $currentUser;
    private ?string $webServerUser = null;
    private array $issues = [];
    private array $recommendations = [];

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->projectRoot = $config->get('paths.project_root');
        $this->currentUser = $this->getCurrentUser();
        $this->webServerUser = $this->detectWebServerUser();
    }

    /**
     * Comprehensive permission check and diagnosis
     */
    public function diagnosePermissions(): array
    {
        $this->issues = [];
        $this->recommendations = [];

        // Critical checks
        $this->checkRootInstallation();
        $this->checkCpmDirectoryPermissions();
        $this->checkLogDirectoryPermissions();
        $this->checkDatabaseFilePermissions();
        $this->checkContextFilePermissions();
        $this->checkBinaryPermissions();
        $this->checkOwnership();

        return [
            'status' => empty($this->issues) ? 'ok' : 'issues_found',
            'issues' => $this->issues,
            'recommendations' => $this->recommendations,
            'current_user' => $this->currentUser,
            'detected_web_user' => $this->webServerUser,
            'cpm_path' => $this->config->get('paths.cpm_root')
        ];
    }

    /**
     * Attempt to fix permissions automatically
     */
    public function fixPermissions(bool $dryRun = false): array
    {
        $fixes = [];
        $errors = [];

        try {
            // Fix directory permissions
            $fixes = array_merge($fixes, $this->fixDirectoryPermissions($dryRun));
            
            // Fix file permissions
            $fixes = array_merge($fixes, $this->fixFilePermissions($dryRun));
            
            // Fix ownership if possible
            $fixes = array_merge($fixes, $this->fixOwnership($dryRun));
            
            // Create missing directories
            $fixes = array_merge($fixes, $this->createMissingDirectories($dryRun));

        } catch (\Exception $e) {
            $errors[] = "Permission fix failed: " . $e->getMessage();
        }

        return [
            'fixes_applied' => $fixes,
            'errors' => $errors,
            'dry_run' => $dryRun
        ];
    }

    /**
     * Check if current installation was done as root
     */
    private function checkRootInstallation(): void
    {
        $cpmPath = $this->config->get('paths.cpm_root');
        
        if (!is_dir($cpmPath)) {
            return; // Not initialized yet
        }

        $owner = $this->getFileOwner($cpmPath);
        
        if ($owner === 'root') {
            $this->issues[] = [
                'type' => 'critical',
                'title' => 'CPM installed as root',
                'description' => 'CPM directory is owned by root, preventing Claude Code access',
                'path' => $cpmPath,
                'current_owner' => $owner,
                'expected_owner' => $this->currentUser
            ];

            $this->recommendations[] = [
                'priority' => 'high',
                'action' => 'Fix ownership to current user',
                'commands' => [
                    'sudo chown -R ' . $this->ownerArg() . ' ' . escapeshellarg($cpmPath),
                    'find ' . escapeshellarg($cpmPath) . ' -type d -exec chmod ' . self::dirMode() . ' {} +',
                    'find ' . escapeshellarg($cpmPath) . ' -type f -exec chmod ' . self::fileMode() . ' {} +'
                ],
                'explanation' => 'Changes ownership from root to current user and restores the installer permissions (no world access)'
            ];
        }
    }

    /**
     * Check CPM main directory permissions
     */
    private function checkCpmDirectoryPermissions(): void
    {
        $cpmPath = $this->config->get('paths.cpm_root');
        
        if (!is_dir($cpmPath)) {
            $this->issues[] = [
                'type' => 'warning',
                'title' => 'CPM directory missing',
                'description' => 'Main CPM directory does not exist',
                'path' => $cpmPath
            ];

            $this->recommendations[] = [
                'priority' => 'medium',
                'action' => 'Create CPM directory',
                'commands' => [
                    'mkdir -p ' . escapeshellarg($cpmPath),
                    'chmod ' . self::dirMode() . ' ' . escapeshellarg($cpmPath)
                ],
                'explanation' => 'Creates the main CPM directory with proper permissions'
            ];
            return;
        }

        if (!is_writable($cpmPath)) {
            $this->issues[] = [
                'type' => 'error',
                'title' => 'CPM directory not writable',
                'description' => 'Cannot write to CPM directory',
                'path' => $cpmPath,
                'current_permissions' => substr(sprintf('%o', fileperms($cpmPath)), -3)
            ];

            $this->recommendations[] = [
                'priority' => 'high',
                'action' => 'Fix CPM directory permissions',
                'commands' => [
                    'chmod ' . self::dirMode() . ' ' . escapeshellarg($cpmPath),
                    'chown ' . $this->ownerArg() . ' ' . escapeshellarg($cpmPath)
                ],
                'explanation' => 'Makes CPM directory writable for the current user'
            ];
        }
    }

    /**
     * Check logs directory - critical for daemon operation
     */
    private function checkLogDirectoryPermissions(): void
    {
        $logsPath = $this->config->get('paths.logs');
        
        if (!is_dir($logsPath)) {
            $this->issues[] = [
                'type' => 'error',
                'title' => 'Logs directory missing',
                'description' => 'Logs directory required for daemon operation',
                'path' => $logsPath
            ];

            $this->recommendations[] = [
                'priority' => 'high',
                'action' => 'Create logs directory',
                'commands' => [
                    'mkdir -p ' . escapeshellarg($logsPath),
                    'chmod ' . self::dirMode() . ' ' . escapeshellarg($logsPath),
                    'chown ' . $this->ownerArg() . ' ' . escapeshellarg($logsPath)
                ],
                'explanation' => 'Creates logs directory with write permissions for daemon'
            ];
            return;
        }

        if (!is_writable($logsPath)) {
            $this->issues[] = [
                'type' => 'error',
                'title' => 'Logs directory not writable',
                'description' => 'Daemon cannot write log files or PID files',
                'path' => $logsPath,
                'current_permissions' => substr(sprintf('%o', fileperms($logsPath)), -3)
            ];

            $this->recommendations[] = [
                'priority' => 'high',
                'action' => 'Fix logs directory permissions',
                'commands' => [
                    'chmod ' . self::dirMode() . ' ' . escapeshellarg($logsPath),
                    'chown ' . $this->ownerArg() . ' ' . escapeshellarg($logsPath)
                ],
                'explanation' => 'Ensures daemon can write log and PID files'
            ];
        }
    }

    /**
     * Check database files permissions
     */
    private function checkDatabaseFilePermissions(): void
    {
        $dbPath = $this->config->get('paths.database');
        
        if (!is_dir($dbPath)) {
            return; // Will be created on first run
        }

        $dbFiles = ['project.json', 'progress.json', 'inventory.json', 'sessions.json', 'tasks.json'];
        
        foreach ($dbFiles as $file) {
            $filePath = $dbPath . '/' . $file;
            
            if (file_exists($filePath) && !is_writable($filePath)) {
                $this->issues[] = [
                    'type' => 'error',
                    'title' => 'Database file not writable',
                    'description' => "Cannot update database file: {$file}",
                    'path' => $filePath,
                    'current_permissions' => substr(sprintf('%o', fileperms($filePath)), -3)
                ];

                $this->recommendations[] = [
                    'priority' => 'high',
                    'action' => 'Fix database file permissions',
                    'commands' => [
                        'chmod ' . self::fileMode() . ' ' . escapeshellarg($filePath),
                        'chown ' . $this->ownerArg() . ' ' . escapeshellarg($filePath)
                    ],
                    'explanation' => 'Ensures CPM can update database files'
                ];
            }
        }
    }

    /**
     * Check context files permissions (DIGEST.md, STATE.json)
     */
    private function checkContextFilePermissions(): void
    {
        $contextPath = $this->config->get('paths.context');
        
        if (!is_dir($contextPath)) {
            return; // Will be created on first context:digest
        }

        $contextFiles = ['DIGEST.md', 'STATE.json'];
        
        foreach ($contextFiles as $file) {
            $filePath = $contextPath . '/' . $file;
            
            if (file_exists($filePath)) {
                if (!is_readable($filePath)) {
                    $this->issues[] = [
                        'type' => 'error',
                        'title' => 'Context file not readable',
                        'description' => "Claude Code cannot read: {$file}",
                        'path' => $filePath,
                        'current_permissions' => substr(sprintf('%o', fileperms($filePath)), -3)
                    ];

                    $this->recommendations[] = [
                        'priority' => 'high',
                        'action' => 'Fix context file permissions',
                        'commands' => [
                            'chmod ' . self::fileMode() . ' ' . escapeshellarg($filePath),
                            'chown ' . $this->ownerArg() . ' ' . escapeshellarg($filePath)
                        ],
                        'explanation' => 'Ensures Claude Code can read context files'
                    ];
                }
            }
        }
    }

    /**
     * Check binary permissions (claude-project executable)
     */
    private function checkBinaryPermissions(): void
    {
        $possibleBinaries = [
            $this->projectRoot . '/claude-project',
            $this->projectRoot . '/bin/claude-project',
            $this->projectRoot . '/vendor/bin/claude-project',
            '/opt/cpm/bin/claude-project'
        ];

        foreach ($possibleBinaries as $binary) {
            if (file_exists($binary)) {
                if (!is_executable($binary)) {
                    $this->issues[] = [
                        'type' => 'error',
                        'title' => 'Binary not executable',
                        'description' => 'CPM binary cannot be executed',
                        'path' => $binary,
                        'current_permissions' => substr(sprintf('%o', fileperms($binary)), -3)
                    ];

                    $this->recommendations[] = [
                        'priority' => 'medium',
                        'action' => 'Make binary executable',
                        'commands' => [
                            'chmod +x ' . escapeshellarg($binary)
                        ],
                        'explanation' => 'Makes CPM binary executable'
                    ];
                }
                break; // Only check the first found binary
            }
        }
    }

    /**
     * Check overall ownership
     */
    private function checkOwnership(): void
    {
        $cpmPath = $this->config->get('paths.cpm_root');
        
        if (!is_dir($cpmPath)) {
            return;
        }

        $owner = $this->getFileOwner($cpmPath);
        
        if ($owner !== $this->currentUser && $owner !== 'unknown') {
            $this->issues[] = [
                'type' => 'warning',
                'title' => 'Ownership mismatch',
                'description' => "CPM directory owned by {$owner}, current user is {$this->currentUser}",
                'path' => $cpmPath,
                'current_owner' => $owner,
                'expected_owner' => $this->currentUser
            ];

            if ($owner === 'root') {
                $this->recommendations[] = [
                    'priority' => 'high',
                    'action' => 'Fix root ownership',
                    'commands' => [
                        'sudo chown -R ' . $this->ownerArg() . ' ' . escapeshellarg($cpmPath)
                    ],
                    'explanation' => 'Changes ownership from root to current user'
                ];
            }
        }
    }

    /**
     * Fix directory permissions: tighten to the installer's mode (no world access)
     */
    private function fixDirectoryPermissions(bool $dryRun): array
    {
        $fixes = [];
        $directories = [
            $this->config->get('paths.cpm_root'),
            $this->config->get('paths.database'),
            $this->config->get('paths.context'),
            $this->config->get('paths.logs'),
            $this->config->get('paths.config')
        ];
        $permission = self::dirMode();

        foreach ($directories as $dir) {
            // Never chmod through a symlink planted in the project
            if (is_dir($dir) && !is_link($dir)) {
                $currentPerms = substr(sprintf('%o', fileperms($dir)), -3);
                if ($currentPerms !== $permission) {
                    if (!$dryRun) {
                        chmod($dir, Installer::CPM_DIR_MODE);
                    }
                    $fixes[] = "Set {$dir} permissions to {$permission}";
                }
            }
        }

        return $fixes;
    }

    /**
     * Fix file permissions: tighten every regular file under .cpm/ to the
     * installer's mode (no world access)
     */
    private function fixFilePermissions(bool $dryRun): array
    {
        $fixes = [];
        $cpmPath = $this->config->get('paths.cpm_root');
        if (!is_dir($cpmPath) || is_link($cpmPath)) {
            return $fixes;
        }
        $permission = self::fileMode();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cpmPath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $currentPerms = substr(sprintf('%o', fileperms($path)), -3);
            if ($currentPerms !== $permission) {
                if (!$dryRun) {
                    chmod($path, Installer::CPM_FILE_MODE);
                }
                $fixes[] = "Set {$path} permissions to {$permission}";
            }
        }

        return $fixes;
    }

    /**
     * Fix ownership if possible
     */
    private function fixOwnership(bool $dryRun): array
    {
        $fixes = [];
        $cpmPath = $this->config->get('paths.cpm_root');
        
        if (is_dir($cpmPath)) {
            $owner = $this->getFileOwner($cpmPath);
            
            if ($owner === 'root' && !$dryRun) {
                // Can't fix root ownership without sudo
                $fixes[] = "Root ownership detected - manual fix required";
            } elseif ($owner !== $this->currentUser && !$dryRun) {
                try {
                    // Try to change ownership (may fail without proper permissions)
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cpmPath)
                    );
                    
                    foreach ($iterator as $file) {
                        if (@chown($file->getPathname(), $this->currentUser)) {
                            $fixes[] = "Changed ownership of {$file->getPathname()}";
                        }
                    }
                } catch (\Exception $e) {
                    $fixes[] = "Ownership fix failed: " . $e->getMessage();
                }
            }
        }

        return $fixes;
    }

    /**
     * Create missing directories
     */
    private function createMissingDirectories(bool $dryRun): array
    {
        $fixes = [];
        $directories = [
            $this->config->get('paths.cpm_root'),
            $this->config->get('paths.database'),
            $this->config->get('paths.context'),
            $this->config->get('paths.logs'),
            $this->config->get('paths.config')
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (!$dryRun) {
                    mkdir($dir, Installer::CPM_DIR_MODE, true);
                    @chmod($dir, Installer::CPM_DIR_MODE);
                    @chown($dir, $this->currentUser);
                }
                $fixes[] = "Created directory: {$dir}";
            }
        }

        return $fixes;
    }

    /**
     * Installer directory mode as an octal string for messages and commands
     */
    private static function dirMode(): string
    {
        return sprintf('%o', Installer::CPM_DIR_MODE);
    }

    /**
     * Installer file mode as an octal string for messages and commands
     */
    private static function fileMode(): string
    {
        return sprintf('%o', Installer::CPM_FILE_MODE);
    }

    /**
     * Shell-quoted "user:user" argument for suggested chown commands
     */
    private function ownerArg(): string
    {
        return escapeshellarg($this->currentUser . ':' . $this->currentUser);
    }

    /**
     * Get current system user
     *
     * Windows note: posix_* functions don't exist on Windows PHP.
     * Falls back to USERNAME/USER environment variables.
     */
    private function getCurrentUser(): string
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return getenv('USERNAME') ?: (getenv('USER') ?: 'unknown');
        }
        return posix_getpwuid(posix_geteuid())['name'] ?? 'unknown';
    }

    /**
     * Detect web server user (www-data, apache, nginx, etc.)
     *
     * Windows note: no Unix user database, always returns null.
     */
    private function detectWebServerUser(): ?string
    {
        if (!function_exists('posix_getpwnam')) {
            return null;
        }

        $commonWebUsers = ['www-data', 'apache', 'nginx', 'httpd', 'web'];

        foreach ($commonWebUsers as $user) {
            if (posix_getpwnam($user)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Get file owner name
     *
     * Windows note: posix_getpwuid unavailable; reports numeric uid as string.
     */
    private function getFileOwner(string $path): string
    {
        if (!file_exists($path)) {
            return 'unknown';
        }

        $uid = fileowner($path);

        if (!function_exists('posix_getpwuid')) {
            return $uid !== false ? (string)$uid : 'unknown';
        }

        $userInfo = posix_getpwuid($uid);

        return $userInfo ? $userInfo['name'] : 'unknown';
    }

    /**
     * Generate human-readable permission report
     */
    public function generateReport(): string
    {
        $diagnosis = $this->diagnosePermissions();
        
        $report = "# CPM Permission Report\n\n";
        $report .= "**Generated**: " . date('Y-m-d H:i:s') . "\n";
        $report .= "**Current User**: {$diagnosis['current_user']}\n";
        $report .= "**Web Server User**: " . ($diagnosis['detected_web_user'] ?? 'Not detected') . "\n";
        $report .= "**CPM Path**: {$diagnosis['cpm_path']}\n\n";
        
        if ($diagnosis['status'] === 'ok') {
            $report .= "✅ **Status**: All permissions are correct!\n\n";
        } else {
            $report .= "❌ **Status**: Issues found\n\n";
            
            $report .= "## Issues Found\n\n";
            foreach ($diagnosis['issues'] as $issue) {
                $report .= "### " . $issue['title'] . "\n";
                $report .= "- **Type**: " . ucfirst($issue['type']) . "\n";
                $report .= "- **Description**: " . $issue['description'] . "\n";
                $report .= "- **Path**: `" . $issue['path'] . "`\n";
                if (isset($issue['current_permissions'])) {
                    $report .= "- **Current Permissions**: " . $issue['current_permissions'] . "\n";
                }
                if (isset($issue['current_owner'])) {
                    $report .= "- **Current Owner**: " . $issue['current_owner'] . "\n";
                }
                $report .= "\n";
            }
            
            $report .= "## Recommended Fixes\n\n";
            foreach ($diagnosis['recommendations'] as $rec) {
                $report .= "### " . $rec['action'] . "\n";
                $report .= "**Priority**: " . ucfirst($rec['priority']) . "\n\n";
                $report .= "**Commands**:\n";
                foreach ($rec['commands'] as $cmd) {
                    $report .= "```bash\n{$cmd}\n```\n";
                }
                $report .= "**Explanation**: " . $rec['explanation'] . "\n\n";
            }
        }
        
        return $report;
    }
}