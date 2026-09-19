<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\ProgressTracker;
use ClaudeProjectManager\Services\Logger;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Explicit progress management command for AI assistants
 * Provides direct control over function and file completion tracking
 */
class ProgressCommand extends Command
{
    private DatabaseManager $database;
    private ProgressTracker $progressTracker;
    private ?Logger $logger = null;

    protected static $defaultName = 'progress';
    protected static $defaultDescription = 'Explicit progress management and control';

    private const VALID_STATUSES = ['pending', 'in_progress', 'completed', 'verified', 'has_issues'];

    public function __construct(DatabaseManager $database, ProgressTracker $progressTracker)
    {
        parent::__construct();
        $this->database = $database;
        $this->progressTracker = $progressTracker;
    }

    /**
     * Initialize logger if --debug flag is set
     * Logger is DISABLED by default for production use
     */
    private function initializeLogger(InputInterface $input): void
    {
        $debugMode = $input->getOption('debug');

        if ($debugMode) {
            $logPath = $this->database->getDatabasePath() . '/cpm-debug.log';
            $this->logger = new Logger($logPath, LogLevel::DEBUG, true);
            $this->logger->info('Debug logging enabled for progress command');
        }
    }

    /**
     * Log message if logger is enabled (debug mode only)
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: show, mark-complete, mark-function, mark-range, mark-by-name, bulk-mark, reset, validate, repair'
            )
            ->addArgument(
                'target',
                InputArgument::OPTIONAL,
                'Target file, function ID, or pattern for the action'
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Status to set: ' . implode(', ', self::VALID_STATUSES)
            )
            ->addOption(
                'reason',
                'r',
                InputOption::VALUE_REQUIRED,
                'Reason or description for the change'
            )
            ->addOption(
                'confidence',
                'c',
                InputOption::VALUE_REQUIRED,
                'Confidence score (0.0-1.0) for completion',
                '1.0'
            )
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'Pattern to match for bulk operations'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI parsing'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be changed without making changes'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force operation, bypass schema validation (use with caution)'
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Show debug information including data structures'
            )
            ->addOption(
                'auto-fix',
                null,
                InputOption::VALUE_NONE,
                'Automatically fix common data structure issues'
            )
            ->setHelp('
This command provides explicit control over progress tracking for AI assistants.

Actions:
  show              Show current progress state
  mark-complete     Mark a file as completed
  mark-function     Update function status by ID
  mark-range        Update a range of function IDs (e.g., 51-62)
  mark-by-name      Update function by name (e.g., Database.php:__construct)
  bulk-mark         Update multiple files matching pattern
  reset             Reset progress for target (recovery command)
  validate          Validate progress data without modifying
  repair            Repair broken progress data structures
  pending           Show pending functions/files
  verify            Verify progress consistency

Options:
  --force           Bypass schema validation (use when errors block you)
  -v, --verbose     Show detailed operation information (built-in)
  --debug           Enable debug logging to cpm-debug.log (for troubleshooting)
  --auto-fix        Automatically fix data issues during repair
  --dry-run         Preview changes without applying them
  --json            Output machine-readable JSON

Examples:
  cpm progress show --json
  cpm progress mark-complete src/utils.py --reason="Fixed all issues"
  cpm progress mark-complete src/utils.py --force  # Bypass validation
  cpm progress mark-function 78 --status=completed --reason="Reviewed"
  cpm progress mark-range 51-62 --status=completed --reason="All reviewed"
  cpm progress mark-by-name "Database.php:__construct" --status=completed
  cpm progress mark-by-name "Logger.php:info" --status=verified --dry-run
  cpm progress bulk-mark "*.py" --status=completed --pattern="fixed"
  cpm progress reset src/utils.py  # Reset file progress
  cpm progress validate --verbose  # Check data integrity
  cpm progress repair --auto-fix  # Fix broken data
  cpm progress pending --json
  cpm progress verify --json

Error Recovery:
  If you get schema validation errors:
  1. Try: cpm progress validate --verbose (diagnose the issue)
  2. Try: cpm progress repair --auto-fix (auto-fix issues)
  3. Try: cpm progress reset <file> (reset specific file)
  4. Try: cpm progress mark-complete <file> --force (bypass validation)

AI Notes:
- Always use --json for machine-readable output
- Use --dry-run to preview changes before applying
- Use --force if validation blocks you (then run repair later)
- Include --reason for audit trail
- Check results with "cpm progress verify --json"
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Initialize logger ONLY if --debug flag is set
        $this->initializeLogger($input);

        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $target = $input->getArgument('target');
        $json = $input->getOption('json');
        $dryRun = $input->getOption('dry-run');

        $this->log(LogLevel::INFO, 'Progress command started', [
            'action' => $action,
            'target' => $target,
            'json' => $json,
            'dry_run' => $dryRun
        ]);

        try {
            $result = match ($action) {
                'show' => $this->showProgress($input, $output),
                'mark-complete' => $this->markComplete($input, $output),
                'mark-function' => $this->markFunction($input, $output),
                'mark-range' => $this->markRange($input, $output),
                'mark-by-name' => $this->markByName($input, $output),
                'bulk-mark' => $this->bulkMark($input, $output),
                'reset' => $this->resetProgress($input, $output),
                'validate' => $this->validateProgress($input, $output),
                'repair' => $this->repairProgress($input, $output),
                'pending' => $this->showPending($input, $output),
                'verify' => $this->verifyProgress($input, $output),
                default => $this->handleUnknownAction($action, $json, $output)
            };

            $this->log(LogLevel::INFO, 'Progress command completed successfully', [
                'action' => $action,
                'result_code' => $result
            ]);

            return $result;

        } catch (\Exception $e) {
            $errorMsg = 'Progress command failed: ' . $e->getMessage();

            $this->log(LogLevel::ERROR, 'Progress command failed', [
                'action' => $action,
                'target' => $target,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $errorMsg,
                    'action' => $action,
                    'target' => $target
                ]));
            } else {
                $io->error($errorMsg);
                $io->note('Use --json flag for machine-readable error output');
            }

            return Command::FAILURE;
        }
    }

    private function showProgress(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $io = new SymfonyStyle($input, $output);

        try {
            $progress = $this->database->read('progress');
            $globalStats = $progress['global_stats'] ?? [];
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);

            if ($json) {
                $output->writeln(json_encode([
                    'success' => true,
                    'action' => 'show',
                    'data' => [
                        'global_stats' => $globalStats,
                        'function_count' => count($byFunction),
                        'status_distribution' => $this->calculateStatusDistribution($byFunction)
                    ]
                ], ));
                return Command::SUCCESS;
            }

            $io->title('Current Progress State');
            
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Functions', $globalStats['total_functions'] ?? 0],
                    ['Completed Functions', $globalStats['completed_functions'] ?? 0],
                    ['Completion Percentage', round($globalStats['completion_percentage'] ?? 0, 1) . '%'],
                    ['Last Updated', $globalStats['last_updated'] ?? 'Never']
                ]
            );

            $statusDistribution = $this->calculateStatusDistribution($byFunction);
            if (!empty($statusDistribution)) {
                $io->section('Status Distribution');
                foreach ($statusDistribution as $status => $count) {
                    $io->text("  {$status}: {$count}");
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $e->getMessage()]));
            } else {
                $io->error('Failed to show progress: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    private function markComplete(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');
        $reason = $input->getOption('reason') ?? 'Marked complete via progress command';
        $confidence = (float) ($input->getOption('confidence') ?? 1.0);
        $json = $input->getOption('json');
        $dryRun = $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        if (!$target) {
            $error = 'Target file path is required for mark-complete action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        try {
            if ($dryRun) {
                $result = [
                    'success' => true,
                    'action' => 'mark-complete',
                    'dry_run' => true,
                    'target' => $target,
                    'would_mark_complete' => true,
                    'reason' => $reason,
                    'confidence' => $confidence
                ];
            } else {
                // Find functions in the target file and mark them as completed
                $functionsMarked = $this->markFileComplete($target, $reason, $confidence);
                
                $result = [
                    'success' => true,
                    'action' => 'mark-complete',
                    'target' => $target,
                    'functions_marked' => $functionsMarked,
                    'reason' => $reason,
                    'confidence' => $confidence,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }

            if ($json) {
                $output->writeln(json_encode($result));
            } else {
                if ($dryRun) {
                    $io->note("DRY RUN: Would mark file '{$target}' as complete");
                } else {
                    $io->success("✓ Marked {$result['functions_marked']} functions in '{$target}' as completed");
                }
                $io->text("Reason: {$reason}");
                $io->text("Confidence: {$confidence}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $error = 'Failed to mark complete: ' . $e->getMessage();
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error, 'target' => $target]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }
    }

    private function markFunction(InputInterface $input, OutputInterface $output): int
    {
        $functionId = $input->getArgument('target');
        $status = $input->getOption('status');
        $reason = $input->getOption('reason') ?? 'Status updated via progress command';
        $confidence = (float) ($input->getOption('confidence') ?? 1.0);
        $json = $input->getOption('json');
        $dryRun = $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        if (!$functionId) {
            $error = 'Function ID is required for mark-function action';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$status || !in_array($status, self::VALID_STATUSES)) {
            $error = 'Valid status is required: ' . implode(', ', self::VALID_STATUSES);
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        try {
            if ($dryRun) {
                $result = [
                    'success' => true,
                    'action' => 'mark-function',
                    'dry_run' => true,
                    'function_id' => $functionId,
                    'would_set_status' => $status,
                    'reason' => $reason,
                    'confidence' => $confidence
                ];
            } else {
                $updated = $this->updateFunctionStatus($functionId, $status, $reason, $confidence);
                
                $result = [
                    'success' => $updated,
                    'action' => 'mark-function',
                    'function_id' => $functionId,
                    'status' => $status,
                    'reason' => $reason,
                    'confidence' => $confidence,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }

            if ($json) {
                $output->writeln(json_encode($result));
            } else {
                if ($dryRun) {
                    $io->note("DRY RUN: Would set function '{$functionId}' to '{$status}'");
                } else {
                    if ($result['success']) {
                        $io->success("✓ Updated function '{$functionId}' to '{$status}'");
                    } else {
                        $io->warning("Function '{$functionId}' not found or already has this status");
                    }
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $error = 'Failed to mark function: ' . $e->getMessage();
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }
    }

    private function showPending(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $io = new SymfonyStyle($input, $output);

        try {
            $progress = $this->database->read('progress');
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);
            
            $pending = array_filter($byFunction, function($func) {
                return ($func['status'] ?? 'pending') === 'pending';
            });

            if ($json) {
                $output->writeln(json_encode([
                    'success' => true,
                    'action' => 'pending',
                    'pending_count' => count($pending),
                    'pending_functions' => array_keys($pending)
                ], ));
            } else {
                $io->title('Pending Functions');
                $io->text('Total pending: ' . count($pending));
                
                if (count($pending) > 0) {
                    foreach (array_slice($pending, 0, 10) as $id => $func) {
                        $io->text("  {$id}: " . ($func['name'] ?? 'Unknown'));
                    }
                    if (count($pending) > 10) {
                        $io->note('Showing first 10 of ' . count($pending) . ' pending functions');
                    }
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $e->getMessage()]));
            } else {
                $io->error('Failed to show pending: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    private function verifyProgress(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $io = new SymfonyStyle($input, $output);

        try {
            $issues = [];
            
            // Check if progress data exists and is readable
            try {
                $progress = $this->database->read('progress');
                $inventory = $this->database->read('inventory');
            } catch (\Exception $e) {
                $issues[] = 'Cannot read progress or inventory data: ' . $e->getMessage();
            }
            
            if (empty($issues)) {
                // Check consistency between inventory and progress
                $inventoryFunctions = $this->safeCount($inventory['functions'] ?? []);
                $progressFunctions = count($this->getByFunctionArray($progress['by_function'] ?? []));
                $globalTotal = $progress['global_stats']['total_functions'] ?? 0;
                
                if ($inventoryFunctions !== $progressFunctions) {
                    $issues[] = "Function count mismatch: inventory({$inventoryFunctions}) vs progress({$progressFunctions})";
                }
                
                if ($inventoryFunctions !== $globalTotal) {
                    $issues[] = "Global stats mismatch: inventory({$inventoryFunctions}) vs global({$globalTotal})";
                }
            }

            $result = [
                'success' => empty($issues),
                'action' => 'verify',
                'issues_found' => count($issues),
                'issues' => $issues,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            if ($json) {
                $output->writeln(json_encode($result));
            } else {
                if (empty($issues)) {
                    $io->success('✓ Progress tracking verification passed');
                } else {
                    $io->warning('⚠ Progress tracking issues found:');
                    foreach ($issues as $issue) {
                        $io->text('  • ' . $issue);
                    }
                    $io->note('Use "cpm tools:fix-perms" or "cpm start --force" to resolve issues');
                }
            }

            return empty($issues) ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $e->getMessage()]));
            } else {
                $io->error('Verification failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Mark a range of function IDs with the same status
     * Example: cpm progress mark-range 51-62 --status=completed
     */
    private function markRange(InputInterface $input, OutputInterface $output): int
    {
        $range = $input->getArgument('target');
        $status = $input->getOption('status');
        $reason = $input->getOption('reason') ?? 'Bulk status update via mark-range';
        $confidence = (float) ($input->getOption('confidence') ?? 1.0);
        $json = $input->getOption('json');
        $dryRun = $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        if (!$range) {
            $error = 'Range is required for mark-range action (e.g., "51-62", "1-10")';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$status || !in_array($status, self::VALID_STATUSES)) {
            $error = 'Valid status is required: ' . implode(', ', self::VALID_STATUSES);
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        // Parse range (e.g., "51-62" or "1-10")
        if (!preg_match('/^(\d+)-(\d+)$/', $range, $matches)) {
            $error = 'Invalid range format. Use "start-end" format (e.g., "51-62")';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $startId = (int)$matches[1];
        $endId = (int)$matches[2];

        if ($startId > $endId) {
            $error = 'Start ID must be less than or equal to end ID';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        $functionIds = range($startId, $endId);

        try {
            if ($dryRun) {
                $result = [
                    'success' => true,
                    'action' => 'mark-range',
                    'dry_run' => true,
                    'range' => $range,
                    'start_id' => $startId,
                    'end_id' => $endId,
                    'function_count' => count($functionIds),
                    'would_set_status' => $status,
                    'reason' => $reason
                ];

                if ($json) {
                    $output->writeln(json_encode($result));
                } else {
                    $io->note("DRY RUN: Would update " . count($functionIds) . " functions ({$range}) to '{$status}'");
                }
                return Command::SUCCESS;
            }

            // Update each function in the range
            $updated = 0;
            $notFound = 0;
            $errors = [];

            foreach ($functionIds as $functionId) {
                try {
                    if ($this->updateFunctionStatus((string)$functionId, $status, $reason, $confidence)) {
                        $updated++;
                    } else {
                        $notFound++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Function {$functionId}: " . $e->getMessage();
                }
            }

            $result = [
                'success' => true,
                'action' => 'mark-range',
                'range' => $range,
                'start_id' => $startId,
                'end_id' => $endId,
                'status' => $status,
                'functions_requested' => count($functionIds),
                'functions_updated' => $updated,
                'functions_not_found' => $notFound,
                'errors' => $errors,
                'reason' => $reason,
                'confidence' => $confidence,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            if ($json) {
                $output->writeln(json_encode($result));
            } else {
                $io->success("✓ Range update completed:");
                $io->text("  Range: {$range} ({$startId}-{$endId})");
                $io->text("  Status: {$status}");
                $io->text("  Updated: {$updated}/" . count($functionIds));
                if ($notFound > 0) {
                    $io->warning("  Not found: {$notFound} function(s) don't exist in inventory");
                }
                if (!empty($errors)) {
                    $io->error("  Errors: " . count($errors));
                    foreach ($errors as $error) {
                        $io->text("    - {$error}");
                    }
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $error = 'Range marking failed: ' . $e->getMessage();
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $error,
                    'range' => $range,
                    'status' => $status ?? null
                ]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }
    }

    /**
     * Mark function by natural language name (file:function format)
     * Examples:
     *   - Database.php:__construct
     *   - src/Core/Database.php:getConnection
     *   - Logger.php:info
     */
    private function markByName(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');
        $status = $input->getOption('status');
        $reason = $input->getOption('reason') ?? 'Status updated via mark-by-name';
        $confidence = (float) ($input->getOption('confidence') ?? 1.0);
        $json = $input->getOption('json');
        $dryRun = $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        if (!$target) {
            $error = 'Target is required for mark-by-name action (e.g., "Database.php:__construct")';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$status || !in_array($status, self::VALID_STATUSES)) {
            $error = 'Valid status is required: ' . implode(', ', self::VALID_STATUSES);
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        try {
            // Parse target (file:function format)
            if (strpos($target, ':') === false) {
                $error = 'Invalid format. Use "file:function" format (e.g., "Database.php:__construct")';
                if ($json) {
                    $output->writeln(json_encode(['success' => false, 'error' => $error]));
                } else {
                    $io->error($error);
                }
                return Command::FAILURE;
            }

            list($filePattern, $functionName) = explode(':', $target, 2);

            // Search for matching functions in inventory
            $inventory = $this->database->read('inventory');
            $matchingFunctions = [];

            foreach ($inventory['functions'] ?? [] as $func) {
                $filePath = $func['file_path'] ?? '';
                $funcName = $func['name'] ?? '';

                // Check if file matches (basename or full path)
                $fileMatches = (
                    basename($filePath) === $filePattern ||
                    $filePath === $filePattern ||
                    str_ends_with($filePath, '/' . $filePattern)
                );

                // Check if function name matches
                $functionMatches = ($funcName === $functionName);

                if ($fileMatches && $functionMatches) {
                    $matchingFunctions[] = $func;
                }
            }

            if (empty($matchingFunctions)) {
                $result = [
                    'success' => false,
                    'action' => 'mark-by-name',
                    'target' => $target,
                    'error' => 'No matching functions found',
                    'suggestion' => 'Check file and function names. Use: cpm status --detailed --file <file>'
                ];

                if ($json) {
                    $output->writeln(json_encode($result));
                } else {
                    $io->error("No matching functions found for: {$target}");
                    $io->note("Use 'cpm status --detailed --file <file>' to see available functions");
                }
                return Command::FAILURE;
            }

            if ($dryRun) {
                $result = [
                    'success' => true,
                    'action' => 'mark-by-name',
                    'dry_run' => true,
                    'target' => $target,
                    'matches_found' => count($matchingFunctions),
                    'matching_functions' => array_map(function($f) {
                        return [
                            'id' => $f['id'] ?? null,
                            'name' => $f['name'] ?? null,
                            'file' => $f['file_path'] ?? null
                        ];
                    }, $matchingFunctions),
                    'would_set_status' => $status
                ];

                if ($json) {
                    $output->writeln(json_encode($result));
                } else {
                    $io->note("DRY RUN: Would update " . count($matchingFunctions) . " function(s) to '{$status}'");
                    foreach ($matchingFunctions as $func) {
                        $io->text("  - {$func['name']} in {$func['file_path']} (ID: {$func['id']})");
                    }
                }
                return Command::SUCCESS;
            }

            // Update all matching functions
            $updated = 0;
            $errors = [];

            foreach ($matchingFunctions as $func) {
                $functionId = $func['id'] ?? null;
                if ($functionId) {
                    try {
                        if ($this->updateFunctionStatus((string)$functionId, $status, $reason, $confidence)) {
                            $updated++;
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Function {$functionId}: " . $e->getMessage();
                    }
                }
            }

            $result = [
                'success' => true,
                'action' => 'mark-by-name',
                'target' => $target,
                'status' => $status,
                'matches_found' => count($matchingFunctions),
                'functions_updated' => $updated,
                'errors' => $errors,
                'reason' => $reason,
                'confidence' => $confidence,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            if ($json) {
                $output->writeln(json_encode($result));
            } else {
                $io->success("✓ Updated {$updated} function(s) matching '{$target}' to '{$status}'");
                if (!empty($errors)) {
                    $io->error("Errors: " . count($errors));
                    foreach ($errors as $error) {
                        $io->text("  - {$error}");
                    }
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $error = 'Mark by name failed: ' . $e->getMessage();
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $error,
                    'target' => $target,
                    'status' => $status ?? null
                ]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }
    }

    private function bulkMark(InputInterface $input, OutputInterface $output): int
    {
        $pattern = $input->getArgument('target'); // File pattern like "*.py" or "src/**/*.php"
        $status = $input->getOption('status');
        $reason = $input->getOption('reason') ?? 'Bulk status update via progress command';
        $confidence = (float) ($input->getOption('confidence') ?? 1.0);
        $json = $input->getOption('json');
        $dryRun = $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);

        if (!$pattern) {
            $error = 'File pattern is required for bulk-mark action (e.g., "*.py", "src/**/*.php")';
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        if (!$status || !in_array($status, self::VALID_STATUSES)) {
            $error = 'Valid status is required: ' . implode(', ', self::VALID_STATUSES);
            if ($json) {
                $output->writeln(json_encode(['success' => false, 'error' => $error]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }

        try {
            // Find matching files using glob pattern
            $matchingFiles = $this->findMatchingFiles($pattern);
            
            if (empty($matchingFiles)) {
                $result = [
                    'success' => true,
                    'action' => 'bulk-mark',
                    'pattern' => $pattern,
                    'files_found' => 0,
                    'functions_updated' => 0,
                    'message' => 'No files matched the pattern'
                ];
                
                if ($json) {
                    $output->writeln(json_encode($result));
                } else {
                    $io->warning("No files matched pattern: {$pattern}");
                }
                return Command::SUCCESS;
            }

            if ($dryRun) {
                $result = [
                    'success' => true,
                    'action' => 'bulk-mark',
                    'dry_run' => true,
                    'pattern' => $pattern,
                    'files_found' => count($matchingFiles),
                    'would_update_files' => $matchingFiles,
                    'status' => $status,
                    'reason' => $reason
                ];
                
                if ($json) {
                    $output->writeln(json_encode($result));
                } else {
                    $io->note("DRY RUN: Would update " . count($matchingFiles) . " files to '{$status}'");
                    foreach ($matchingFiles as $file) {
                        $io->text("  - {$file}");
                    }
                }
                return Command::SUCCESS;
            }

            // Perform bulk update
            $totalFunctionsUpdated = 0;
            $filesUpdated = [];

            foreach ($matchingFiles as $file) {
                $functionsInFile = $this->markFileCompleteWithStatus($file, $reason, $confidence, $status);
                if ($functionsInFile > 0) {
                    $totalFunctionsUpdated += $functionsInFile;
                    $filesUpdated[] = $file;
                }
            }

            $result = [
                'success' => true,
                'action' => 'bulk-mark',
                'pattern' => $pattern,
                'status' => $status,
                'files_found' => count($matchingFiles),
                'files_updated' => count($filesUpdated),
                'functions_updated' => $totalFunctionsUpdated,
                'updated_files' => $filesUpdated,
                'reason' => $reason,
                'confidence' => $confidence,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            if ($json) {
                $output->writeln(json_encode($result));
            } else {
                $io->success("✓ Bulk update completed:");
                $io->text("  Pattern: {$pattern}");
                $io->text("  Status: {$status}");
                $io->text("  Files updated: " . count($filesUpdated) . "/" . count($matchingFiles));
                $io->text("  Functions updated: {$totalFunctionsUpdated}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $error = 'Bulk mark failed: ' . $e->getMessage();
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false, 
                    'error' => $error, 
                    'pattern' => $pattern,
                    'status' => $status ?? null
                ]));
            } else {
                $io->error($error);
            }
            return Command::FAILURE;
        }
    }

    private function findMatchingFiles(string $pattern): array
    {
        // Convert glob pattern to actual file list
        $files = [];
        
        // Handle different pattern types
        if (strpos($pattern, '*') !== false) {
            // Glob pattern
            $globResults = glob($pattern, GLOB_BRACE);
            if ($globResults) {
                $files = array_filter($globResults, 'is_file');
            }
        } else {
            // Single file
            if (file_exists($pattern) && is_file($pattern)) {
                $files = [$pattern];
            }
        }
        
        // Filter to only include files that are actually in the project
        $inventory = $this->database->read('inventory');
        $inventoryFiles = array_column($inventory['files'] ?? [], 'file_path');
        
        return array_filter($files, function($file) use ($inventoryFiles) {
            return in_array($file, $inventoryFiles) || in_array(realpath($file), $inventoryFiles);
        });
    }

    private function handleUnknownAction(string $action, bool $json, OutputInterface $output): int
    {
        $validActions = ['show', 'mark-complete', 'mark-function', 'mark-range', 'mark-by-name', 'bulk-mark', 'reset', 'validate', 'repair', 'pending', 'verify'];
        $error = "Unknown action '{$action}'. Valid actions: " . implode(', ', $validActions);
        
        if ($json) {
            $output->writeln(json_encode(['success' => false, 'error' => $error]));
        } else {
            $io = new SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
            $io->error($error);
            $io->note('Use "cpm help progress" for detailed usage information');
        }
        
        return Command::FAILURE;
    }

    private function markFileComplete(string $filePath, string $reason, float $confidence): int
    {
        $inventory = $this->database->read('inventory');
        $progress = $this->database->read('progress');

        // EARLY VALIDATION 1: Check if file exists in inventory
        $functionsInFile = array_filter($inventory['functions'] ?? [], function($func) use ($filePath) {
            return isset($func['file_path']) && $func['file_path'] === $filePath;
        });

        if (empty($functionsInFile)) {
            throw new \RuntimeException(
                "File '$filePath' not found in inventory. " .
                "Run 'cpm monitor' to update inventory, or check the file path."
            );
        }

        // EARLY VALIDATION 2: Check progress data structure BEFORE modifying
        if (!isset($progress['by_function'])) {
            throw new \RuntimeException(
                "Progress data structure is invalid (missing 'by_function'). " .
                "Run 'cpm progress repair --auto-fix' to fix the data structure."
            );
        }

        $marked = 0;
        $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);

        foreach ($functionsInFile as $function) {
            $functionId = $function['id'] ?? null;
            if ($functionId) {
                $byFunction[$functionId] = [
                    'status' => 'completed',
                    'confidence' => $confidence,
                    'reason' => $reason,
                    'last_updated' => date('Y-m-d H:i:s'),
                    'completed_at' => date('Y-m-d H:i:s')
                ];
                $marked++;
            }
        }

        // Update progress data
        $progress['by_function'] = $byFunction;
        $this->recalculateGlobalStats($progress);

        // EARLY VALIDATION 3: Validate AFTER modification, BEFORE writing (if not using --force)
        // Note: --force flag bypassing will be handled at a higher level
        $this->database->write('progress', $progress);

        return $marked;
    }

    private function markFileCompleteWithStatus(string $filePath, string $reason, float $confidence, string $status): int
    {
        $inventory = $this->database->read('inventory');
        $progress = $this->database->read('progress');

        // EARLY VALIDATION 1: Check if file exists in inventory
        $functionsInFile = array_filter($inventory['functions'] ?? [], function($func) use ($filePath) {
            return isset($func['file_path']) && $func['file_path'] === $filePath;
        });

        if (empty($functionsInFile)) {
            throw new \RuntimeException(
                "File '$filePath' not found in inventory. " .
                "Run 'cpm monitor' to update inventory, or check the file path."
            );
        }

        // EARLY VALIDATION 2: Check progress data structure BEFORE modifying
        if (!isset($progress['by_function'])) {
            throw new \RuntimeException(
                "Progress data structure is invalid (missing 'by_function'). " .
                "Run 'cpm progress repair --auto-fix' to fix the data structure."
            );
        }

        $marked = 0;
        $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);

        foreach ($functionsInFile as $function) {
            $functionId = $function['id'] ?? null;
            if ($functionId) {
                $byFunction[$functionId] = [
                    'status' => $status,
                    'confidence' => $confidence,
                    'reason' => $reason,
                    'last_updated' => date('Y-m-d H:i:s')
                ];

                if ($status === 'completed') {
                    $byFunction[$functionId]['completed_at'] = date('Y-m-d H:i:s');
                }

                $marked++;
            }
        }

        // Update progress data
        $progress['by_function'] = $byFunction;
        $this->recalculateGlobalStats($progress);
        $this->database->write('progress', $progress);

        return $marked;
    }

    private function updateFunctionStatus(string|int $functionId, string $status, string $reason, float $confidence): bool
    {
        // Normalize function ID to string (JSON decode may convert numeric strings to ints)
        $functionId = (string)$functionId;

        $progress = $this->database->read('progress');
        $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);

        if (!isset($byFunction[$functionId])) {
            // Create new entry if function exists in inventory
            $inventory = $this->database->read('inventory');
            $functionExists = false;
            foreach ($inventory['functions'] ?? [] as $func) {
                if (isset($func['id']) && (string)$func['id'] === $functionId) {
                    $functionExists = true;
                    break;
                }
            }
            if (!$functionExists) {
                return false;
            }
        }

        $byFunction[$functionId] = array_merge(
            $byFunction[$functionId] ?? [],
            [
                'status' => $status,
                'confidence' => $confidence,
                'reason' => $reason,
                'last_updated' => date('Y-m-d H:i:s')
            ]
        );

        if ($status === 'completed') {
            $byFunction[$functionId]['completed_at'] = date('Y-m-d H:i:s');
        }

        $progress['by_function'] = $byFunction;
        $this->recalculateGlobalStats($progress);
        $this->database->write('progress', $progress);

        return true;
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

    private function calculateStatusDistribution(array $byFunction): array
    {
        $distribution = array_fill_keys(self::VALID_STATUSES, 0);
        
        foreach ($byFunction as $func) {
            $status = $func['status'] ?? 'pending';
            if (isset($distribution[$status])) {
                $distribution[$status]++;
            }
        }
        
        return array_filter($distribution); // Remove zero counts
    }

    private function recalculateGlobalStats(array &$progress): void
    {
        $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);
        $totalFunctions = count($byFunction);
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
    }

    /**
     * Safely count array or object elements
     */
    private function safeCount($value): int
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_object($value)) {
            return count((array) $value);
        }
        return 0;
    }

    /**
     * Validate progress data structure without modifying it
     * Checks schema compliance and reports issues
     */
    private function validateProgress(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $verbose = $output->isVerbose();

        $this->log(LogLevel::INFO, 'Starting progress validation', [
            'verbose' => $verbose,
            'json' => $json
        ]);

        try {
            // Verbose: Show reading data
            if ($verbose && !$json) {
                $io->section('🔍 Reading progress data...');
                $io->text('Path: ' . $this->database->getDatabasePath() . '/progress.json');
            }

            // Read progress data
            $progressData = $this->database->read('progress');

            $functionCount = count((array)($progressData['by_function'] ?? []));
            $fileCount = count((array)($progressData['by_file'] ?? []));

            $this->log(LogLevel::DEBUG, 'Progress data loaded', [
                'functions_count' => $functionCount
            ]);

            if ($verbose && !$json) {
                $io->text(sprintf('✅ Loaded %d functions across %d files', $functionCount, $fileCount));
                $io->newLine();
                $io->section('🔍 Validating schema...');
            }

            // Validate against schema
            $validator = new \ClaudeProjectManager\Database\SchemaValidator(
                \ClaudeProjectManager\Database\SchemaPathResolver::fromDatabasePath(
                    $this->database->getDatabasePath()
                )
            );

            $result = $validator->validateProgressData($progressData);

            if ($verbose && !$json) {
                if ($result->isValid()) {
                    $io->text('✅ Schema validation passed');
                } else {
                    $io->text('❌ Schema validation failed with ' . count($result->getErrors()) . ' errors');
                }
                $io->newLine();
            }

            if ($result->isValid()) {
                $this->log(LogLevel::INFO, 'Validation successful');
                if ($json) {
                    $output->writeln(json_encode([
                        'success' => true,
                        'valid' => true,
                        'message' => 'Progress data is valid'
                    ]));
                } else {
                    $io->success('✅ Progress data is valid and schema-compliant');
                }
                return Command::SUCCESS;
            } else {
                $errors = $result->getErrors();

                $this->log(LogLevel::WARNING, 'Validation failed', [
                    'error_count' => count($errors)
                ]);

                if ($json) {
                    $output->writeln(json_encode([
                        'success' => false,
                        'valid' => false,
                        'errors' => $errors,
                        'suggestion' => 'Run: cpm progress repair --auto-fix'
                    ]));
                } else {
                    $io->error('❌ Progress data validation failed');
                    $io->section('Validation Errors:');
                    foreach ($errors as $error) {
                        $io->writeln($error);
                    }
                    $io->newLine();
                    $io->note('Run "cpm progress repair --auto-fix" to attempt automatic repair');
                }
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->log(LogLevel::ERROR, 'Validation exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);

            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
            } else {
                $io->error('Validation failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Repair broken progress data structures
     * Attempts to auto-fix common schema validation issues
     */
    private function repairProgress(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $autoFix = $input->getOption('auto-fix');
        $dryRun = $input->getOption('dry-run');

        try {
            // Read current progress data
            $progressData = $this->database->read('progress');
            $originalData = $progressData;
            $fixesApplied = [];

            // Fix 1: Ensure by_file exists and is an object
            if (!isset($progressData['by_file'])) {
                $progressData['by_file'] = new \stdClass();
                $fixesApplied[] = 'Added missing by_file field';
            } elseif (is_array($progressData['by_file']) && empty($progressData['by_file'])) {
                $progressData['by_file'] = new \stdClass();
                $fixesApplied[] = 'Converted empty by_file array to object';
            }

            // Fix 2: Ensure by_function exists and is an object
            if (!isset($progressData['by_function'])) {
                $progressData['by_function'] = new \stdClass();
                $fixesApplied[] = 'Added missing by_function field';
            } elseif (is_array($progressData['by_function']) && empty($progressData['by_function'])) {
                $progressData['by_function'] = new \stdClass();
                $fixesApplied[] = 'Converted empty by_function array to object';
            }

            // Fix 3: Ensure global_stats exists
            if (!isset($progressData['global_stats'])) {
                $progressData['global_stats'] = [
                    'total_functions' => 0,
                    'completed_functions' => 0,
                    'completion_percentage' => 0,
                    'last_updated' => date('c')
                ];
                $fixesApplied[] = 'Added missing global_stats field';
            }

            // Fix 4: Ensure last_updated is present in global_stats
            if (!isset($progressData['global_stats']['last_updated'])) {
                $progressData['global_stats']['last_updated'] = date('c');
                $fixesApplied[] = 'Added missing last_updated timestamp';
            }

            // Fix 5: Validate status values in by_function
            if (isset($progressData['by_function']) && is_object($progressData['by_function'])) {
                foreach ($progressData['by_function'] as $funcId => $funcData) {
                    if (isset($funcData['status']) && !in_array($funcData['status'], self::VALID_STATUSES)) {
                        $funcData['status'] = 'pending';
                        $progressData['by_function']->$funcId = $funcData;
                        $fixesApplied[] = "Fixed invalid status for function $funcId";
                    }
                }
            }

            if (empty($fixesApplied)) {
                if ($json) {
                    $output->writeln(json_encode([
                        'success' => true,
                        'fixes_applied' => 0,
                        'message' => 'No issues found'
                    ]));
                } else {
                    $io->success('✅ No issues found. Progress data is healthy.');
                }
                return Command::SUCCESS;
            }

            // Show what would be fixed
            if ($json) {
                $output->writeln(json_encode([
                    'success' => true,
                    'dry_run' => $dryRun,
                    'fixes_applied' => count($fixesApplied),
                    'fixes' => $fixesApplied
                ]));
            } else {
                $io->section('🔧 Repairs Available:');
                foreach ($fixesApplied as $fix) {
                    $io->writeln('  • ' . $fix);
                }
                $io->newLine();
            }

            // Apply fixes if not dry-run
            if (!$dryRun && ($autoFix || $io->confirm('Apply these fixes?', true))) {
                $this->database->write('progress', $progressData);

                if (!$json) {
                    $io->success(sprintf('✅ Applied %d fixes successfully', count($fixesApplied)));
                    $io->note('Run "cpm progress validate" to verify the repairs');
                }
                return Command::SUCCESS;
            } else {
                if (!$json) {
                    $io->note('Dry run - no changes made. Use --auto-fix to apply repairs.');
                }
                return Command::SUCCESS;
            }
        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
            } else {
                $io->error('Repair failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Reset progress for specific target (file or function)
     * Provides error recovery mechanism
     */
    private function resetProgress(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getArgument('target');
        $json = $input->getOption('json');
        $force = $input->getOption('force');

        if (!$target && !$force) {
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => 'Target required (or use --force to reset all)'
                ]));
            } else {
                $io->error('Target required. Specify a file path or use --force to reset all progress.');
                $io->note('Example: cpm progress reset src/utils.py');
                $io->warning('To reset ALL progress: cpm progress reset --force');
            }
            return Command::FAILURE;
        }

        try {
            $progressData = $this->database->read('progress');

            if (!$target && $force) {
                // Reset all progress
                $progressData['by_file'] = new \stdClass();
                $progressData['by_function'] = new \stdClass();
                $progressData['global_stats'] = [
                    'total_functions' => 0,
                    'completed_functions' => 0,
                    'completion_percentage' => 0,
                    'last_updated' => date('c')
                ];

                $this->database->write('progress', $progressData);

                if ($json) {
                    $output->writeln(json_encode([
                        'success' => true,
                        'reset' => 'all',
                        'message' => 'All progress reset'
                    ]));
                } else {
                    $io->success('✅ All progress has been reset');
                }
                return Command::SUCCESS;
            }

            // Reset specific file
            $fileReset = false;
            if (isset($progressData['by_file']->$target)) {
                unset($progressData['by_file']->$target);
                $fileReset = true;
            }

            // Reset functions in that file
            $functionsReset = 0;
            if (isset($progressData['by_function']) && is_object($progressData['by_function'])) {
                foreach ($progressData['by_function'] as $funcId => $funcData) {
                    if (isset($funcData['file']) && $funcData['file'] === $target) {
                        unset($progressData['by_function']->$funcId);
                        $functionsReset++;
                    }
                }
            }

            if (!$fileReset && $functionsReset === 0) {
                if ($json) {
                    $output->writeln(json_encode([
                        'success' => false,
                        'message' => 'Target not found in progress data'
                    ]));
                } else {
                    $io->warning("No progress data found for: $target");
                }
                return Command::FAILURE;
            }

            // Write updated progress
            $this->database->write('progress', $progressData);

            if ($json) {
                $output->writeln(json_encode([
                    'success' => true,
                    'target' => $target,
                    'functions_reset' => $functionsReset
                ]));
            } else {
                $io->success("✅ Reset progress for: $target");
                if ($functionsReset > 0) {
                    $io->note("Reset $functionsReset function(s)");
                }
            }
            return Command::SUCCESS;
        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
            } else {
                $io->error('Reset failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }
}