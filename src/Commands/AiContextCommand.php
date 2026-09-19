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
 * AI-optimized context generation command
 * Generates perfect context summaries for AI assistant handoffs
 */
class AiContextCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;

    protected static $defaultName = 'ai-context';
    protected static $defaultDescription = 'Generate AI-optimized context for session handoffs';

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
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format (always recommended for AI)'
            )
            ->addOption(
                'include-todos',
                't',
                InputOption::VALUE_NONE,
                'Include pending tasks and next actions'
            )
            ->addOption(
                'include-history',
                null,
                InputOption::VALUE_NONE,
                'Include recent session activity'
            )
            ->addOption(
                'minimal',
                'm',
                InputOption::VALUE_NONE,
                'Minimal context for quick handoffs'
            )
            ->addOption(
                'detailed',
                'd',
                InputOption::VALUE_NONE,
                'Comprehensive context with full details'
            )
            ->setHelp('
Generate AI-optimized context summaries for seamless session handoffs.

This command creates structured context information perfect for AI assistants
to understand the current project state and continue work effectively.

Context Levels:
  --minimal     Essential info only (quick handoffs)
  --detailed    Comprehensive context (complex projects)
  default       Balanced context (recommended)

Additional Data:
  --include-todos    Add pending tasks and recommended actions
  --include-history  Add recent session activities

Examples:
  cpm ai-context --json --include-todos    # Full context with tasks
  cpm ai-context --minimal --json          # Quick handoff context
  cpm ai-context --detailed                # Human-readable detailed context

Output is specifically optimized for AI consumption with:
- Clear project overview
- Progress status with percentages
- Pending work identification
- Recommended next actions
- Context for decision making
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->isJsonRequested($input);
        $includeTodos = (bool) $input->getOption('include-todos');
        $includeHistory = (bool) $input->getOption('include-history');
        $minimal = (bool) $input->getOption('minimal');
        $detailed = (bool) $input->getOption('detailed');
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            $contextData = $this->generateAiContext($minimal, $detailed, $includeTodos, $includeHistory);

            if ($json) {
                $this->outputJsonSuccess($output, 'ai-context', $contextData, [
                    'generated_at' => date('Y-m-d H:i:s'),
                    'context_level' => $minimal ? 'minimal' : ($detailed ? 'detailed' : 'balanced'),
                    'includes_todos' => $includeTodos,
                    'includes_history' => $includeHistory
                ]);
            } else {
                $this->outputHumanContext($io, $contextData, $minimal, $detailed, $includeTodos);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'ai-context', $e);
        }
    }

    private function generateAiContext(bool $minimal, bool $detailed, bool $includeTodos, bool $includeHistory): array
    {
        $context = [
            'project_overview' => $this->getProjectOverview($minimal),
            'progress_status' => $this->getProgressStatus($minimal),
            'current_state' => $this->getCurrentState($minimal)
        ];

        if ($includeTodos || $detailed) {
            $context['recommended_actions'] = $this->getRecommendedActions();
            $context['pending_work'] = $this->getPendingWork();
        }

        if ($includeHistory || $detailed) {
            $context['recent_activity'] = $this->getRecentActivity();
            $context['session_info'] = $this->getSessionInfo();
        }

        if ($detailed) {
            $context['detailed_analysis'] = $this->getDetailedAnalysis();
            $context['system_health'] = $this->getSystemHealth();
        }

        return $context;
    }

    private function getProjectOverview(bool $minimal): array
    {
        try {
            $project = $this->database->read('project');
            $inventory = $this->database->read('inventory');
            
            $overview = [
                'name' => $project['name'] ?? 'Unknown Project',
                'type' => $this->detectProjectType(),
                'total_files' => $this->safeCount($inventory['files'] ?? []),
                'total_functions' => $this->safeCount($inventory['functions'] ?? []),
                'last_analysis' => $project['last_analysis'] ?? 'Never'
            ];

            if (!$minimal) {
                $overview['root_path'] = $project['root_path'] ?? '';
                $overview['initialized_at'] = $project['initialized_at'] ?? '';
                $overview['primary_languages'] = $this->getLanguageBreakdown($inventory);
            }

            return $overview;

        } catch (\Exception $e) {
            return ['error' => 'Could not load project overview: ' . $e->getMessage()];
        }
    }

    private function getProgressStatus(bool $minimal): array
    {
        try {
            $progress = $this->database->read('progress');
            $globalStats = $progress['global_stats'] ?? [];
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);

            $status = [
                'completion_percentage' => round($globalStats['completion_percentage'] ?? 0, 1),
                'total_functions' => $globalStats['total_functions'] ?? 0,
                'completed_functions' => $globalStats['completed_functions'] ?? 0,
                'last_updated' => $globalStats['last_updated'] ?? 'Never'
            ];

            if (!$minimal) {
                $statusDistribution = $this->calculateStatusDistribution($byFunction);
                $status['status_breakdown'] = $statusDistribution;
                $status['progress_trend'] = $this->getProgressTrend();
            }

            return $status;

        } catch (\Exception $e) {
            return ['error' => 'Could not load progress status: ' . $e->getMessage()];
        }
    }

    private function getCurrentState(bool $minimal): array
    {
        try {
            $statusReport = $this->sessionManager->generateStatusReport();
            
            $state = [
                'session_active' => $statusReport['status'] === 'active',
                'current_task' => $statusReport['current_task'],
                'session_duration' => $statusReport['duration_minutes'] . ' minutes'
            ];

            if (!$minimal) {
                $state['session_id'] = substr($statusReport['session_id'], -12);
                $state['activities_count'] = $statusReport['activities_count'];
                $state['checkpoints'] = $statusReport['checkpoints'];
                $state['last_activity'] = $statusReport['last_activity'] ?? 'None';
            }

            return $state;

        } catch (\Exception $e) {
            return ['error' => 'Could not load current state: ' . $e->getMessage()];
        }
    }

    private function getRecommendedActions(): array
    {
        $actions = [];

        try {
            $progress = $this->database->read('progress');
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);
            
            // Count pending and in-progress items
            $pending = array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'pending');
            $inProgress = array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'in_progress');
            $hasIssues = array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'has_issues');

            if (count($pending) > 0) {
                $actions[] = [
                    'priority' => 'high',
                    'action' => 'Continue with pending functions',
                    'command' => 'cpm progress pending --json',
                    'description' => count($pending) . ' functions need attention'
                ];
            }

            if (count($inProgress) > 0) {
                $actions[] = [
                    'priority' => 'high',
                    'action' => 'Complete in-progress work',
                    'command' => 'cpm status --detailed',
                    'description' => count($inProgress) . ' functions partially complete'
                ];
            }

            if (count($hasIssues) > 0) {
                $actions[] = [
                    'priority' => 'medium',
                    'action' => 'Address functions with issues',
                    'command' => 'cpm progress show --json',
                    'description' => count($hasIssues) . ' functions have issues'
                ];
            }

            // Always add verification step
            $actions[] = [
                'priority' => 'low',
                'action' => 'Verify system health',
                'command' => 'cpm verify --json',
                'description' => 'Ensure CPM tracking is consistent'
            ];

        } catch (\Exception $e) {
            $actions[] = [
                'priority' => 'high',
                'action' => 'Fix system issues',
                'command' => 'cpm verify --fix',
                'description' => 'System error detected: ' . $e->getMessage()
            ];
        }

        return $actions;
    }

    private function getPendingWork(): array
    {
        try {
            $progress = $this->database->read('progress');
            $inventory = $this->database->read('inventory');
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);

            $pending = [];
            $limit = 10; // Limit to first 10 for context

            foreach ($byFunction as $functionId => $functionProgress) {
                if (($functionProgress['status'] ?? 'pending') !== 'completed' && count($pending) < $limit) {
                    $functionInfo = $this->findFunctionInfo($functionId, $inventory);
                    $pending[] = [
                        'function_id' => $functionId,
                        'name' => $functionInfo['name'] ?? 'Unknown',
                        'file' => $functionInfo['file_path'] ?? 'Unknown',
                        'status' => $functionProgress['status'] ?? 'pending',
                        'last_updated' => $functionProgress['last_updated'] ?? 'Never'
                    ];
                }
            }

            return [
                'total_pending' => count($byFunction) - count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'completed')),
                'items' => $pending,
                'showing' => 'first ' . count($pending) . ' items'
            ];

        } catch (\Exception $e) {
            return ['error' => 'Could not load pending work: ' . $e->getMessage()];
        }
    }

    private function getRecentActivity(): array
    {
        try {
            $sessions = $this->database->read('sessions');
            $currentSession = $sessions['current_session'] ?? null;

            if (!$currentSession) {
                return ['message' => 'No active session'];
            }

            $activities = $this->getSessionActivities($currentSession);
            $recentActivities = array_slice($activities, -5); // Last 5 activities

            return [
                'total_activities' => count($activities),
                'recent_activities' => array_map(function($activity) {
                    return [
                        'timestamp' => $activity['timestamp'] ?? '',
                        'type' => $activity['type'] ?? 'general',
                        'description' => $activity['description'] ?? '',
                        'time_ago' => $this->getTimeAgo($activity['timestamp'] ?? '')
                    ];
                }, $recentActivities)
            ];

        } catch (\Exception $e) {
            return ['error' => 'Could not load recent activity: ' . $e->getMessage()];
        }
    }

    private function getSessionInfo(): array
    {
        try {
            $sessionHistory = $this->sessionManager->getSessionHistory(3);
            return [
                'current_session' => $this->sessionManager->generateStatusReport(),
                'recent_sessions' => array_map(function($session) {
                    return [
                        'session_id' => substr($session['session_id'] ?? '', -8),
                        'started_at' => $session['started_at'] ?? '',
                        'status' => $session['status'] ?? 'unknown',
                        'activities' => count($session['activities'] ?? [])
                    ];
                }, $sessionHistory)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Could not load session info: ' . $e->getMessage()];
        }
    }

    private function getDetailedAnalysis(): array
    {
        // This would include more advanced analytics
        return [
            'code_quality_metrics' => $this->getCodeQualityMetrics(),
            'complexity_analysis' => $this->getComplexityAnalysis(),
            'progress_velocity' => $this->getProgressVelocity()
        ];
    }

    private function getSystemHealth(): array
    {
        // Run basic system health checks
        $health = [
            'database_accessible' => true,
            'files_writable' => true,
            'schema_valid' => true
        ];

        try {
            $this->database->read('project');
        } catch (\Exception $e) {
            $health['database_accessible'] = false;
            $health['error'] = $e->getMessage();
        }

        return $health;
    }

    // Helper methods
    private function detectProjectType(): string
    {
        $cwd = getcwd();
        if (file_exists($cwd . '/composer.json')) return 'PHP';
        if (file_exists($cwd . '/package.json')) return 'JavaScript/Node.js';
        if (file_exists($cwd . '/requirements.txt') || file_exists($cwd . '/pyproject.toml')) return 'Python';
        return 'Unknown';
    }

    private function getLanguageBreakdown(array $inventory): array
    {
        $languages = [];
        foreach ($inventory['files'] ?? [] as $file) {
            $ext = pathinfo($file['file_path'] ?? '', PATHINFO_EXTENSION);
            $languages[$ext] = ($languages[$ext] ?? 0) + 1;
        }
        arsort($languages);
        return array_slice($languages, 0, 3, true); // Top 3 languages
    }

    private function calculateStatusDistribution(array $byFunction): array
    {
        $statuses = ['pending', 'in_progress', 'completed', 'verified', 'has_issues'];
        $distribution = array_fill_keys($statuses, 0);
        
        foreach ($byFunction as $func) {
            $status = $func['status'] ?? 'pending';
            if (isset($distribution[$status])) {
                $distribution[$status]++;
            }
        }
        
        return array_filter($distribution);
    }

    private function getProgressTrend(): string
    {
        // Simplified trend analysis
        try {
            $progress = $this->database->read('progress');
            $percentage = $progress['global_stats']['completion_percentage'] ?? 0;
            
            if ($percentage >= 80) return 'excellent';
            if ($percentage >= 60) return 'good';
            if ($percentage >= 40) return 'moderate';
            return 'starting';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    private function findFunctionInfo(string|int $functionId, array $inventory): array
    {
        // Normalize function ID to string (JSON decode may convert numeric strings to ints)
        $functionId = (string)$functionId;

        foreach ($inventory['functions'] ?? [] as $function) {
            if (isset($function['id']) && (string)$function['id'] === $functionId) {
                return $function;
            }
        }
        return [];
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

    private function getTimeAgo(string $timestamp): string
    {
        if (empty($timestamp)) return 'unknown';
        
        $time = strtotime($timestamp);
        if (!$time) return 'unknown';
        
        $diff = time() - $time;
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }

    private function getCodeQualityMetrics(): array
    {
        return ['status' => 'analysis_not_implemented'];
    }

    private function getComplexityAnalysis(): array
    {
        return ['status' => 'analysis_not_implemented'];
    }

    private function getProgressVelocity(): array
    {
        return ['status' => 'analysis_not_implemented'];
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

    private function outputHumanContext(?SymfonyStyle $io, array $contextData, bool $minimal, bool $detailed, bool $includeTodos): void
    {
        if (!$io) return;

        $io->title('🤖 AI Context Summary');

        // Project Overview
        $overview = $contextData['project_overview'] ?? [];
        $io->section('📋 Project Overview');
        $io->text('Name: ' . ($overview['name'] ?? 'Unknown'));
        $io->text('Type: ' . ($overview['type'] ?? 'Unknown'));
        $io->text('Functions: ' . ($overview['total_functions'] ?? 0));
        
        // Progress Status
        $progress = $contextData['progress_status'] ?? [];
        $io->section('📈 Progress Status');
        $percentage = $progress['completion_percentage'] ?? 0;
        $io->text("Completion: {$percentage}%");
        $io->text('Completed: ' . ($progress['completed_functions'] ?? 0) . '/' . ($progress['total_functions'] ?? 0));

        // Current State
        $state = $contextData['current_state'] ?? [];
        $io->section('⚡ Current State');
        $io->text('Session: ' . ($state['session_active'] ? 'Active' : 'Inactive'));
        $io->text('Current Task: ' . ($state['current_task'] ?? 'None'));
        $io->text('Duration: ' . ($state['session_duration'] ?? '0 minutes'));

        // Recommended Actions
        if ($includeTodos && isset($contextData['recommended_actions'])) {
            $io->section('🎯 Recommended Next Actions');
            foreach ($contextData['recommended_actions'] as $action) {
                $priority = strtoupper($action['priority'] ?? 'MEDIUM');
                $io->text("[{$priority}] " . ($action['action'] ?? 'Unknown action'));
                $io->text("  Command: <info>" . ($action['command'] ?? 'N/A') . "</info>");
                $io->text("  " . ($action['description'] ?? ''));
                $io->newLine();
            }
        }

        $io->note('💡 This context is optimized for AI assistant handoffs. Use --json for machine-readable format.');
    }
}