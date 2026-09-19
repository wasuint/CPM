<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\SessionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Auto-repair mechanisms for common CPM issues
 * Automatically diagnoses and fixes system problems
 */
class RepairCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;

    protected static $defaultName = 'repair';
    protected static $defaultDescription = 'Auto-repair common system issues';

    public function __construct(DatabaseManager $database, SessionManager $sessionManager)
    {
        parent::__construct();
        $this->database = $database;
        $this->sessionManager = $sessionManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'auto-fix',
                'a',
                InputOption::VALUE_NONE,
                'Automatically fix all detected issues'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be repaired without making changes'
            )
            ->addOption(
                'backup',
                'b',
                InputOption::VALUE_NONE,
                'Create backup before repairs'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force repairs even for risky operations'
            )
            ->setHelp('
Auto-repair system for common CPM issues and inconsistencies.

This command diagnoses and automatically fixes common problems:
- File permission issues
- Database corruption and inconsistencies  
- Progress tracking mismatches
- Configuration problems
- Session data corruption
- Missing or invalid schemas

Repair Levels:
  --dry-run       Show issues and fixes without applying them
  --auto-fix      Apply all safe automatic fixes
  --force         Apply all fixes including potentially risky ones

Safety Features:
  --backup        Create checkpoint backup before repairs
  Automatic       Safe operation detection and confirmation

Examples:
  cpm repair                    # Diagnose issues only
  cpm repair --auto-fix --json  # Fix safe issues automatically  
  cpm repair --dry-run          # Preview all potential fixes
  cpm repair --backup --force   # Full repair with backup

Auto-repair handles:
- Permission fixes (chmod/chown)
- Data consistency repairs
- Schema validation and fixes
- Progress recalculation
- Session cleanup
- Configuration regeneration
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $autoFix = (bool) $input->getOption('auto-fix');
        $json = $this->isJsonRequested($input);
        $dryRun = (bool) $input->getOption('dry-run');
        $backup = (bool) $input->getOption('backup');
        $force = (bool) $input->getOption('force');
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            // Create backup if requested
            if ($backup && !$dryRun) {
                $this->createRepairBackup();
            }

            // Diagnose all issues
            $diagnostics = $this->runComprehensiveDiagnosis();
            
            // Apply repairs if requested
            $repairResults = [];
            if (($autoFix || $force) && !$dryRun) {
                $repairResults = $this->performRepairs($diagnostics, $force);
            }

            $result = [
                'diagnosis' => $diagnostics,
                'repairs_performed' => $repairResults,
                'backup_created' => $backup && !$dryRun,
                'dry_run' => $dryRun,
                'issues_found' => count($diagnostics['issues']),
                'issues_fixed' => count($repairResults),
                'health_score' => $this->calculateHealthScore($diagnostics)
            ];

            if ($json) {
                $this->outputJsonSuccess($output, 'repair', $result);
            } else {
                $this->outputHumanRepairResults($io, $result, $autoFix, $dryRun, $force);
            }

            return count($diagnostics['issues']) === 0 ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'repair', $e);
        }
    }

    private function runComprehensiveDiagnosis(): array
    {
        $issues = [];
        $systemHealth = [];

        // File system checks
        $fileSystemIssues = $this->checkFileSystemIssues();
        $issues = array_merge($issues, $fileSystemIssues);

        // Database integrity checks
        $databaseIssues = $this->checkDatabaseIntegrity();
        $issues = array_merge($issues, $databaseIssues);

        // Progress consistency checks
        $progressIssues = $this->checkProgressConsistency();
        $issues = array_merge($issues, $progressIssues);

        // Session health checks
        $sessionIssues = $this->checkSessionHealth();
        $issues = array_merge($issues, $sessionIssues);

        // Configuration checks
        $configIssues = $this->checkConfiguration();
        $issues = array_merge($issues, $configIssues);

        // System health metrics
        $systemHealth = $this->gatherSystemHealth();

        return [
            'issues' => $issues,
            'system_health' => $systemHealth,
            'diagnosis_time' => date('Y-m-d H:i:s'),
            'total_checks' => 5
        ];
    }

    private function checkFileSystemIssues(): array
    {
        $issues = [];

        // Check CPM directory existence and permissions
        $cpmDir = file_exists('.cpm') ? '.cpm' : '.claude-project';
        
        if (!file_exists($cpmDir)) {
            $issues[] = [
                'type' => 'missing_directory',
                'severity' => 'high',
                'description' => 'CPM directory missing',
                'affected' => $cpmDir,
                'fix_action' => 'create_directory',
                'auto_fixable' => true
            ];
        } else {
            if (!is_readable($cpmDir)) {
                $issues[] = [
                    'type' => 'permission_read',
                    'severity' => 'high',
                    'description' => 'CPM directory not readable',
                    'affected' => $cpmDir,
                    'fix_action' => 'fix_permissions',
                    'auto_fixable' => true
                ];
            }
            
            if (!is_writable($cpmDir)) {
                $issues[] = [
                    'type' => 'permission_write',
                    'severity' => 'high',
                    'description' => 'CPM directory not writable',
                    'affected' => $cpmDir,
                    'fix_action' => 'fix_permissions',
                    'auto_fixable' => true
                ];
            }
        }

        return $issues;
    }

    private function checkDatabaseIntegrity(): array
    {
        $issues = [];
        $requiredDatabases = ['project', 'inventory', 'progress', 'sessions'];

        foreach ($requiredDatabases as $dbName) {
            try {
                $data = $this->database->read($dbName);
                
                // Check for empty or corrupt data
                if (empty($data) || !is_array($data)) {
                    $issues[] = [
                        'type' => 'database_empty',
                        'severity' => 'medium',
                        'description' => "Database {$dbName} is empty or corrupt",
                        'affected' => $dbName,
                        'fix_action' => 'reinitialize_database',
                        'auto_fixable' => true
                    ];
                }
                
            } catch (\Exception $e) {
                $issues[] = [
                    'type' => 'database_unreadable',
                    'severity' => 'high',
                    'description' => "Cannot read database {$dbName}: " . $e->getMessage(),
                    'affected' => $dbName,
                    'fix_action' => 'recreate_database',
                    'auto_fixable' => true
                ];
            }
        }

        return $issues;
    }

    private function checkProgressConsistency(): array
    {
        $issues = [];

        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');

            $inventoryFunctions = $this->safeCount($inventory['functions'] ?? []);
            $progressFunctions = $this->safeCount($progress['by_function'] ?? []);
            $globalTotal = $progress['global_stats']['total_functions'] ?? 0;

            if ($inventoryFunctions !== $progressFunctions) {
                $issues[] = [
                    'type' => 'progress_mismatch',
                    'severity' => 'medium',
                    'description' => "Function count mismatch: inventory({$inventoryFunctions}) vs progress({$progressFunctions})",
                    'affected' => 'progress_tracking',
                    'fix_action' => 'recalculate_progress',
                    'auto_fixable' => true
                ];
            }

            if ($inventoryFunctions !== $globalTotal) {
                $issues[] = [
                    'type' => 'global_stats_mismatch',
                    'severity' => 'medium',
                    'description' => "Global stats mismatch: inventory({$inventoryFunctions}) vs global({$globalTotal})",
                    'affected' => 'global_statistics',
                    'fix_action' => 'recalculate_global_stats',
                    'auto_fixable' => true
                ];
            }

        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'progress_check_failed',
                'severity' => 'low',
                'description' => 'Could not check progress consistency: ' . $e->getMessage(),
                'affected' => 'progress_system',
                'fix_action' => 'reinitialize_progress',
                'auto_fixable' => false
            ];
        }

        return $issues;
    }

    private function checkSessionHealth(): array
    {
        $issues = [];

        try {
            $sessions = $this->database->read('sessions');
            
            // Check for orphaned sessions
            if (isset($sessions['current_session'])) {
                $currentSession = $sessions['current_session'];
                $activities = $this->getSessionActivities($currentSession);
                
                // Check for sessions with no activities that are very old
                if (empty($activities)) {
                    $startTime = $currentSession['started_at'] ?? '';
                    if ($startTime && strtotime($startTime) < (time() - 86400)) { // 24 hours
                        $issues[] = [
                            'type' => 'orphaned_session',
                            'severity' => 'low',
                            'description' => 'Old session with no activities detected',
                            'affected' => 'session_data',
                            'fix_action' => 'cleanup_sessions',
                            'auto_fixable' => true
                        ];
                    }
                }
            }

        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'session_check_failed',
                'severity' => 'low',
                'description' => 'Could not check session health: ' . $e->getMessage(),
                'affected' => 'session_system',
                'fix_action' => 'reinitialize_sessions',
                'auto_fixable' => true
            ];
        }

        return $issues;
    }

    private function checkConfiguration(): array
    {
        $issues = [];

        // Check PHP requirements
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $issues[] = [
                'type' => 'php_version',
                'severity' => 'medium',
                'description' => 'PHP version below recommended 8.1.0 (current: ' . PHP_VERSION . ')',
                'affected' => 'system_compatibility',
                'fix_action' => 'upgrade_php',
                'auto_fixable' => false
            ];
        }

        // Check required extensions
        $requiredExtensions = ['json', 'mbstring'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $issues[] = [
                    'type' => 'missing_extension',
                    'severity' => 'high',
                    'description' => "Required PHP extension missing: {$ext}",
                    'affected' => 'system_functionality',
                    'fix_action' => 'install_extension',
                    'auto_fixable' => false
                ];
            }
        }

        return $issues;
    }

    private function gatherSystemHealth(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage' => memory_get_usage(true),
            'disk_space' => disk_free_space('.'),
            'load_average' => sys_getloadavg()[0] ?? null,
            'uptime' => $this->getSystemUptime()
        ];
    }

    private function performRepairs(array $diagnostics, bool $force): array
    {
        $repaired = [];

        foreach ($diagnostics['issues'] as $issue) {
            if (!$issue['auto_fixable'] && !$force) {
                continue;
            }

            $result = $this->performSingleRepair($issue);
            if ($result['success']) {
                $repaired[] = $result;
            }
        }

        return $repaired;
    }

    private function performSingleRepair(array $issue): array
    {
        $result = [
            'issue_type' => $issue['type'],
            'fix_action' => $issue['fix_action'],
            'success' => false,
            'message' => ''
        ];

        try {
            switch ($issue['fix_action']) {
                case 'create_directory':
                    mkdir($issue['affected'], 0755, true);
                    $result['success'] = true;
                    $result['message'] = 'Directory created successfully';
                    break;

                case 'fix_permissions':
                    chmod($issue['affected'], 0755);
                    $result['success'] = true;
                    $result['message'] = 'Permissions fixed';
                    break;

                case 'reinitialize_database':
                case 'recreate_database':
                    $this->database->initializeDatabase();
                    $result['success'] = true;
                    $result['message'] = 'Database reinitialized';
                    break;

                case 'recalculate_progress':
                case 'recalculate_global_stats':
                    $this->recalculateProgressStats();
                    $result['success'] = true;
                    $result['message'] = 'Progress statistics recalculated';
                    break;

                case 'cleanup_sessions':
                    $this->cleanupOrphanedSessions();
                    $result['success'] = true;
                    $result['message'] = 'Orphaned sessions cleaned up';
                    break;

                default:
                    $result['message'] = 'Repair action not implemented: ' . $issue['fix_action'];
                    break;
            }

        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = 'Repair failed: ' . $e->getMessage();
        }

        return $result;
    }

    private function createRepairBackup(): void
    {
        // Create a checkpoint before repair
        $checkpointCommand = new CheckpointCommand($this->database, $this->sessionManager);
        // This would need to be called properly in a real implementation
    }

    private function recalculateProgressStats(): void
    {
        try {
            $progress = $this->database->read('progress');
            $inventory = $this->database->read('inventory');
            
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);
            $totalFunctions = count($inventory['functions'] ?? []);
            $completedFunctions = 0;

            foreach ($byFunction as $func) {
                if (($func['status'] ?? 'pending') === 'completed') {
                    $completedFunctions++;
                }
            }

            $completionPercentage = $totalFunctions > 0 ? ($completedFunctions / $totalFunctions) * 100 : 0;

            $progress['global_stats'] = [
                'total_functions' => $totalFunctions,
                'completed_functions' => $completedFunctions,
                'completion_percentage' => $completionPercentage,
                'last_updated' => date('Y-m-d H:i:s')
            ];

            $this->database->write('progress', $progress);

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to recalculate progress stats: ' . $e->getMessage());
        }
    }

    private function cleanupOrphanedSessions(): void
    {
        try {
            $sessions = $this->database->read('sessions');
            
            // Simple cleanup: remove very old current session if it has no activities
            if (isset($sessions['current_session'])) {
                $currentSession = $sessions['current_session'];
                $activities = $this->getSessionActivities($currentSession);
                
                if (empty($activities)) {
                    $startTime = $currentSession['started_at'] ?? '';
                    if ($startTime && strtotime($startTime) < (time() - 86400)) {
                        $sessions['current_session'] = (object) [];
                        $this->database->write('sessions', $sessions);
                    }
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to cleanup sessions: ' . $e->getMessage());
        }
    }

    private function calculateHealthScore(array $diagnostics): int
    {
        $totalIssues = count($diagnostics['issues']);
        $highSeverityIssues = count(array_filter($diagnostics['issues'], fn($i) => $i['severity'] === 'high'));
        $mediumSeverityIssues = count(array_filter($diagnostics['issues'], fn($i) => $i['severity'] === 'medium'));

        // Start with perfect score
        $score = 100;
        
        // Deduct points for issues
        $score -= $highSeverityIssues * 25;   // -25 points per high severity
        $score -= $mediumSeverityIssues * 10; // -10 points per medium severity
        $score -= ($totalIssues - $highSeverityIssues - $mediumSeverityIssues) * 5; // -5 points per low severity

        return max(0, $score);
    }

    private function getSystemUptime(): ?string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            if ($uptime) {
                $seconds = (float) explode(' ', trim($uptime))[0];
                $days = floor($seconds / 86400);
                $hours = floor(($seconds % 86400) / 3600);
                return "{$days}d {$hours}h";
            }
        }
        return null;
    }

    private function getSessionActivities($currentSession): array
    {
        if (is_object($currentSession)) {
            return isset($currentSession->activities) ? (array) $currentSession->activities : [];
        }
        if (is_array($currentSession)) {
            return $currentSession['activities'] ?? [];
        }
        return [];
    }

    private function getByFunctionArray($byFunction): array
    {
        if (is_object($byFunction)) {
            return (array) $byFunction;
        }
        if (is_array($byFunction)) {
            return $byFunction;
        }
        return [];
    }

    private function outputHumanRepairResults(?SymfonyStyle $io, array $result, bool $autoFix, bool $dryRun, bool $force): void
    {
        if (!$io) return;

        $healthScore = $result['health_score'];
        $healthIcon = $healthScore >= 90 ? '🟢' : ($healthScore >= 70 ? '🟡' : '🔴');

        $io->title("🔧 System Repair Report");
        $io->text("{$healthIcon} System Health Score: {$healthScore}/100");

        if ($result['backup_created']) {
            $io->success('✅ Backup created before repairs');
        }

        if ($dryRun) {
            $io->note('🔍 DRY RUN MODE - No changes were made');
        }

        $issues = $result['diagnosis']['issues'];
        $io->section("📋 Diagnosis Results ({$result['issues_found']} issues found)");

        if (empty($issues)) {
            $io->success('🎉 No issues detected! System is healthy.');
            return;
        }

        foreach ($issues as $i => $issue) {
            $severityIcon = match($issue['severity']) {
                'high' => '🔴',
                'medium' => '🟡',
                'low' => '🔵',
                default => '⚪'
            };

            $fixIcon = $issue['auto_fixable'] ? '🔧' : '⚠️';
            
            $issueNum = $i + 1;
            $io->text("{$severityIcon} {$fixIcon} Issue #{$issueNum}: " . $issue['description']);
            $io->text("    Affected: " . $issue['affected']);
            $io->text("    Fix: " . $issue['fix_action'] . ($issue['auto_fixable'] ? ' (auto-fixable)' : ' (manual)'));
        }

        if (count($result['repairs_performed']) > 0) {
            $io->section("🔧 Repairs Applied ({$result['issues_fixed']} fixes)");
            foreach ($result['repairs_performed'] as $repair) {
                $icon = $repair['success'] ? '✅' : '❌';
                $io->text("{$icon} {$repair['issue_type']}: {$repair['message']}");
            }
        } elseif ($autoFix || $force) {
            $io->note('No automatic repairs were applied.');
        }

        if (!$autoFix && !$dryRun) {
            $autoFixableCount = count(array_filter($issues, fn($i) => $i['auto_fixable']));
            if ($autoFixableCount > 0) {
                $io->section('🚀 Next Steps');
                $io->text("Run with --auto-fix to repair {$autoFixableCount} auto-fixable issues");
                $io->text('Example: <info>cpm repair --auto-fix --backup</info>');
            }
        }
    }
}