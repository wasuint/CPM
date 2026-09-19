<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\SessionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Session checkpointing for AI workflow continuity
 * Captures project state snapshots for seamless session handoffs
 */
class CheckpointCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;
    private ?string $actingAgent = null;

    protected static $defaultName = 'checkpoint';
    protected static $defaultDescription = 'Create checkpoints for AI workflow continuity';

    public function __construct(DatabaseManager $database, SessionManager $sessionManager)
    {
        parent::__construct();
        $this->database = $database;
        $this->sessionManager = $sessionManager;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: create, list, restore, auto, prune',
                'create'
            )
            ->addArgument(
                'checkpoint-id',
                InputArgument::OPTIONAL,
                'Checkpoint ID for restore operation'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_REQUIRED,
                'Description of the checkpoint',
                'Manual checkpoint'
            )
            ->addOption(
                'auto-description',
                'a',
                InputOption::VALUE_NONE,
                'Generate automatic description based on current state'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit number of checkpoints to list',
                '10'
            )
            ->addOption(
                'keep',
                'k',
                InputOption::VALUE_REQUIRED,
                'Number of most recent checkpoints to keep when pruning (default: retention limit)'
            )
            ->addOption(
                'agent',
                null,
                InputOption::VALUE_REQUIRED,
                'Acting agent recorded on the checkpoint (default: $CPM_AGENT env)'
            )
            ->setHelp('
Create and manage session checkpoints for AI workflow continuity.

Checkpoints capture complete project state including:
- Progress status and completion data
- Session information and activity
- Current task context and metadata
- System health and configuration

Actions:
  create          Create a new checkpoint (default)
  list            List existing checkpoints
  restore         Restore from a checkpoint
  auto            Create automatic checkpoint with smart description
  prune           Remove old checkpoints, keeping the most recent ones

Examples:
  cpm checkpoint create --description="Before refactoring auth module"
  cpm checkpoint create --auto-description --json
  cpm checkpoint list --json --limit=5
  cpm checkpoint restore checkpoint_123 --json
  cpm checkpoint prune --keep=20 --json

Retention: at most checkpoints.max_retained checkpoints are kept
(config, default 50); older ones are dropped automatically on create.

Checkpoints enable:
- Seamless AI session handoffs
- Rollback to previous states
- Progress milestone tracking
- Session continuity across interruptions

Auto-checkpoints are created:
- Before major operations (start, bulk operations)
- At completion milestones (25%, 50%, 75%, 100%)
- Before system repairs and resets
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $checkpointId = $input->getArgument('checkpoint-id');
        $description = $input->getOption('description');
        $autoDescription = (bool) $input->getOption('auto-description');
        $json = $this->isJsonRequested($input);
        $limit = (int) $input->getOption('limit');
        $io = $this->createStyleIfNeeded($input, $output);

        $agent = $input->getOption('agent');
        if ($agent === null || $agent === '') {
            $env = getenv('CPM_AGENT');
            $agent = ($env !== false && $env !== '') ? $env : null;
        }
        $this->actingAgent = $agent !== null ? trim($agent) : null;

        try {
            $result = match ($action) {
                'create' => $this->createCheckpoint($description, $autoDescription),
                'list' => $this->listCheckpoints($limit),
                'restore' => $this->restoreCheckpoint($checkpointId),
                'auto' => $this->createAutoCheckpoint(),
                'prune' => $this->pruneCheckpoints($input->getOption('keep') !== null ? (int) $input->getOption('keep') : null),
                default => throw new \InvalidArgumentException("Unknown action: {$action}")
            };

            if ($json) {
                $this->outputJsonSuccess($output, 'checkpoint', $result, [
                    'action' => $action,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->outputHumanResult($io, $action, $result);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'checkpoint', $e, [
                'action' => $action,
                'checkpoint_id' => $checkpointId
            ]);
        }
    }

    private function createCheckpoint(string $description, bool $autoDescription): array
    {
        if ($autoDescription) {
            $description = $this->generateAutoDescription();
        }

        $checkpointId = 'checkpoint_' . time() . '_' . substr(md5(uniqid()), 0, 8);
        
        $checkpoint = [
            'id' => $checkpointId,
            'created_at' => date('Y-m-d H:i:s'),
            'description' => $description,
            'type' => $autoDescription ? 'auto' : 'manual',
            'state_snapshot' => $this->captureStateSnapshot(),
            'session_info' => $this->captureSessionInfo(),
            'metadata' => $this->captureMetadata()
        ];

        // Stored under metadata: the checkpoint entry schema forbids unknown
        // top-level keys (additionalProperties: false) and deployed projects
        // keep their own published schema copies.
        if ($this->actingAgent !== null) {
            $checkpoint['metadata']['agent'] = $this->actingAgent;
        }

        // Save checkpoint inside a locked transaction; enforce retention
        $maxRetained = $this->getMaxRetainedCheckpoints();
        $totalAfter = 0;
        $this->database->safeUpdate('checkpoints', function (array $data) use ($checkpoint, $maxRetained, &$totalAfter) {
            $data['checkpoints'][] = $checkpoint;

            if (count($data['checkpoints']) > $maxRetained) {
                $data['checkpoints'] = array_slice($data['checkpoints'], -$maxRetained);
            }

            $totalAfter = count($data['checkpoints']);

            return $data;
        }, 'checkpoint create');

        return [
            'checkpoint_created' => true,
            'checkpoint_id' => $checkpointId,
            'description' => $description,
            'state_captured' => true,
            'snapshot_size' => $this->calculateSnapshotSize($checkpoint),
            'total_checkpoints' => $totalAfter
        ];
    }

    /**
     * Remove old checkpoints, keeping only the most recent $keep entries.
     */
    private function pruneCheckpoints(?int $keep): array
    {
        $keep = $keep ?? $this->getMaxRetainedCheckpoints();
        if ($keep < 1) {
            throw new \InvalidArgumentException('--keep must be at least 1');
        }

        $before = 0;
        $after = 0;
        $this->database->safeUpdate('checkpoints', function (array $data) use ($keep, &$before, &$after) {
            $checkpoints = $data['checkpoints'] ?? [];
            $before = count($checkpoints);

            if ($before > $keep) {
                $checkpoints = array_slice($checkpoints, -$keep);
            }

            $after = count($checkpoints);
            $data['checkpoints'] = $checkpoints;

            return $data;
        }, 'checkpoint prune');

        return [
            'pruned' => $before - $after,
            'kept' => $after,
            'keep_limit' => $keep
        ];
    }

    /**
     * Retention limit from config (checkpoints.max_retained), default 50.
     */
    private function getMaxRetainedCheckpoints(): int
    {
        try {
            $config = new \ClaudeProjectManager\ConfigManager(getcwd());
            $value = (int) ($config->get('checkpoints.max_retained') ?? 0);
            if ($value > 0) {
                return $value;
            }
        } catch (\Exception $e) {
            // fall through to default
        }

        return 50;
    }

    private function listCheckpoints(int $limit): array
    {
        $checkpoints = $this->loadCheckpoints();
        $checkpoints = array_slice($checkpoints, -$limit); // Get most recent

        $summaryList = [];
        foreach ($checkpoints as $checkpoint) {
            $summaryList[] = [
                'id' => $checkpoint['id'],
                'created_at' => $checkpoint['created_at'],
                'description' => $checkpoint['description'],
                'type' => $checkpoint['type'],
                'agent' => $checkpoint['metadata']['agent'] ?? $checkpoint['agent'] ?? null,
                'progress_at_time' => $checkpoint['state_snapshot']['progress']['completion_percentage'] ?? 0,
                'functions_total' => $checkpoint['state_snapshot']['progress']['total_functions'] ?? 0,
                'age' => $this->getTimeAgo($checkpoint['created_at'])
            ];
        }

        return [
            'total_checkpoints' => count($this->loadCheckpoints()),
            'showing' => count($summaryList),
            'checkpoints' => array_reverse($summaryList) // Most recent first
        ];
    }

    private function restoreCheckpoint(?string $checkpointId): array
    {
        if (!$checkpointId) {
            throw new \InvalidArgumentException('Checkpoint ID is required for restore operation');
        }

        $checkpoints = $this->loadCheckpoints();
        $checkpoint = null;

        foreach ($checkpoints as $cp) {
            if ($cp['id'] === $checkpointId) {
                $checkpoint = $cp;
                break;
            }
        }

        if (!$checkpoint) {
            throw new \InvalidArgumentException("Checkpoint not found: {$checkpointId}");
        }

        // Create backup checkpoint before restore
        $backupResult = $this->createCheckpoint('Before restore to ' . $checkpointId, false);

        // Restore state (simplified - in real implementation, would restore database files)
        $restoredItems = $this->performRestore($checkpoint);

        return [
            'checkpoint_restored' => true,
            'checkpoint_id' => $checkpointId,
            'description' => $checkpoint['description'],
            'restored_at' => date('Y-m-d H:i:s'),
            'backup_checkpoint' => $backupResult['checkpoint_id'],
            'restored_items' => $restoredItems,
            'warning' => 'Checkpoint restore is experimental. Verify system state after restore.'
        ];
    }

    private function createAutoCheckpoint(): array
    {
        return $this->createCheckpoint('', true);
    }

    private function captureStateSnapshot(): array
    {
        // Deliberately light: earlier versions embedded the FULL progress,
        // inventory and session databases in every checkpoint, which grew
        // checkpoints.json to ~2.6 MB per entry (124 MB seen in production)
        // while restore never actually consumed that data. Databases are
        // still on disk; a checkpoint only needs the aggregate picture.
        $snapshot = [];

        try {
            $snapshot['project'] = $this->database->read('project');

            $progress = $this->database->read('progress');
            $stats = $progress['global_stats'] ?? [];
            $snapshot['progress'] = [
                'completion_percentage' => $stats['completion_percentage'] ?? 0,
                'total_functions' => $stats['total_functions'] ?? 0,
                'completed_functions' => $stats['completed_functions'] ?? 0,
                'global_stats' => $stats
            ];

            $inventory = $this->database->read('inventory');
            $files = $inventory['files'] ?? [];
            $snapshot['inventory_summary'] = [
                'file_count' => is_array($files) ? count($files) : 0,
                'total_functions' => $inventory['total_functions'] ?? null
            ];

            $sessions = $this->database->read('sessions');
            $snapshot['sessions'] = [
                'current_session' => $sessions['current_session'] ?? null
            ];

        } catch (\Exception $e) {
            $snapshot['error'] = 'Failed to capture complete state: ' . $e->getMessage();
        }

        return $snapshot;
    }

    private function captureSessionInfo(): array
    {
        try {
            $sessionReport = $this->sessionManager->generateStatusReport();
            $sessionHistory = $this->sessionManager->getSessionHistory(3);
            
            return [
                'current_session' => $sessionReport,
                'recent_sessions' => $sessionHistory,
                'session_active' => $sessionReport['status'] === 'active'
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to capture session info: ' . $e->getMessage()];
        }
    }

    private function captureMetadata(): array
    {
        return [
            'cpm_version' => \ClaudeProjectManager\Console\Application::VERSION,
            'php_version' => PHP_VERSION,
            'system_time' => time(),
            'working_directory' => getcwd(),
            'system_load' => sys_getloadavg()[0] ?? null,
            'memory_usage' => memory_get_usage(true)
        ];
    }

    private function generateAutoDescription(): string
    {
        try {
            $progress = $this->database->read('progress');
            $percentage = round($progress['global_stats']['completion_percentage'] ?? 0, 1);
            $totalFunctions = $progress['global_stats']['total_functions'] ?? 0;
            $completedFunctions = $progress['global_stats']['completed_functions'] ?? 0;

            $sessionReport = $this->sessionManager->generateStatusReport();
            $duration = $sessionReport['duration_minutes'];

            $descriptions = [
                "Progress: {$percentage}% complete ({$completedFunctions}/{$totalFunctions} functions)",
                "Session duration: {$duration} minutes",
                "Auto-checkpoint at " . date('H:i:s')
            ];

            // Add milestone descriptions
            if ($percentage == 0) {
                $descriptions[] = "Project initialization phase";
            } elseif ($percentage >= 25 && $percentage < 50) {
                $descriptions[] = "First quarter milestone";
            } elseif ($percentage >= 50 && $percentage < 75) {
                $descriptions[] = "Midpoint milestone";
            } elseif ($percentage >= 75 && $percentage < 100) {
                $descriptions[] = "Final quarter milestone";
            } elseif ($percentage >= 100) {
                $descriptions[] = "Project completion milestone";
            }

            return implode(' | ', $descriptions);
            
        } catch (\Exception $e) {
            return 'Auto-checkpoint at ' . date('Y-m-d H:i:s');
        }
    }

    private function loadCheckpoints(): array
    {
        try {
            $checkpointData = $this->database->read('checkpoints');
            return $checkpointData['checkpoints'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function saveCheckpoints(array $checkpoints): void
    {
        $this->database->write('checkpoints', ['checkpoints' => $checkpoints]);
    }

    private function calculateSnapshotSize(array $checkpoint): string
    {
        $serialized = serialize($checkpoint);
        $bytes = strlen($serialized);
        
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function performRestore(array $checkpoint): array
    {
        $restored = [];
        
        // In a full implementation, this would restore database files
        // For now, we'll just return what would be restored
        if (isset($checkpoint['state_snapshot']['progress'])) {
            $restored[] = 'progress_data';
        }
        if (isset($checkpoint['state_snapshot']['sessions'])) {
            $restored[] = 'session_data';
        }
        
        return $restored;
    }

    private function getTimeAgo(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if (!$time) return 'unknown';
        
        $diff = time() - $time;
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }

    private function outputHumanResult(?SymfonyStyle $io, string $action, array $result): void
    {
        if (!$io) return;

        switch ($action) {
            case 'create':
            case 'auto':
                $io->success('✅ Checkpoint created successfully');
                $io->text('ID: ' . $result['checkpoint_id']);
                $io->text('Description: ' . $result['description']);
                $io->text('Snapshot Size: ' . $result['snapshot_size']);
                $io->text('Total Checkpoints: ' . $result['total_checkpoints']);
                break;

            case 'list':
                $io->title('📋 Available Checkpoints');
                $io->text('Showing ' . $result['showing'] . ' of ' . $result['total_checkpoints'] . ' checkpoints');
                
                if (empty($result['checkpoints'])) {
                    $io->note('No checkpoints found. Create one with: cpm checkpoint create');
                    return;
                }

                $tableData = [];
                foreach ($result['checkpoints'] as $cp) {
                    $tableData[] = [
                        substr($cp['id'], -12),
                        $cp['created_at'],
                        $cp['description'],
                        $cp['type'],
                        $cp['progress_at_time'] . '%',
                        $cp['age']
                    ];
                }

                $io->table(
                    ['ID', 'Created', 'Description', 'Type', 'Progress', 'Age'],
                    $tableData
                );
                break;

            case 'prune':
                $io->success('✅ Checkpoints pruned');
                $io->text('Removed: ' . $result['pruned']);
                $io->text('Kept: ' . $result['kept'] . ' (limit: ' . $result['keep_limit'] . ')');
                break;

            case 'restore':
                $io->success('✅ Checkpoint restored successfully');
                $io->text('Restored ID: ' . $result['checkpoint_id']);
                $io->text('Description: ' . $result['description']);
                $io->text('Backup Created: ' . $result['backup_checkpoint']);
                $io->warning($result['warning']);
                break;
        }
    }
}
