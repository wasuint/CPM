<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Commands;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Intelligence\AiPriorityScorer;
use ClaudeProjectManager\Services\AdaptiveTrackingManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Suggest Command - AI Workflow Guidance
 *
 * Provides intelligent, actionable suggestions for AI assistants
 * based on project state, priority scoring, and adaptive tracking.
 */
class SuggestCommand extends Command
{
    private DatabaseManager $database;
    private AiPriorityScorer $priorityScorer;
    private AdaptiveTrackingManager $trackingManager;

    protected static $defaultName = 'suggest';
    protected static $defaultDescription = 'Get AI-optimized workflow suggestions';

    public function __construct(
        DatabaseManager $database,
        ?AiPriorityScorer $priorityScorer = null,
        ?AdaptiveTrackingManager $trackingManager = null
    ) {
        parent::__construct();
        $this->database = $database;
        $this->priorityScorer = $priorityScorer ?? new AiPriorityScorer();
        $this->trackingManager = $trackingManager ?? new AdaptiveTrackingManager();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output in JSON format for AI parsing'
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_REQUIRED,
                'Suggestion mode: autonomous, guided, review',
                'guided'
            )
            ->setHelp('
🤖 AI Workflow Suggestions

This command provides intelligent, context-aware suggestions specifically
optimized for AI assistant workflows (Claude Code, ChatGPT, Copilot, etc.).

Modes:
  autonomous - Suggestions for fully autonomous AI operation
  guided     - Suggestions for AI working with human guidance (default)
  review     - Suggestions for code review and quality checks

Output includes:
  • Top priority tasks with token estimates
  • Dependency-ordered action plan
  • Recommended tracking mode for project size
  • Executable commands for next steps
  • Success criteria and verification steps

Examples:
  cpm suggest                    # Get guided mode suggestions
  cpm suggest --json             # JSON output for AI parsing
  cpm suggest --mode=autonomous  # Full autonomous workflow
  cpm suggest --mode=review      # Code review focus
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json');
        $mode = $input->getOption('mode');

        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');
            $project = $this->database->read('project');

            $suggestions = $this->generateSuggestions($inventory, $progress, $project, $mode);

            if ($json) {
                $output->writeln(json_encode($suggestions, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }

            $this->displaySuggestions($io, $suggestions, $mode);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($json) {
                $output->writeln(json_encode(['error' => $e->getMessage()]));
            } else {
                $io->error('Failed to generate suggestions: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Generate AI workflow suggestions
     */
    private function generateSuggestions(
        array $inventory,
        array $progress,
        array $project,
        string $mode
    ): array {
        $totalFunctions = count($inventory['functions'] ?? []);
        $globalStats = $progress['global_stats'] ?? [];
        $completionPercentage = $globalStats['completion_percentage'] ?? 0;

        // Get priority functions
        $scoredFunctions = $this->priorityScorer->scoreFunctions($inventory, $progress);
        $topFunctions = $this->priorityScorer->getTopPriorityFunctions($scoredFunctions, 10, 50000);
        $groupedByFile = $this->priorityScorer->groupByFile($scoredFunctions);

        // Get tracking recommendations
        $trackingRec = $this->trackingManager->getAiRecommendations($totalFunctions);
        $trackingStats = $this->trackingManager->getTrackingStatistics(
            $inventory['functions'] ?? [],
            $trackingRec['recommended_mode']
        );

        // Generate mode-specific suggestions
        $tasks = $this->generateTasksForMode($mode, $topFunctions, $groupedByFile, $completionPercentage);

        return [
            'mode' => $mode,
            'project' => [
                'name' => $project['name'] ?? 'Unknown',
                'total_functions' => $totalFunctions,
                'completion_percentage' => $completionPercentage,
                'pending_functions' => $this->countPendingFunctions($progress)
            ],
            'tracking' => $trackingRec,
            'tracking_stats' => $trackingStats,
            'top_priority_functions' => array_slice($topFunctions['functions'], 0, 5),
            'priority_files' => array_slice($groupedByFile, 0, 5),
            'tasks' => $tasks,
            'workflow_tips' => $this->getWorkflowTips($mode, $completionPercentage),
            'commands' => $this->generateCommands($mode, $topFunctions)
        ];
    }

    /**
     * Generate tasks based on mode
     */
    private function generateTasksForMode(
        string $mode,
        array $topFunctions,
        array $groupedByFile,
        float $completionPercentage
    ): array {
        $tasks = [];

        switch ($mode) {
            case 'autonomous':
                $tasks = $this->generateAutonomousTasks($topFunctions, $groupedByFile, $completionPercentage);
                break;

            case 'review':
                $tasks = $this->generateReviewTasks($topFunctions, $groupedByFile, $completionPercentage);
                break;

            case 'guided':
            default:
                $tasks = $this->generateGuidedTasks($topFunctions, $groupedByFile, $completionPercentage);
                break;
        }

        return $tasks;
    }

    /**
     * Generate autonomous mode tasks
     */
    private function generateAutonomousTasks(array $topFunctions, array $groupedByFile, float $completion): array
    {
        $tasks = [];

        if ($completion < 10) {
            $tasks[] = [
                'priority' => 'high',
                'action' => 'Establish baseline documentation',
                'description' => 'Document project structure, identify entry points, map critical paths',
                'estimated_time' => '30-60 minutes',
                'functions_involved' => min(20, count($topFunctions['functions'] ?? [])),
                'success_criteria' => 'Core architecture documented, critical functions identified'
            ];
        }

        // Add top priority function tasks
        foreach (array_slice($topFunctions['functions'] ?? [], 0, 3) as $idx => $func) {
            $tasks[] = [
                'priority' => $func['priority_level'],
                'action' => sprintf('Work on %s', $func['name']),
                'description' => sprintf('File: %s - %s', basename($func['file']), $func['reason']),
                'estimated_tokens' => $func['estimated_tokens'],
                'estimated_time' => $this->estimateTime($func['estimated_tokens'], $func['complexity']),
                'success_criteria' => 'Function documented, typed, tested, complexity reviewed'
            ];
        }

        // File-based tasks
        if (!empty($groupedByFile)) {
            $topFile = $groupedByFile[0];
            $tasks[] = [
                'priority' => 'medium',
                'action' => sprintf('Complete file: %s', basename($topFile['file'])),
                'description' => sprintf('%d functions pending in this file', $topFile['count']),
                'estimated_time' => $this->estimateTime($topFile['total_tokens'], 0),
                'success_criteria' => 'All functions in file completed and verified'
            ];
        }

        return $tasks;
    }

    /**
     * Generate guided mode tasks
     */
    private function generateGuidedTasks(array $topFunctions, array $groupedByFile, float $completion): array
    {
        $tasks = [];

        // Progressive suggestions based on completion
        if ($completion < 25) {
            $tasks[] = [
                'priority' => 'high',
                'action' => 'Start with critical path functions',
                'description' => 'Focus on entry points and heavily-used utilities first',
                'command' => 'cpm status --focus --top 5',
                'success_criteria' => 'Critical functions documented and working'
            ];
        } elseif ($completion < 50) {
            $tasks[] = [
                'priority' => 'medium',
                'action' => 'Address high-complexity functions',
                'description' => 'Tackle complex functions that need refactoring or documentation',
                'command' => 'cpm status --ai-priority --top 10',
                'success_criteria' => 'Complex functions simplified or well-documented'
            ];
        } elseif ($completion < 75) {
            $tasks[] = [
                'priority' => 'medium',
                'action' => 'Complete remaining functions systematically',
                'description' => 'Work through pending functions file-by-file',
                'command' => 'cpm status --detailed',
                'success_criteria' => 'Steady progress toward 100% completion'
            ];
        } else {
            $tasks[] = [
                'priority' => 'low',
                'action' => 'Final quality pass',
                'description' => 'Review, refine, and verify all functions',
                'command' => 'cpm verify',
                'success_criteria' => '100% completion with quality checks passing'
            ];
        }

        // Add top priority task
        if (!empty($topFunctions['functions'])) {
            $topFunc = $topFunctions['functions'][0];
            $tasks[] = [
                'priority' => $topFunc['priority_level'],
                'action' => sprintf('Next function: %s', $topFunc['name']),
                'description' => $topFunc['reason'],
                'file' => basename($topFunc['file']),
                'estimated_tokens' => $topFunc['estimated_tokens']
            ];
        }

        return $tasks;
    }

    /**
     * Generate review mode tasks
     */
    private function generateReviewTasks(array $topFunctions, array $groupedByFile, float $completion): array
    {
        $tasks = [
            [
                'priority' => 'high',
                'action' => 'Review high-complexity functions',
                'description' => 'Check functions with complexity > 15 for refactoring opportunities',
                'command' => 'cpm status --ai-priority',
                'focus' => 'Complexity, maintainability, documentation'
            ],
            [
                'priority' => 'high',
                'action' => 'Verify type coverage',
                'description' => 'Ensure all functions have proper type declarations',
                'command' => 'cpm metrics',
                'focus' => 'Type hints, return types, parameter types'
            ],
            [
                'priority' => 'medium',
                'action' => 'Check documentation coverage',
                'description' => 'Verify all public methods have docblocks',
                'focus' => 'Docblocks, parameter descriptions, return descriptions'
            ],
            [
                'priority' => 'medium',
                'action' => 'Review critical path functions',
                'description' => 'Ensure entry points and core utilities are well-implemented',
                'focus' => 'Error handling, edge cases, performance'
            ]
        ];

        return $tasks;
    }

    /**
     * Get workflow tips for mode
     */
    private function getWorkflowTips(string $mode, float $completion): array
    {
        $tips = [
            'Run `cpm monitor` after completing each function to update progress',
            'Use `cpm status --focus` for a streamlined AI-optimized view',
            'Token estimates help plan work within AI context windows',
            'Functions are pre-sorted by priority and dependencies'
        ];

        if ($mode === 'autonomous') {
            $tips[] = 'Work systematically through top priority functions';
            $tips[] = 'Create checkpoints after completing major milestones';
            $tips[] = 'Use `cpm context:digest` to refresh AI context files';
        } elseif ($mode === 'review') {
            $tips[] = 'Focus on code quality metrics and standards compliance';
            $tips[] = 'Use `cpm metrics` to track quality improvements';
        }

        if ($completion > 75) {
            $tips[] = 'Consider running `cpm verify` for final quality checks';
        }

        return $tips;
    }

    /**
     * Generate executable commands
     */
    private function generateCommands(string $mode, array $topFunctions): array
    {
        $commands = [
            [
                'command' => 'cpm status --focus --top 5',
                'description' => 'View top 5 priority functions',
                'when_to_use' => 'When starting work or choosing next task'
            ],
            [
                'command' => 'cpm monitor',
                'description' => 'Update progress after completing work',
                'when_to_use' => 'After editing any files'
            ],
            [
                'command' => 'cpm context:digest',
                'description' => 'Refresh AI context files',
                'when_to_use' => 'After significant progress or when context feels stale'
            ]
        ];

        if ($mode === 'autonomous') {
            $commands[] = [
                'command' => 'cpm checkpoint:create',
                'description' => 'Save progress checkpoint',
                'when_to_use' => 'After completing major milestones'
            ];
        }

        if ($mode === 'review') {
            $commands[] = [
                'command' => 'cpm metrics',
                'description' => 'View code quality metrics',
                'when_to_use' => 'When checking progress on quality improvements'
            ];
        }

        return $commands;
    }

    /**
     * Display suggestions in human-readable format
     */
    private function displaySuggestions(SymfonyStyle $io, array $suggestions, string $mode): void
    {
        $io->title(sprintf('🤖 AI Workflow Suggestions (%s mode)', $mode));

        // Project status
        $io->section('📊 Project Status');
        $project = $suggestions['project'];
        $io->text([
            sprintf('Project: %s', $project['name']),
            sprintf('Progress: %.1f%% complete', $project['completion_percentage']),
            sprintf('Functions: %d total, %d pending', $project['total_functions'], $project['pending_functions']),
            ''
        ]);

        // Tracking recommendation
        $tracking = $suggestions['tracking'];
        $trackingStats = $suggestions['tracking_stats'];
        $io->section('⚙️  Recommended Tracking Mode');
        $io->text([
            sprintf('Mode: %s', $tracking['recommended_mode']),
            sprintf('Reason: %s', $tracking['reason']),
            sprintf('Tracks %d of %d functions (%.1f%% reduction)',
                $trackingStats['tracked_functions'],
                $trackingStats['total_functions'],
                $trackingStats['reduction_percentage']
            ),
            sprintf('Estimated token savings: ~%d tokens', $trackingStats['estimated_token_savings']),
            ''
        ]);

        // Tasks
        $io->section('📋 Recommended Tasks');
        foreach ($suggestions['tasks'] as $idx => $task) {
            $io->writeln(sprintf(
                '%d. [%s] %s',
                $idx + 1,
                strtoupper($task['priority']),
                $task['action']
            ));
            $io->writeln(sprintf('   %s', $task['description']));
            if (isset($task['command'])) {
                $io->writeln(sprintf('   Command: %s', $task['command']));
            }
            if (isset($task['estimated_time'])) {
                $io->writeln(sprintf('   Estimated time: %s', $task['estimated_time']));
            }
            $io->writeln('');
        }

        // Top priority functions
        if (!empty($suggestions['top_priority_functions'])) {
            $io->section('🎯 Top Priority Functions');
            foreach ($suggestions['top_priority_functions'] as $idx => $func) {
                $io->writeln(sprintf(
                    '%d. %s (%s) - %s',
                    $idx + 1,
                    $func['name'],
                    $func['priority_level'],
                    basename($func['file'])
                ));
            }
            $io->writeln('');
        }

        // Commands
        $io->section('💻 Useful Commands');
        foreach ($suggestions['commands'] as $cmd) {
            $io->writeln(sprintf('• %s', $cmd['command']));
            $io->writeln(sprintf('  %s', $cmd['description']));
            $io->writeln('');
        }

        // Tips
        $io->section('💡 Workflow Tips');
        foreach ($suggestions['workflow_tips'] as $tip) {
            $io->writeln('• ' . $tip);
        }
    }

    /**
     * Estimate time based on tokens and complexity
     */
    private function estimateTime(int $tokens, int $complexity): string
    {
        // Rough heuristic: 1000 tokens ≈ 5 minutes for AI
        $minutes = max(5, ($tokens / 1000) * 5 + ($complexity * 2));

        if ($minutes < 15) {
            return '5-15 minutes';
        } elseif ($minutes < 30) {
            return '15-30 minutes';
        } elseif ($minutes < 60) {
            return '30-60 minutes';
        } else {
            return '1+ hours';
        }
    }

    /**
     * Count pending functions
     */
    private function countPendingFunctions(array $progress): int
    {
        $count = 0;
        $byFunction = $progress['by_function'] ?? [];

        if (is_object($byFunction)) {
            $byFunction = (array)$byFunction;
        }

        foreach ($byFunction as $functionProgress) {
            if (is_object($functionProgress)) {
                $functionProgress = (array)$functionProgress;
            }
            if (($functionProgress['status'] ?? 'pending') === 'pending') {
                $count++;
            }
        }

        return $count;
    }
}