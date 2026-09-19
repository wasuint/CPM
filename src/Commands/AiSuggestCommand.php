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
 * AI-powered suggestion engine for next actions
 * Analyzes project state and recommends optimal next steps
 */
class AiSuggestCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;

    protected static $defaultName = 'ai-suggest';
    protected static $defaultDescription = 'Generate intelligent suggestions for next actions';

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
                'context',
                InputArgument::OPTIONAL,
                'Context for suggestions: workflow, debugging, optimization, completion',
                'workflow'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format (recommended for AI)'
            )
            ->addOption(
                'priority',
                'p',
                InputOption::VALUE_REQUIRED,
                'Filter suggestions by priority: high, medium, low, all',
                'all'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of suggestions to return',
                '5'
            )
            ->addOption(
                'include-commands',
                'c',
                InputOption::VALUE_NONE,
                'Include executable commands with suggestions'
            )
            ->setHelp('
Generate intelligent, context-aware suggestions for next development actions.

The AI suggestion engine analyzes your project state, progress, and patterns
to recommend the most effective next steps for your development workflow.

Context Types:
  workflow      General workflow optimization (default)
  debugging     Focus on fixing issues and problems  
  optimization  Performance and code quality improvements
  completion    Focus on completing pending work

Priority Levels:
  high          Critical actions that should be done immediately
  medium        Important actions for continued progress  
  low           Optional improvements and maintenance
  all           Show suggestions at all priority levels

Examples:
  cpm ai-suggest --json                           # General workflow suggestions
  cpm ai-suggest debugging --priority=high       # High-priority debugging actions
  cpm ai-suggest completion --limit=3 --json     # Top 3 completion suggestions
  cpm ai-suggest optimization --include-commands # Optimization with commands

Suggestions include:
- Contextual analysis of current state
- Prioritized action recommendations  
- Specific commands to execute
- Expected outcomes and benefits
- Time estimates where applicable
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $input->getArgument('context');
        $json = $this->isJsonRequested($input);
        $priority = $input->getOption('priority');
        $limit = (int) $input->getOption('limit');
        $includeCommands = (bool) $input->getOption('include-commands');
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            $suggestions = $this->generateSuggestions($context, $priority, $limit, $includeCommands);

            if ($json) {
                $this->outputJsonSuccess($output, 'ai-suggest', $suggestions, [
                    'context' => $context,
                    'priority_filter' => $priority,
                    'limit' => $limit,
                    'generated_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->outputHumanSuggestions($io, $suggestions, $context, $priority);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'ai-suggest', $e);
        }
    }

    private function generateSuggestions(string $context, string $priority, int $limit, bool $includeCommands): array
    {
        $projectState = $this->analyzeProjectState();
        
        $suggestions = match ($context) {
            'debugging' => $this->generateDebuggingSuggestions($projectState),
            'optimization' => $this->generateOptimizationSuggestions($projectState),
            'completion' => $this->generateCompletionSuggestions($projectState),
            default => $this->generateWorkflowSuggestions($projectState)
        };

        // Filter by priority
        if ($priority !== 'all') {
            $suggestions = array_filter($suggestions, fn($s) => $s['priority'] === $priority);
        }

        // Sort by priority and impact
        usort($suggestions, function($a, $b) {
            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            $impactOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            
            $aScore = ($priorityOrder[$a['priority']] ?? 0) * 10 + ($impactOrder[$a['impact']] ?? 0);
            $bScore = ($priorityOrder[$b['priority']] ?? 0) * 10 + ($impactOrder[$b['impact']] ?? 0);
            
            return $bScore - $aScore;
        });

        // Apply limit
        $suggestions = array_slice($suggestions, 0, $limit);

        // Add commands if requested
        if ($includeCommands) {
            foreach ($suggestions as &$suggestion) {
                if (!isset($suggestion['commands'])) {
                    $suggestion['commands'] = $this->generateCommandsForSuggestion($suggestion);
                }
            }
        }

        return [
            'context_analysis' => $projectState,
            'suggestions' => $suggestions,
            'total_suggestions' => count($suggestions),
            'context' => $context
        ];
    }

    private function analyzeProjectState(): array
    {
        $state = [
            'health' => 'unknown',
            'progress_percentage' => 0,
            'active_session' => false,
            'pending_count' => 0,
            'issues_count' => 0,
            'completion_velocity' => 'unknown'
        ];

        try {
            // Basic health check
            $this->database->read('project');
            $state['health'] = 'healthy';

            // Progress analysis
            $progress = $this->database->read('progress');
            $globalStats = $progress['global_stats'] ?? [];
            $state['progress_percentage'] = round($globalStats['completion_percentage'] ?? 0, 1);

            // Function status analysis
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);
            $state['pending_count'] = count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'pending'));
            $state['issues_count'] = count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'has_issues'));
            $state['in_progress_count'] = count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'in_progress'));

            // Session status
            $sessionReport = $this->sessionManager->generateStatusReport();
            $state['active_session'] = $sessionReport['status'] === 'active';
            $state['session_duration'] = $sessionReport['duration_minutes'];

            // Velocity analysis
            $state['completion_velocity'] = $this->calculateVelocity($globalStats, $byFunction);

        } catch (\Exception $e) {
            $state['health'] = 'unhealthy';
            $state['error'] = $e->getMessage();
        }

        return $state;
    }

    private function generateWorkflowSuggestions(array $projectState): array
    {
        $suggestions = [];

        // Health-based suggestions
        if ($projectState['health'] === 'unhealthy') {
            $suggestions[] = [
                'id' => 'fix_system_health',
                'title' => 'Fix System Health Issues',
                'description' => 'CPM system has health issues that need immediate attention',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'low',
                'commands' => ['cpm verify --json', 'cpm verify --fix'],
                'expected_outcome' => 'Restore system functionality and data integrity',
                'category' => 'system_maintenance'
            ];
        }

        // Progress-based suggestions  
        if ($projectState['progress_percentage'] < 20) {
            $suggestions[] = [
                'id' => 'initial_analysis',
                'title' => 'Complete Initial Project Analysis',
                'description' => 'Project analysis is in early stages. Focus on understanding codebase structure',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'medium',
                'commands' => ['cpm start --force', 'cpm status --detailed --json'],
                'expected_outcome' => 'Comprehensive project analysis and baseline establishment',
                'category' => 'initialization'
            ];
        } elseif ($projectState['progress_percentage'] >= 80) {
            $suggestions[] = [
                'id' => 'finalize_remaining',
                'title' => 'Finalize Remaining Work',
                'description' => 'Project is nearly complete. Focus on finishing remaining functions',
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'low',
                'commands' => ['cpm progress pending --json', 'cpm status --detailed'],
                'expected_outcome' => 'Project completion and final verification',
                'category' => 'completion'
            ];
        }

        // Pending work suggestions
        if ($projectState['pending_count'] > 10) {
            $suggestions[] = [
                'id' => 'batch_processing',
                'title' => 'Use Batch Operations for Efficiency',
                'description' => "You have {$projectState['pending_count']} pending functions. Consider batch operations",
                'priority' => 'medium',
                'impact' => 'high',
                'effort' => 'low',
                'commands' => ['cpm progress bulk-mark "*.php" --status=completed --json'],
                'expected_outcome' => 'Faster progress tracking and completion',
                'category' => 'efficiency'
            ];
        }

        // Session-based suggestions
        if (!$projectState['active_session']) {
            $suggestions[] = [
                'id' => 'start_monitoring',
                'title' => 'Enable Background Monitoring',
                'description' => 'Start background monitoring for automatic progress detection',
                'priority' => 'low',
                'impact' => 'medium',
                'effort' => 'low',
                'commands' => ['cpm monitor --daemon', 'cpm monitor --status'],
                'expected_outcome' => 'Automatic change detection and progress updates',
                'category' => 'automation'
            ];
        }

        return $suggestions;
    }

    private function generateDebuggingSuggestions(array $projectState): array
    {
        $suggestions = [];

        // System health debugging
        if ($projectState['health'] === 'unhealthy') {
            $suggestions[] = [
                'id' => 'diagnose_system_issues',
                'title' => 'Diagnose System Issues',
                'description' => 'Run comprehensive system diagnostics to identify problems',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'low',
                'commands' => ['cpm verify --json', 'cpm tools:fix-perms'],
                'expected_outcome' => 'Identification and resolution of system issues',
                'category' => 'diagnostics'
            ];
        }

        // Issues-based debugging
        if ($projectState['issues_count'] > 0) {
            $suggestions[] = [
                'id' => 'address_function_issues',
                'title' => 'Address Functions with Issues',
                'description' => "Focus on {$projectState['issues_count']} functions marked as having issues",
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'medium',
                'commands' => ['cpm progress show --json'],
                'expected_outcome' => 'Resolution of problematic functions',
                'category' => 'issue_resolution'
            ];
        }

        // Data consistency debugging
        $suggestions[] = [
            'id' => 'check_data_consistency',
            'title' => 'Verify Data Consistency',
            'description' => 'Check for data consistency issues between inventory and progress tracking',
            'priority' => 'medium',
            'impact' => 'medium',
            'effort' => 'low',
            'commands' => ['cpm progress verify --json'],
            'expected_outcome' => 'Identification of tracking inconsistencies',
            'category' => 'data_integrity'
        ];

        return $suggestions;
    }

    private function generateOptimizationSuggestions(array $projectState): array
    {
        $suggestions = [];

        // Performance optimization
        if ($projectState['progress_percentage'] > 0) {
            $suggestions[] = [
                'id' => 'optimize_batch_operations',
                'title' => 'Optimize with Batch Operations',
                'description' => 'Use bulk operations to improve progress tracking efficiency',
                'priority' => 'medium',
                'impact' => 'high',
                'effort' => 'low',
                'commands' => ['cpm progress bulk-mark "src/**/*.php" --status=completed'],
                'expected_outcome' => 'Faster progress updates and reduced overhead',
                'category' => 'performance'
            ];
        }

        // Workflow optimization
        $suggestions[] = [
            'id' => 'setup_automation',
            'title' => 'Setup Automated Monitoring',
            'description' => 'Configure background monitoring for hands-off progress tracking',
            'priority' => 'low',
            'impact' => 'medium',
            'effort' => 'low',
            'commands' => ['cpm monitor --daemon'],
            'expected_outcome' => 'Reduced manual intervention and automatic updates',
            'category' => 'automation'
        ];

        return $suggestions;
    }

    private function generateCompletionSuggestions(array $projectState): array
    {
        $suggestions = [];

        // Pending work completion
        if ($projectState['pending_count'] > 0) {
            $suggestions[] = [
                'id' => 'complete_pending_functions',
                'title' => 'Complete Pending Functions',
                'description' => "Focus on completing {$projectState['pending_count']} pending functions",
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'high',
                'commands' => ['cpm progress pending --json', 'cpm progress mark-complete <file>'],
                'expected_outcome' => 'Progress toward project completion',
                'category' => 'task_completion'
            ];
        }

        // In-progress work completion
        if ($projectState['in_progress_count'] > 0) {
            $suggestions[] = [
                'id' => 'finish_in_progress',
                'title' => 'Finish In-Progress Work',
                'description' => "Complete {$projectState['in_progress_count']} functions currently in progress",
                'priority' => 'high',
                'impact' => 'medium',
                'effort' => 'medium',
                'commands' => ['cpm status --detailed'],
                'expected_outcome' => 'Completion of partially finished work',
                'category' => 'task_completion'
            ];
        }

        // Final verification
        if ($projectState['progress_percentage'] >= 90) {
            $suggestions[] = [
                'id' => 'final_verification',
                'title' => 'Perform Final Verification',
                'description' => 'Run comprehensive verification before project completion',
                'priority' => 'high',
                'impact' => 'high',
                'effort' => 'low',
                'commands' => ['cpm verify --json', 'cpm ai-context --detailed --json'],
                'expected_outcome' => 'Confirmed project completion and final documentation',
                'category' => 'verification'
            ];
        }

        return $suggestions;
    }

    private function calculateVelocity(array $globalStats, array $byFunction): string
    {
        $percentage = $globalStats['completion_percentage'] ?? 0;
        $totalFunctions = $globalStats['total_functions'] ?? 0;
        
        if ($totalFunctions === 0) return 'no_data';
        if ($percentage === 0) return 'not_started';
        if ($percentage >= 80) return 'high';
        if ($percentage >= 40) return 'moderate';
        return 'slow';
    }

    private function generateCommandsForSuggestion(array $suggestion): array
    {
        // Generate appropriate commands based on suggestion category
        return match ($suggestion['category'] ?? 'general') {
            'system_maintenance' => ['cpm verify --json', 'cpm tools:fix-perms'],
            'initialization' => ['cmp start --force', 'cpm context:digest'],
            'completion' => ['cpm progress pending --json', 'cpm status --detailed'],
            'efficiency' => ['cpm progress bulk-mark "*.php" --status=completed'],
            'automation' => ['cpm monitor --daemon', 'cpm monitor --status'],
            'diagnostics' => ['cpm verify --json', 'cpm progress verify --json'],
            default => ['cpm help', 'cpm status --json']
        };
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

    private function outputHumanSuggestions(?SymfonyStyle $io, array $data, string $context, string $priority): void
    {
        if (!$io) return;

        $suggestions = $data['suggestions'] ?? [];
        $contextAnalysis = $data['context_analysis'] ?? [];

        $io->title("🤖 AI Suggestions - " . ucfirst($context) . " Context");

        // Context Analysis
        $io->section('📊 Current State Analysis');
        $io->text('System Health: ' . ucfirst($contextAnalysis['health'] ?? 'unknown'));
        $io->text('Progress: ' . ($contextAnalysis['progress_percentage'] ?? 0) . '%');
        $io->text('Pending Functions: ' . ($contextAnalysis['pending_count'] ?? 0));
        if (($contextAnalysis['issues_count'] ?? 0) > 0) {
            $io->text('⚠️  Functions with Issues: ' . $contextAnalysis['issues_count']);
        }

        // Suggestions
        $io->section('💡 Recommended Actions');
        
        if (empty($suggestions)) {
            $io->note('No suggestions available for the current context and priority level.');
            return;
        }

        foreach ($suggestions as $i => $suggestion) {
            $priorityIcon = match($suggestion['priority']) {
                'high' => '🔴',
                'medium' => '🟡',
                'low' => '🔵',
                default => '⚪'
            };

            $io->text("{$priorityIcon} " . ($suggestion['title'] ?? 'Unknown Suggestion'));
            $io->text('   ' . ($suggestion['description'] ?? ''));
            $io->text('   Impact: ' . ucfirst($suggestion['impact'] ?? 'unknown') . 
                     ' | Effort: ' . ucfirst($suggestion['effort'] ?? 'unknown'));
            
            if (!empty($suggestion['commands'])) {
                $io->text('   Commands: <info>' . implode(', ', $suggestion['commands']) . '</info>');
            }
            
            $io->newLine();
        }

        $io->note([
            '💡 Use --json for machine-readable format',
            '🔧 Use --include-commands to get executable commands',
            '📊 Suggestions are prioritized by impact and urgency'
        ]);
    }
}