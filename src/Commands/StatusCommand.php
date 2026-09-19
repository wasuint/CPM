<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\SessionManager;
use ClaudeProjectManager\Progress\MetricsCalculator;
use ClaudeProjectManager\Intelligence\AiPriorityScorer;
use ClaudeProjectManager\Services\HealthChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Status command that displays current project progress and metrics
 */
class StatusCommand extends Command
{
    private DatabaseManager $database;
    private SessionManager $sessionManager;
    private MetricsCalculator $metricsCalculator;
    private AiPriorityScorer $aiPriorityScorer;
    private string $projectRoot;

    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Display current project status and progress';

    public function __construct(
        DatabaseManager $database,
        SessionManager $sessionManager,
        MetricsCalculator $metricsCalculator,
        AiPriorityScorer $aiPriorityScorer = null
    ) {
        parent::__construct();
        $this->database = $database;
        $this->sessionManager = $sessionManager;
        $this->metricsCalculator = $metricsCalculator;
        $this->aiPriorityScorer = $aiPriorityScorer ?? new AiPriorityScorer();
        
        // Get project root from database
        try {
            $project = $this->database->read('project');
            $this->projectRoot = $project['root_path'] ?? getcwd();
        } catch (\Exception $e) {
            $this->projectRoot = getcwd();
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'detailed',
                'd',
                InputOption::VALUE_NONE,
                'Show detailed function-level progress'
            )
            ->addOption(
                'history',
                null,
                InputOption::VALUE_NONE,
                'Show session history'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON for CI/integration'
            )
            ->addOption(
                'health',
                null,
                InputOption::VALUE_NONE,
                'Run comprehensive health checks on CPM data'
            )
            ->addOption(
                'summary',
                's',
                InputOption::VALUE_NONE,
                'Show lightweight summary (faster)'
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Filter functions by file path (supports partial matching)'
            )
            ->addOption(
                'top',
                't',
                InputOption::VALUE_REQUIRED,
                'Show top N priority functions for AI (requires --ai-priority)'
            )
            ->addOption(
                'focus',
                null,
                InputOption::VALUE_NONE,
                'Show AI-focused view with top priority functions and task recommendations'
            )
            ->addOption(
                'ai-priority',
                null,
                InputOption::VALUE_NONE,
                'Calculate and display AI-optimized priority scores for functions'
            )
            ->setHelp('
This command displays the current status of your project including:
- Overall progress statistics
- Function completion status
- Session information
- Recent activity
- Performance metrics

Use --detailed to see function-level breakdown.
Use --history to view session history.
Use --file to filter functions by file path (e.g., --file data_manager.py).
Use --focus for AI-optimized view with priority recommendations.
Use --ai-priority to calculate priority scores for functions.
Use --top N with --ai-priority to show top N priority functions.
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $detailed = $input->getOption('detailed');
        $history = $input->getOption('history');
        $json = $input->getOption('json');
        $health = $input->getOption('health');
        $fileFilter = $input->getOption('file');
        $aiPriority = $input->getOption('ai-priority');
        $focus = $input->getOption('focus');
        $top = $input->getOption('top');
        $verbose = $output->isVerbose();

        try {
            // Verbose: Show what we're doing
            if ($verbose && !$json) {
                $io->section('🔍 Loading CPM data...');
                $io->text('Database path: ' . $this->database->getDatabasePath());
            }

            // Health check mode
            if ($health) {
                return $this->runHealthChecks($input, $output);
            }

            // If JSON output requested, generate and output JSON instead of formatted display
            if ($json) {
                $payload = $this->generateJsonPayload($aiPriority, $top ? (int)$top : null);
                $output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return Command::SUCCESS;
            }

            // AI-focused view (streamlined for AI assistants)
            if ($focus) {
                return $this->displayAiFocusView($io, $top ? (int)$top : 10);
            }

            if ($verbose && !$json) {
                $io->text('✅ Data loaded successfully');
                $io->newLine();
            }

            $io->title('Claude Project Manager - Status Report');

            // Project overview
            $this->displayProjectOverview($io);

            // Progress summary
            $this->displayProgressSummary($io);

            // Current session info
            $this->displayCurrentSession($io);

            // Recent activity
            $this->displayRecentActivity($io);

            // AI Priority view
            if ($aiPriority) {
                $this->displayAiPriorityFunctions($io, $top ? (int)$top : 20);
            }

            if ($detailed) {
                $this->displayDetailedProgress($io, $fileFilter);
            }

            if ($history) {
                $this->displaySessionHistory($io);
            }

            // Display Claude Code guidance (only for non-JSON output)
            if (!$json) {
                $this->displayClaudeGuidance($io);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode(['error' => $e->getMessage()]));
            } else {
                $io->error('Failed to generate status report: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    private function displayProjectOverview(SymfonyStyle $io): void
    {
        try {
            $project = $this->database->read('project');
            $inventory = $this->database->read('inventory');

            $io->section('Project Overview');
            
            $io->table(
                ['Property', 'Value'],
                [
                    ['Project Name', $project['name'] ?? 'Unknown'],
                    ['Root Path', $project['root_path'] ?? 'Unknown'],
                    ['Initialized', $project['initialized_at'] ?? 'Unknown'],
                    ['Total Files', $this->safeCount($inventory['files'] ?? [])],
                    ['Total Functions', $this->safeCount($inventory['functions'] ?? [])],
                    ['Total Classes', $this->safeCount($inventory['classes'] ?? [])],
                    ['Last Analysis', $project['last_analysis'] ?? 'Never']
                ]
            );

        } catch (\Exception $e) {
            $io->warning('Could not load project overview: ' . $e->getMessage());
        }
    }

    private function displayProgressSummary(SymfonyStyle $io): void
    {
        try {
            $progress = $this->database->read('progress');
            $globalStats = $progress['global_stats'] ?? [];

            $io->section('Progress Summary');

            $totalFunctions = $globalStats['total_functions'] ?? 0;
            $completedFunctions = $globalStats['completed_functions'] ?? 0;
            $completionPercentage = $globalStats['completion_percentage'] ?? 0;

            // Calculate status distribution
            $statusCounts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'verified' => 0, 'has_issues' => 0];
            foreach ($this->getByFunctionArray($progress['by_function'] ?? []) as $functionProgress) {
                $status = $functionProgress['status'] ?? 'pending';
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
            }

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Functions', $totalFunctions],
                    ['Completed', $completedFunctions],
                    ['Completion %', round($completionPercentage, 1) . '%'],
                    ['Pending', $statusCounts['pending']],
                    ['In Progress', $statusCounts['in_progress']],
                    ['Has Issues', $statusCounts['has_issues']],
                    ['Verified', $statusCounts['verified']],
                    ['Last Updated', $globalStats['last_updated'] ?? 'Never']
                ]
            );

            // Progress bar
            $progressBar = $io->createProgressBar(100);
            $progressBar->setProgress((int)$completionPercentage);
            $progressBar->setMessage(sprintf('%.1f%% Complete', $completionPercentage));
            $progressBar->display();
            $io->newLine(2);

        } catch (\Exception $e) {
            $io->warning('Could not load progress summary: ' . $e->getMessage());
        }
    }

    private function displayCurrentSession(SymfonyStyle $io): void
    {
        try {
            $statusReport = $this->sessionManager->generateStatusReport();

            $io->section('Current Session');
            
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Session ID', substr($statusReport['session_id'], -12)],
                    ['Duration', $statusReport['duration_minutes'] . ' minutes'],
                    ['Activities', $statusReport['activities_count']],
                    ['Status', $statusReport['status']],
                    ['Current Task', $statusReport['current_task']],
                    ['Checkpoints', $statusReport['checkpoints']],
                    ['Last Activity', $statusReport['last_activity'] ?? 'None']
                ]
            );

        } catch (\Exception $e) {
            $io->warning('Could not load session information: ' . $e->getMessage());
        }
    }

    private function displayRecentActivity(SymfonyStyle $io): void
    {
        try {
            $sessions = $this->database->read('sessions');
            $currentSession = $sessions['current_session'] ?? null;

            if (!$currentSession || empty($this->getSessionActivities($currentSession))) {
                return;
            }

            $io->section('Recent Activity');
            
            $recentActivities = array_slice($this->getSessionActivities($currentSession), -5);
            $activities = [];
            
            foreach ($recentActivities as $activity) {
                $activities[] = [
                    date('H:i:s', strtotime($activity['timestamp'])),
                    $activity['type'] ?? 'general',
                    $activity['description']
                ];
            }

            $io->table(['Time', 'Type', 'Description'], $activities);

        } catch (\Exception $e) {
            $io->warning('Could not load recent activity: ' . $e->getMessage());
        }
    }

    private function displayDetailedProgress(SymfonyStyle $io, string $fileFilter = null): void
    {
        try {
            $progress = $this->database->read('progress');
            $inventory = $this->database->read('inventory');

            $sectionTitle = 'Detailed Function Progress';
            if ($fileFilter !== null) {
                $sectionTitle .= sprintf(' (filtered by: %s)', $fileFilter);
            }
            $io->section($sectionTitle);

            $functions = [];
            foreach ($this->getByFunctionArray($progress['by_function'] ?? []) as $functionId => $functionProgress) {
                // Find function info by searching through the functions array
                $functionInfo = null;
                foreach ($inventory['functions'] ?? [] as $function) {
                    if (isset($function['id']) && $function['id'] == $functionId) {
                        $functionInfo = $function;
                        break;
                    }
                }
                $functionInfo = $functionInfo ?? [];
                
                // Apply file filter if specified
                if ($fileFilter !== null) {
                    $filePath = $functionInfo['file_path'] ?? '';
                    if (stripos($filePath, $fileFilter) === false) {
                        continue; // Skip this function if it doesn't match the file filter
                    }
                }
                
                $functions[] = [
                    substr((string)$functionId, -12),
                    $functionInfo['name'] ?? 'Unknown',
                    $functionInfo['file_path'] ?? 'Unknown',
                    $functionProgress['status'] ?? 'pending',
                    $functionProgress['last_updated'] ?? 'Never'
                ];
            }

            // Sort by status
            usort($functions, function($a, $b) {
                $statusOrder = ['in_progress' => 0, 'has_issues' => 1, 'pending' => 2, 'completed' => 3, 'verified' => 4];
                return ($statusOrder[$a[3]] ?? 5) - ($statusOrder[$b[3]] ?? 5);
            });

            $io->table(
                ['ID', 'Function', 'File', 'Status', 'Updated'],
                array_slice($functions, 0, 20) // Limit to first 20
            );

            if (empty($functions) && $fileFilter !== null) {
                $io->warning(sprintf('No functions found matching file filter: %s', $fileFilter));
            } elseif (count($functions) > 20) {
                $totalMessage = sprintf('Showing first 20 of %d functions', count($functions));
                if ($fileFilter !== null) {
                    $totalMessage .= sprintf(' (filtered by: %s)', $fileFilter);
                } else {
                    $totalMessage .= '. Use --file filter for specific files.';
                }
                $io->note($totalMessage);
            } elseif ($fileFilter !== null && !empty($functions)) {
                $io->info(sprintf('Found %d function(s) in files matching: %s', count($functions), $fileFilter));
            }

        } catch (\Exception $e) {
            $io->warning('Could not load detailed progress: ' . $e->getMessage());
        }
    }

    private function displaySessionHistory(SymfonyStyle $io): void
    {
        try {
            $sessionHistory = $this->sessionManager->getSessionHistory(10);

            if (empty($sessionHistory)) {
                $io->note('No session history available.');
                return;
            }

            $io->section('Session History');

            $sessions = [];
            foreach ($sessionHistory as $session) {
                $duration = 0;
                if (isset($session['started_at']) && isset($session['ended_at'])) {
                    $duration = (strtotime($session['ended_at']) - strtotime($session['started_at'])) / 60;
                }

                $sessions[] = [
                    substr((string)($session['session_id'] ?? ''), -12),
                    date('Y-m-d H:i', strtotime($session['started_at'])),
                    $session['status'] ?? 'unknown',
                    round($duration, 1) . 'm',
                    count($session['activities'] ?? [])
                ];
            }

            $io->table(
                ['Session ID', 'Started', 'Status', 'Duration', 'Activities'],
                $sessions
            );

        } catch (\Exception $e) {
            $io->warning('Could not load session history: ' . $e->getMessage());
        }
    }

    /**
     * Safely get by_function data as array regardless of storage format
     */
    private function getByFunctionArray($byFunction): array
    {
        // Handle object (stdClass) access
        if (is_object($byFunction)) {
            return (array) $byFunction;
        }
        
        // Handle array access
        if (is_array($byFunction)) {
            return $byFunction;
        }
        
        return [];
    }

    /**
     * Safely get session activities from current session
     */
    private function getSessionActivities($currentSession): array
    {
        // Handle object (stdClass) access
        if (is_object($currentSession)) {
            return isset($currentSession->activities) ? (array) $currentSession->activities : [];
        }
        
        // Handle array access
        if (is_array($currentSession)) {
            return $currentSession['activities'] ?? [];
        }
        
        return [];
    }

    /**
     * Display AI-optimized priority functions
     */
    private function displayAiPriorityFunctions(SymfonyStyle $io, int $limit): void
    {
        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');

            $scoredFunctions = $this->aiPriorityScorer->scoreFunctions($inventory, $progress);
            $topFunctions = $this->aiPriorityScorer->getTopPriorityFunctions($scoredFunctions, $limit);

            $io->section('🤖 AI Priority Functions');

            if (empty($topFunctions['functions'])) {
                $io->success('All functions completed! No pending work.');
                return;
            }

            $io->text([
                sprintf('Showing top %d priority functions (Total tokens: ~%d)',
                    $topFunctions['count'],
                    $topFunctions['total_tokens']
                ),
                ''
            ]);

            $tableData = [];
            foreach ($topFunctions['functions'] as $function) {
                $tableData[] = [
                    substr($function['name'], 0, 30),
                    $this->truncatePath($function['file'], 40),
                    $function['priority_level'],
                    number_format($function['score'], 1),
                    $function['complexity'],
                    number_format($function['estimated_tokens']),
                    $this->truncate($function['reason'], 50)
                ];
            }

            $io->table(
                ['Function', 'File', 'Priority', 'Score', 'Complex', 'Tokens', 'Reason'],
                $tableData
            );

            $io->note([
                '💡 AI Workflow Tips:',
                '  • Start with "critical" priority functions for maximum impact',
                '  • Functions are pre-sorted by dependency and complexity',
                '  • Token estimates help plan AI context window usage',
                '  • Use --focus for streamlined AI-optimized view'
            ]);

        } catch (\Exception $e) {
            $io->warning('Could not calculate AI priorities: ' . $e->getMessage());
        }
    }

    /**
     * Display AI-focused view (streamlined for AI consumption)
     */
    private function displayAiFocusView(SymfonyStyle $io, int $limit): int
    {
        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');
            $project = $this->database->read('project');

            $io->title('🤖 AI Focus View');

            // Quick stats
            $globalStats = $progress['global_stats'] ?? [];
            $completionPercentage = $globalStats['completion_percentage'] ?? 0;

            $io->text([
                sprintf('Project: %s', $project['name'] ?? 'Unknown'),
                sprintf('Progress: %.1f%% complete', $completionPercentage),
                sprintf('Functions: %d total, %d pending',
                    $globalStats['total_functions'] ?? 0,
                    $this->countPendingFunctions($progress)
                ),
                ''
            ]);

            // Get priority functions
            $scoredFunctions = $this->aiPriorityScorer->scoreFunctions($inventory, $progress);
            $topFunctions = $this->aiPriorityScorer->getTopPriorityFunctions($scoredFunctions, $limit, 50000);

            $io->section('📋 Top Priority Tasks');

            if (empty($topFunctions['functions'])) {
                $io->success('✅ All functions completed!');
                return Command::SUCCESS;
            }

            $taskNumber = 1;
            foreach ($topFunctions['functions'] as $function) {
                $io->writeln(sprintf(
                    '%d. [%s] %s',
                    $taskNumber++,
                    strtoupper($function['priority_level']),
                    $function['name']
                ));
                $io->writeln(sprintf(
                    '   File: %s',
                    $this->makeRelativePathForDisplay($function['file'])
                ));
                $io->writeln(sprintf(
                    '   Reason: %s',
                    $function['reason']
                ));
                $io->writeln(sprintf(
                    '   Estimated tokens: ~%d | Complexity: %d',
                    $function['estimated_tokens'],
                    $function['complexity']
                ));
                $io->writeln('');
            }

            $io->section('📊 Quick Stats');
            $io->text([
                sprintf('Total tokens for top %d functions: ~%d', $limit, $topFunctions['total_tokens']),
                sprintf('Recommended approach: Start with task #1 (highest priority)'),
                ''
            ]);

            $io->note([
                '🎯 AI Workflow Recommendations:',
                '  1. Work through tasks in order (already dependency-sorted)',
                '  2. Complete one function before moving to the next',
                '  3. Run `cpm monitor` after each function to update progress',
                '  4. Use `cpm status --focus` again to see updated priorities'
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to generate AI focus view: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Count pending functions
     */
    private function countPendingFunctions(array $progress): int
    {
        $count = 0;
        foreach ($this->getByFunctionArray($progress['by_function'] ?? []) as $functionProgress) {
            if (($functionProgress['status'] ?? 'pending') === 'pending') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Truncate string with ellipsis
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Truncate file path intelligently
     */
    private function truncatePath(string $path, int $length): string
    {
        if (strlen($path) <= $length) {
            return $path;
        }

        // Try to keep filename
        $basename = basename($path);
        if (strlen($basename) < $length - 10) {
            return '...' . substr($path, -(($length - 3)));
        }

        return $this->truncate($path, $length);
    }

    /**
     * Make path relative for display
     */
    private function makeRelativePathForDisplay(string $path): string
    {
        if (str_starts_with($path, $this->projectRoot)) {
            return substr($path, strlen($this->projectRoot) + 1);
        }
        return $path;
    }

    /**
     * Generate JSON payload for machine-readable output
     */
    private function generateJsonPayload(bool $includeAiPriority = false, ?int $topN = null): array
    {
        $overview = [];
        $summary = [];
        $session = [];

        try {
            // Project overview
            $project = $this->database->read('project');
            $inventory = $this->database->read('inventory');
            $overview = [
                'ProjectName' => $project['name'] ?? 'Unknown',
                'RootPath' => $project['root_path'] ?? 'Unknown',
                'Initialized' => $project['initialized_at'] ?? 'Unknown',
                'TotalFiles' => $this->safeCount($inventory['files'] ?? []),
                'TotalFunctions' => $this->safeCount($inventory['functions'] ?? []),
                'TotalClasses' => $this->safeCount($inventory['classes'] ?? []),
                'LastAnalysis' => $project['last_analysis'] ?? 'Never'
            ];

            // Progress summary
            $progress = $this->database->read('progress');
            $globalStats = $progress['global_stats'] ?? [];
            $statusCounts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'verified' => 0, 'has_issues' => 0];
            foreach ($this->getByFunctionArray($progress['by_function'] ?? []) as $functionProgress) {
                $status = $functionProgress['status'] ?? 'pending';
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
            }
            $summary = [
                'TotalFunctions' => $globalStats['total_functions'] ?? 0,
                'CompletedFunctions' => $globalStats['completed_functions'] ?? 0,
                'CompletionPercentage' => round($globalStats['completion_percentage'] ?? 0, 1),
                'StatusCounts' => $statusCounts,
                'LastUpdated' => $globalStats['last_updated'] ?? 'Never'
            ];

            // Session info
            $statusReport = $this->sessionManager->generateStatusReport();
            $session = [
                'SessionId' => substr($statusReport['session_id'], -12),
                'DurationMinutes' => $statusReport['duration_minutes'],
                'ActivitiesCount' => $statusReport['activities_count'],
                'Status' => $statusReport['status'],
                'CurrentTask' => $statusReport['current_task'],
                'Checkpoints' => $statusReport['checkpoints'],
                'LastActivity' => $statusReport['last_activity'] ?? 'None'
            ];
        } catch (\Exception $e) {
            // Include error in output but continue
            $overview['error'] = $e->getMessage();
        }

        $payload = [
            'overview' => $overview,
            'summary' => $summary,
            'session' => $session
        ];

        // Add AI priority data if requested
        if ($includeAiPriority) {
            try {
                $inventory = $this->database->read('inventory');
                $progress = $this->database->read('progress');
                $scoredFunctions = $this->aiPriorityScorer->scoreFunctions($inventory, $progress);
                $topFunctions = $this->aiPriorityScorer->getTopPriorityFunctions(
                    $scoredFunctions,
                    $topN ?? 20,
                    50000
                );

                $payload['ai_priority'] = [
                    'total_scored' => count($scoredFunctions),
                    'top_functions' => $topFunctions['functions'],
                    'total_tokens' => $topFunctions['total_tokens'],
                    'count' => $topFunctions['count']
                ];
            } catch (\Exception $e) {
                $payload['ai_priority'] = ['error' => $e->getMessage()];
            }
        }

        return $payload;
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
     * Display guidance for Claude Code to maintain project documentation
     */
    private function displayClaudeGuidance(SymfonyStyle $io): void
    {
        try {
            $claudeMdPath = $this->projectRoot . '/.claude.md';
            $contextFiles = [
                '.claude.md' => filesize($claudeMdPath) ?: 0,
                '.cpm/context/DIGEST.md' => filesize($this->projectRoot . '/.cpm/context/DIGEST.md') ?: 0,
                '.cpm/context/STATE.json' => filesize($this->projectRoot . '/.cpm/context/STATE.json') ?: 0
            ];

            $needsUpdate = false;
            $needsCompaction = false;
            $messages = [];

            // Check if .claude.md exists and its age
            if (file_exists($claudeMdPath)) {
                $lastModified = filemtime($claudeMdPath);
                $hoursSinceUpdate = (time() - $lastModified) / 3600;
                
                if ($hoursSinceUpdate > 24) {
                    $needsUpdate = true;
                    $messages[] = sprintf(
                        '📝 .claude.md last updated %.1f hours ago - consider updating with new project insights',
                        $hoursSinceUpdate
                    );
                }

                // Check file sizes for compaction needs
                foreach ($contextFiles as $file => $size) {
                    if ($size > 50000) { // 50KB threshold
                        $needsCompaction = true;
                        $messages[] = sprintf(
                            '📦 %s is %.1fKB - consider compacting to essential information',
                            $file,
                            $size / 1024
                        );
                    }
                }
            } else {
                $needsUpdate = true;
                $messages[] = '🚀 .claude.md not found - create it to document project structure and rules';
            }

            // Check project progress for documentation updates
            $progress = $this->database->read('progress');
            $completion = $progress['global_stats']['completion_percentage'] ?? 0;
            $lastProgressUpdate = $progress['global_stats']['last_updated'] ?? null;
            
            if ($completion > 25 && $needsUpdate) {
                $messages[] = sprintf(
                    '🎯 Project %.1f%% complete - update .claude.md with discovered patterns and conventions',
                    $completion
                );
            }

            // Display guidance if needed
            if (!empty($messages)) {
                $io->section('📚 Claude Code Documentation Guidance');
                
                foreach ($messages as $message) {
                    $io->writeln(' ' . $message);
                }

                $io->writeln('');
                $io->note([
                    'Claude Code: Use these reminders to maintain project documentation:',
                    '1. Update .claude.md when you discover new patterns or conventions',
                    '2. Compact large files by summarizing verbose content',
                    '3. Keep context files focused on essential project knowledge',
                    '4. Run "cpm context:digest" to refresh context'
                ]);
            }

            // Check for optimal workflow
            $this->checkWorkflowOptimization($io);

        } catch (\Exception $e) {
            // Silent fail - guidance is optional
        }
    }

    /**
     * Check and suggest workflow optimizations
     */
    private function checkWorkflowOptimization(SymfonyStyle $io): void
    {
        try {
            $sessions = $this->database->read('sessions');
            $currentSession = $sessions['current_session'] ?? null;
            
            if (!$currentSession) {
                return;
            }

            $suggestions = [];
            
            // Check session duration - use started_at instead of startTime
            if (isset($currentSession['started_at'])) {
                $startTime = strtotime($currentSession['started_at']);
                if ($startTime !== false) {
                    $duration = (time() - $startTime) / 3600;
                    if ($duration > 4) {
                        $suggestions[] = 'Consider creating a checkpoint to save progress: cpm checkpoint:create';
                    }
                }
            }

            // Check for monitoring status
            $monitoringActive = file_exists($this->projectRoot . '/.cpm/logs/monitor.pid');
            if (!$monitoringActive) {
                $suggestions[] = 'Enable monitoring for automatic progress tracking: cpm monitor --daemon';
            }

            if (!empty($suggestions)) {
                $io->section('💡 Workflow Optimization Tips');
                foreach ($suggestions as $suggestion) {
                    $io->writeln(' • ' . $suggestion);
                }
            }

        } catch (\Exception $e) {
            // Silent fail
        }
    }

    /**
     * Run comprehensive health checks on CPM data
     */
    private function runHealthChecks(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');

        $healthChecker = new HealthChecker($this->database);
        $checks = $healthChecker->runAllChecks();
        $isHealthy = $healthChecker->isHealthy($checks);
        $summary = $healthChecker->getSummary($checks);

        if ($json) {
            $output->writeln(json_encode([
                'success' => $isHealthy,
                'checks' => $checks,
                'summary' => $summary,
                'overall_status' => $isHealthy ? 'healthy' : 'unhealthy'
            ], JSON_PRETTY_PRINT));
        } else {
            $this->displayHealthReport($io, $checks, $summary);
        }

        return $isHealthy ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Display health check report
     */
    private function displayHealthReport(SymfonyStyle $io, array $checks, array $summary): void
    {
        $io->title('CPM Health Check Report');

        foreach ($checks as $check) {
            $icon = match($check['status']) {
                'ok' => '✅',
                'warning' => '⚠️',
                'error' => '❌',
                default => 'ℹ️'
            };

            $io->section($icon . ' ' . $check['name']);
            $io->text($check['message']);

            if ($check['fix']) {
                $io->note('Fix: ' . $check['fix']);
            }
        }

        $io->newLine();

        if ($summary['error_count'] === 0 && $summary['warning_count'] === 0) {
            $io->success('All checks passed! CPM is healthy.');
        } else {
            $io->warning(sprintf(
                'Health check found %d error(s) and %d warning(s)',
                $summary['error_count'],
                $summary['warning_count']
            ));
        }
    }
}