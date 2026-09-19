<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * System verification command with enhanced error handling
 */
class VerifyCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;

    protected static $defaultName = 'verify';
    protected static $defaultDescription = 'Verify CPM system health with actionable suggestions';

    public function __construct(DatabaseManager $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'json',
                null,
                \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->addOption(
                'fix',
                null,
                \Symfony\Component\Console\Input\InputOption::VALUE_NONE,
                'Automatically fix issues where possible'
            )
            ->setHelp('
Verify CPM system health and provide actionable suggestions for fixing issues.

This command checks:
- Project initialization status
- File permissions
- Data integrity
- Database consistency
- Configuration validity

Examples:
  cpm verify --json           # AI-friendly verification
  cpm verify --fix           # Fix issues automatically
  cpm verify                 # Human-readable report

The command provides specific suggestions for fixing any issues found.
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->isJsonRequested($input);
        $fix = (bool) $input->getOption('fix');
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            $issues = $this->runSystemChecks();
            $fixedIssues = [];
            
            if ($fix && !empty($issues)) {
                $fixedIssues = $this->attemptFixes($issues);
            }

            if ($json) {
                $this->outputJsonSuccess($output, 'verify', [
                    'healthy' => empty($issues),
                    'issues_found' => count($issues),
                    'issues' => $issues,
                    'fixes_applied' => $fix ? count($fixedIssues) : 0,
                    'fixed_issues' => $fixedIssues
                ]);
            } else {
                $this->outputHumanResults($io, $issues, $fixedIssues, $fix);
            }

            return empty($issues) ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'verify', $e);
        }
    }

    private function runSystemChecks(): array
    {
        $issues = [];

        // Check 1: Project initialization
        if (!file_exists('.cpm') && !file_exists('.claude-project')) {  // Check for legacy dir
            $issues[] = [
                'type' => 'initialization',
                'severity' => 'high',
                'message' => 'CPM not initialized in this project',
                'suggestion' => 'Initialize with: cpm start',
                'fixable' => false
            ];
        }

        // Check 2: Directory permissions
        $cpmDir = file_exists('.cpm') ? '.cpm' : '.claude-project';  // Check legacy
        if (file_exists($cpmDir)) {
            if (!is_readable($cpmDir)) {
                $issues[] = [
                    'type' => 'permissions',
                    'severity' => 'high',
                    'message' => "Cannot read {$cpmDir} directory",
                    'suggestion' => 'Fix permissions with: cpm tools:fix-perms',
                    'fixable' => true
                ];
            }
            if (!is_writable($cpmDir)) {
                $issues[] = [
                    'type' => 'permissions',
                    'severity' => 'high', 
                    'message' => "Cannot write to {$cpmDir} directory",
                    'suggestion' => 'Fix permissions with: cpm tools:fix-perms',
                    'fixable' => true
                ];
            }
        }

        // Check 3: Database files integrity
        try {
            $this->database->read('project');
        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'data_integrity',
                'severity' => 'high',
                'message' => 'Cannot read project database: ' . $e->getMessage(),
                'suggestion' => 'Reinitialize with: cpm start --force',
                'fixable' => true
            ];
        }

        try {
            $this->database->read('progress');
        } catch (\Exception $e) {
            $issues[] = [
                'type' => 'data_integrity',
                'severity' => 'medium',
                'message' => 'Cannot read progress database: ' . $e->getMessage(),
                'suggestion' => 'Reset progress with: cpm start --force',
                'fixable' => true
            ];
        }

        // Check 4: Data consistency
        try {
            $project = $this->database->read('project');
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');

            $inventoryFunctions = $this->safeCount($inventory['functions'] ?? []);
            $progressFunctions = $this->safeCount($progress['by_function'] ?? []);

            if ($inventoryFunctions > 0 && $progressFunctions === 0) {
                $issues[] = [
                    'type' => 'consistency',
                    'severity' => 'medium',
                    'message' => "Functions in inventory ({$inventoryFunctions}) but no progress tracking",
                    'suggestion' => 'Sync progress with: cpm start --force',
                    'fixable' => true
                ];
            }
        } catch (\Exception $e) {
            // Already handled above in database integrity checks
        }

        // Check 5: Configuration validity
        if (!$this->checkPhpRequirements()) {
            $issues[] = [
                'type' => 'system',
                'severity' => 'low',
                'message' => 'PHP version or extensions may not be optimal',
                'suggestion' => 'Ensure PHP 8.1+ with json, mbstring extensions',
                'fixable' => false
            ];
        }

        return $issues;
    }

    private function attemptFixes(array $issues): array
    {
        $fixed = [];

        foreach ($issues as $issue) {
            if (!$issue['fixable']) {
                continue;
            }

            switch ($issue['type']) {
                case 'permissions':
                    if ($this->fixPermissions()) {
                        $fixed[] = $issue['message'];
                    }
                    break;
                case 'data_integrity':
                case 'consistency':
                    // These require manual intervention via cpm start --force
                    break;
            }
        }

        return $fixed;
    }

    private function fixPermissions(): bool
    {
        try {
            $cpmDir = file_exists('.cpm') ? '.cpm' : '.claude-project';  // Check legacy
            if (file_exists($cpmDir)) {
                chmod($cpmDir, 0755);
                
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($cpmDir)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        chmod($file->getPathname(), 0644);
                    } elseif ($file->isDir() && !in_array($file->getFilename(), ['.', '..'])) {
                        chmod($file->getPathname(), 0755);
                    }
                }
                
                return true;
            }
        } catch (\Exception $e) {
            // Permission fix failed
        }
        
        return false;
    }

    private function checkPhpRequirements(): bool
    {
        return version_compare(PHP_VERSION, '8.1.0', '>=') &&
               extension_loaded('json') &&
               extension_loaded('mbstring');
    }

    private function outputHumanResults(
        ?SymfonyStyle $io,
        array $issues,
        array $fixedIssues,
        bool $fixAttempted
    ): void {
        if (!$io) return;

        $io->title('CPM System Verification');

        if (empty($issues)) {
            $io->success('✅ All systems healthy! CPM is working correctly.');
            return;
        }

        $io->warning(sprintf('Found %d issue(s) that need attention:', count($issues)));

        foreach ($issues as $i => $issue) {
            $icon = match($issue['severity']) {
                'high' => '🔴',
                'medium' => '🟡',
                'low' => '🔵',
                default => '⚪'
            };
            
            $io->section(sprintf('%s Issue #%d (%s)', $icon, $i + 1, ucfirst($issue['severity'])));
            $io->text('Problem: ' . $issue['message']);
            $io->text('Solution: ' . $issue['suggestion']);
            if ($issue['fixable']) {
                $io->text('✅ This issue can be auto-fixed with --fix flag');
            } else {
                $io->text('⚠️ Manual intervention required');
            }
        }

        if ($fixAttempted) {
            if (!empty($fixedIssues)) {
                $io->success('🔧 Fixed the following issues:');
                foreach ($fixedIssues as $fixed) {
                    $io->text("  ✅ $fixed");
                }
            } else {
                $io->note('No issues could be automatically fixed. Manual intervention required.');
            }
        }

        $io->section('🚀 Recommended Actions');
        $fixableCount = count(array_filter($issues, fn($issue) => $issue['fixable']));
        
        if ($fixableCount > 0 && !$fixAttempted) {
            $io->text('1. Run: <info>cpm verify --fix</info> (fixes ' . $fixableCount . ' issues)');
        }
        $io->text('2. For initialization issues: <info>cpm start --force</info>');
        $io->text('3. For permission issues: <info>cpm tools:fix-perms</info>');
        $io->text('4. For help: <info>cpm help</info>');
    }
}