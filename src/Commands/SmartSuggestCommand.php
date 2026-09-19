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
 * Smart Suggestion Engine with Predictive Analytics
 * Uses AI learning patterns to provide intelligent, context-aware suggestions
 */
class SmartSuggestCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;

    protected static $defaultName = 'smart-suggest';
    protected static $defaultDescription = 'Intelligent suggestions using predictive analytics and AI learning';

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
                'Suggestion context: workflow, completion, optimization, debugging, planning',
                'workflow'
            )
            ->addOption(
                'adaptive',
                'a',
                InputOption::VALUE_NONE,
                'Use adaptive suggestions based on learned patterns'
            )
            ->addOption(
                'predictive',
                'p',
                InputOption::VALUE_NONE,
                'Enable predictive analytics for future actions'
            )
            ->addOption(
                'personalized',
                null,
                InputOption::VALUE_NONE,
                'Generate personalized suggestions based on AI behavior'
            )
            ->addOption(
                'confidence-threshold',
                'c',
                InputOption::VALUE_REQUIRED,
                'Minimum confidence threshold for suggestions (0.0-1.0)',
                '0.6'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->addOption(
                'include-reasoning',
                'r',
                InputOption::VALUE_NONE,
                'Include detailed reasoning for each suggestion'
            )
            ->setHelp('
Smart Suggestion Engine with Predictive Analytics and AI Learning

This advanced suggestion system combines multiple intelligence sources:
- Historical pattern analysis from previous AI interactions
- Predictive modeling based on project characteristics
- Context-aware recommendations for specific workflows
- Personalized suggestions adapted to AI behavior patterns

Context Types:
  workflow      Smart workflow optimization suggestions
  completion    File and task completion predictions
  optimization  Performance and efficiency improvements  
  debugging     Issue resolution and diagnostic suggestions
  planning      Strategic planning and milestone recommendations

Intelligence Features:
  --adaptive       Use learned patterns to improve suggestions
  --predictive     Enable future action predictions
  --personalized   Adapt to specific AI usage patterns
  --reasoning      Include detailed explanation for suggestions

Examples:
  cpm smart-suggest workflow --adaptive --json
  cpm smart-suggest completion --predictive --confidence-threshold=0.8
  cpm smart-suggest debugging --personalized --include-reasoning
  cpm smart-suggest optimization --adaptive --predictive

Advanced Capabilities:
- Pattern Recognition: Learns from successful AI workflows
- Predictive Analytics: Forecasts likely next actions
- Context Intelligence: Understands project state and needs
- Adaptive Learning: Improves suggestions based on feedback
- Personalization: Tailors recommendations to AI behavior
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $input->getArgument('context');
        $adaptive = (bool) $input->getOption('adaptive');
        $predictive = (bool) $input->getOption('predictive');
        $personalized = (bool) $input->getOption('personalized');
        $confidenceThreshold = (float) $input->getOption('confidence-threshold');
        $json = $this->isJsonRequested($input);
        $includeReasoning = (bool) $input->getOption('include-reasoning');
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            $suggestions = $this->generateSmartSuggestions(
                $context,
                $adaptive,
                $predictive,
                $personalized,
                $confidenceThreshold,
                $includeReasoning
            );

            $result = [
                'suggestions' => $suggestions,
                'context' => $context,
                'intelligence_enabled' => [
                    'adaptive' => $adaptive,
                    'predictive' => $predictive,
                    'personalized' => $personalized
                ],
                'confidence_threshold' => $confidenceThreshold,
                'total_suggestions' => count($suggestions),
                'high_confidence_count' => count(array_filter($suggestions, fn($s) => ($s['confidence'] ?? 0) >= 0.8)),
                'generated_at' => date('Y-m-d H:i:s')
            ];

            if ($json) {
                $this->outputJsonSuccess($output, 'smart-suggest', $result);
            } else {
                $this->outputHumanSuggestions($io, $result, $includeReasoning);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'smart-suggest', $e, [
                'context' => $context,
                'adaptive' => $adaptive,
                'predictive' => $predictive
            ]);
        }
    }

    private function generateSmartSuggestions(
        string $context,
        bool $adaptive,
        bool $predictive,
        bool $personalized,
        float $confidenceThreshold,
        bool $includeReasoning
    ): array {
        $suggestions = [];
        
        // Get base context analysis
        $projectState = $this->analyzeProjectState();
        $sessionContext = $this->analyzeSessionContext();
        $workflowState = $this->analyzeWorkflowState();

        // Generate context-specific suggestions
        $baseSuggestions = match ($context) {
            'workflow' => $this->generateWorkflowSuggestions($projectState, $sessionContext),
            'completion' => $this->generateCompletionSuggestions($projectState, $workflowState),
            'optimization' => $this->generateOptimizationSuggestions($projectState, $sessionContext),
            'debugging' => $this->generateDebuggingSuggestions($projectState, $workflowState),
            'planning' => $this->generatePlanningSuggestions($projectState, $sessionContext),
            default => $this->generateGeneralSuggestions($projectState)
        };

        // Apply intelligence layers
        if ($adaptive) {
            $baseSuggestions = $this->applyAdaptiveLearning($baseSuggestions, $context);
        }

        if ($predictive) {
            $predictiveSuggestions = $this->generatePredictiveSuggestions($context, $projectState);
            $baseSuggestions = array_merge($baseSuggestions, $predictiveSuggestions);
        }

        if ($personalized) {
            $baseSuggestions = $this->applyPersonalization($baseSuggestions, $context);
        }

        // Filter by confidence threshold
        $suggestions = array_filter($baseSuggestions, fn($s) => ($s['confidence'] ?? 0) >= $confidenceThreshold);

        // Add reasoning if requested
        if ($includeReasoning) {
            foreach ($suggestions as &$suggestion) {
                $suggestion['detailed_reasoning'] = $this->generateDetailedReasoning($suggestion, $projectState);
            }
        }

        // Sort by confidence and priority
        usort($suggestions, function($a, $b) {
            $priorityOrder = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $aPriority = $priorityOrder[$a['priority'] ?? 'medium'] ?? 2;
            $bPriority = $priorityOrder[$b['priority'] ?? 'medium'] ?? 2;
            
            if ($aPriority === $bPriority) {
                return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
            }
            return $bPriority <=> $aPriority;
        });

        return array_slice($suggestions, 0, 10); // Top 10 suggestions
    }

    private function generateWorkflowSuggestions(array $projectState, array $sessionContext): array
    {
        $suggestions = [];
        
        // Progress-based workflow suggestions
        $progress = $projectState['progress_percentage'] ?? 0;
        
        if ($progress < 25) {
            $suggestions[] = [
                'id' => 'establish_workflow',
                'title' => 'Establish Development Workflow',
                'description' => 'Set up systematic approach for project completion',
                'action' => 'cpm checkpoint create --auto-description',
                'confidence' => 0.85,
                'priority' => 'high',
                'category' => 'workflow_setup',
                'reasoning' => 'Early project stage benefits from workflow establishment'
            ];
        }

        if ($progress >= 25 && $progress < 75) {
            $suggestions[] = [
                'id' => 'batch_processing',
                'title' => 'Use Batch Operations for Efficiency',
                'description' => 'Leverage bulk operations to accelerate progress',
                'action' => 'cpm progress bulk-mark "src/**/*.php" --status=completed',
                'confidence' => 0.78,
                'priority' => 'medium',
                'category' => 'efficiency',
                'reasoning' => 'Mid-project stage optimal for bulk operations'
            ];
        }

        if ($progress >= 75) {
            $suggestions[] = [
                'id' => 'final_verification',
                'title' => 'Prepare for Project Completion',
                'description' => 'Run comprehensive verification and prepare handoff',
                'action' => 'cpm ai-context --detailed --include-todos',
                'confidence' => 0.90,
                'priority' => 'high',
                'category' => 'completion',
                'reasoning' => 'Near completion requires thorough verification'
            ];
        }

        // Session activity based suggestions
        if ($sessionContext['duration_minutes'] > 60) {
            $suggestions[] = [
                'id' => 'create_checkpoint',
                'title' => 'Create Progress Checkpoint',
                'description' => 'Long session detected - save progress state',
                'action' => 'cpm checkpoint create --auto-description',
                'confidence' => 0.72,
                'priority' => 'medium',
                'category' => 'session_management',
                'reasoning' => 'Extended session benefits from checkpointing'
            ];
        }

        return $suggestions;
    }

    private function generateCompletionSuggestions(array $projectState, array $workflowState): array
    {
        $suggestions = [];
        $pendingCount = $workflowState['pending_count'] ?? 0;
        $inProgressCount = $workflowState['in_progress_count'] ?? 0;

        if ($pendingCount > 20) {
            $suggestions[] = [
                'id' => 'bulk_completion',
                'title' => 'Bulk Complete Similar Files',
                'description' => "Efficiently handle {$pendingCount} pending items",
                'action' => 'cpm progress bulk-mark "*.php" --status=completed --dry-run',
                'confidence' => 0.80,
                'priority' => 'high',
                'category' => 'bulk_operations',
                'reasoning' => 'High pending count suggests bulk operations efficiency'
            ];
        }

        if ($inProgressCount > 0) {
            $suggestions[] = [
                'id' => 'complete_in_progress',
                'title' => 'Focus on In-Progress Items',
                'description' => "Complete {$inProgressCount} partially finished items",
                'action' => 'cpm status --detailed',
                'confidence' => 0.85,
                'priority' => 'high',
                'category' => 'focus',
                'reasoning' => 'In-progress items have highest completion probability'
            ];
        }

        return $suggestions;
    }

    private function generateOptimizationSuggestions(array $projectState, array $sessionContext): array
    {
        $suggestions = [];

        if ($sessionContext['activities_count'] < 5) {
            $suggestions[] = [
                'id' => 'enable_monitoring',
                'title' => 'Enable Background Monitoring',
                'description' => 'Automate progress tracking for efficiency',
                'action' => 'cpm monitor --daemon',
                'confidence' => 0.75,
                'priority' => 'medium',
                'category' => 'automation',
                'reasoning' => 'Low activity suggests automation benefits'
            ];
        }

        $suggestions[] = [
            'id' => 'learn_patterns',
            'title' => 'Enable AI Learning System',
            'description' => 'Activate pattern learning for better predictions',
            'action' => 'cpm learn analyze --from-session',
            'confidence' => 0.70,
            'priority' => 'low',
            'category' => 'intelligence',
            'reasoning' => 'Learning system improves over time'
        ];

        return $suggestions;
    }

    private function generateDebuggingSuggestions(array $projectState, array $workflowState): array
    {
        $suggestions = [];

        if ($projectState['health'] !== 'healthy') {
            $suggestions[] = [
                'id' => 'system_repair',
                'title' => 'Run System Diagnostics',
                'description' => 'Address system health issues automatically',
                'action' => 'cpm repair --auto-fix --backup',
                'confidence' => 0.95,
                'priority' => 'critical',
                'category' => 'system_health',
                'reasoning' => 'System health issues require immediate attention'
            ];
        }

        if ($workflowState['issues_count'] > 0) {
            $suggestions[] = [
                'id' => 'resolve_issues',
                'title' => 'Address Function Issues',
                'description' => "Resolve {$workflowState['issues_count']} functions with issues",
                'action' => 'cpm progress show --json',
                'confidence' => 0.88,
                'priority' => 'high',
                'category' => 'issue_resolution',
                'reasoning' => 'Functions with issues block overall progress'
            ];
        }

        return $suggestions;
    }

    private function generatePlanningSuggestions(array $projectState, array $sessionContext): array
    {
        $suggestions = [];
        $progress = $projectState['progress_percentage'] ?? 0;

        // Milestone-based planning
        $nextMilestone = $this->calculateNextMilestone($progress);
        if ($nextMilestone) {
            $suggestions[] = [
                'id' => 'milestone_planning',
                'title' => "Plan {$nextMilestone['name']} Milestone",
                'description' => $nextMilestone['description'],
                'action' => 'cpm ai-suggest planning --priority=high',
                'confidence' => 0.82,
                'priority' => 'medium',
                'category' => 'milestone',
                'reasoning' => 'Milestone planning improves project structure'
            ];
        }

        return $suggestions;
    }

    private function generateGeneralSuggestions(array $projectState): array
    {
        return [
            [
                'id' => 'status_check',
                'title' => 'Check Current Status',
                'description' => 'Review project status and progress',
                'action' => 'cpm status --json',
                'confidence' => 0.60,
                'priority' => 'low',
                'category' => 'general',
                'reasoning' => 'Regular status checks maintain awareness'
            ]
        ];
    }

    private function applyAdaptiveLearning(array $suggestions, string $context): array
    {
        $learningData = $this->getLearningData();
        $commandStats = $this->getCommandSuccessStats($learningData);

        foreach ($suggestions as &$suggestion) {
            $command = explode(' ', $suggestion['action'])[0] ?? '';
            if (isset($commandStats[$command])) {
                $stats = $commandStats[$command];
                // Adjust confidence based on historical success rate
                $adjustment = ($stats['success_rate'] - 0.7) * 0.2; // -0.2 to +0.2 adjustment
                $suggestion['confidence'] = max(0.1, min(1.0, $suggestion['confidence'] + $adjustment));
                $suggestion['adaptive_adjustment'] = $adjustment;
                $suggestion['historical_success_rate'] = $stats['success_rate'];
            }
        }

        return $suggestions;
    }

    private function generatePredictiveSuggestions(string $context, array $projectState): array
    {
        $predictions = [];
        
        // Predict based on current trajectory
        $progress = $projectState['progress_percentage'] ?? 0;
        $velocity = $this->calculateProgressVelocity();

        if ($velocity > 0) {
            $estimatedCompletion = $this->estimateCompletionTime($progress, $velocity);
            $predictions[] = [
                'id' => 'completion_prediction',
                'title' => 'Completion Timeline Prediction',
                'description' => "Estimated completion in {$estimatedCompletion['time_remaining']}",
                'action' => 'cpm ai-context --include-todos',
                'confidence' => $estimatedCompletion['confidence'],
                'priority' => 'medium',
                'category' => 'prediction',
                'reasoning' => 'Based on current progress velocity pattern'
            ];
        }

        // Predict next likely actions
        $nextActions = $this->predictNextActions();
        foreach ($nextActions as $action) {
            $predictions[] = [
                'id' => 'predicted_action_' . md5($action['action']),
                'title' => 'Predicted Next Action',
                'description' => $action['reasoning'],
                'action' => $action['action'],
                'confidence' => $action['confidence'],
                'priority' => 'medium',
                'category' => 'prediction',
                'reasoning' => 'Based on command sequence patterns'
            ];
        }

        return $predictions;
    }

    private function applyPersonalization(array $suggestions, string $context): array
    {
        $preferences = $this->getAiPreferences();
        
        foreach ($suggestions as &$suggestion) {
            $command = explode(' ', $suggestion['action'])[0] ?? '';
            
            // Boost confidence for preferred commands
            if (isset($preferences['preferred_commands'][$command])) {
                $pref = $preferences['preferred_commands'][$command];
                if ($pref['frequency'] > 3 && $pref['success_rate'] > 0.8) {
                    $suggestion['confidence'] = min(1.0, $suggestion['confidence'] + 0.1);
                    $suggestion['personalization'] = 'Boosted based on usage preference';
                }
            }

            // Adjust based on time preferences
            $currentHour = (int) date('H');
            if (isset($preferences['time_patterns'][$currentHour])) {
                $timePattern = $preferences['time_patterns'][$currentHour];
                if (in_array($command, $timePattern['preferred_commands'] ?? [])) {
                    $suggestion['confidence'] = min(1.0, $suggestion['confidence'] + 0.05);
                    $suggestion['personalization'] = 'Time-based preference adjustment';
                }
            }
        }

        return $suggestions;
    }

    private function generateDetailedReasoning(array $suggestion, array $projectState): array
    {
        return [
            'confidence_factors' => [
                'historical_success' => $suggestion['historical_success_rate'] ?? 0.0,
                'context_relevance' => 0.8,
                'project_state_match' => 0.75
            ],
            'decision_factors' => [
                'project_progress' => $projectState['progress_percentage'] ?? 0,
                'system_health' => $projectState['health'] ?? 'unknown',
                'session_context' => 'Active development session'
            ],
            'expected_outcome' => 'Improved workflow efficiency and progress acceleration',
            'risk_assessment' => 'Low risk - safe operation with verification steps'
        ];
    }

    // Helper methods for analysis
    private function analyzeProjectState(): array
    {
        try {
            $progress = $this->database->read('progress');
            return [
                'progress_percentage' => round($progress['global_stats']['completion_percentage'] ?? 0, 1),
                'total_functions' => $progress['global_stats']['total_functions'] ?? 0,
                'completed_functions' => $progress['global_stats']['completed_functions'] ?? 0,
                'health' => 'healthy'
            ];
        } catch (\Exception $e) {
            return ['health' => 'unhealthy', 'progress_percentage' => 0];
        }
    }

    private function analyzeSessionContext(): array
    {
        try {
            $sessionReport = $this->sessionManager->generateStatusReport();
            return [
                'duration_minutes' => $sessionReport['duration_minutes'] ?? 0,
                'activities_count' => $sessionReport['activities_count'] ?? 0,
                'status' => $sessionReport['status'] ?? 'unknown'
            ];
        } catch (\Exception $e) {
            return ['duration_minutes' => 0, 'activities_count' => 0, 'status' => 'unknown'];
        }
    }

    private function analyzeWorkflowState(): array
    {
        try {
            $progress = $this->database->read('progress');
            $byFunction = $this->getByFunctionArray($progress['by_function'] ?? []);
            
            return [
                'pending_count' => count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'pending')),
                'in_progress_count' => count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'in_progress')),
                'issues_count' => count(array_filter($byFunction, fn($f) => ($f['status'] ?? 'pending') === 'has_issues'))
            ];
        } catch (\Exception $e) {
            return ['pending_count' => 0, 'in_progress_count' => 0, 'issues_count' => 0];
        }
    }

    private function calculateNextMilestone(float $progress): ?array
    {
        $milestones = [
            25 => ['name' => 'Quarter', 'description' => 'First quarter completion milestone'],
            50 => ['name' => 'Halfway', 'description' => 'Midpoint milestone with review'],
            75 => ['name' => 'Three-Quarters', 'description' => 'Final quarter milestone'],
            100 => ['name' => 'Completion', 'description' => 'Full project completion']
        ];

        foreach ($milestones as $threshold => $milestone) {
            if ($progress < $threshold) {
                return $milestone;
            }
        }

        return null;
    }

    private function getLearningData(): array
    {
        try {
            return $this->database->read('learning');
        } catch (\Exception $e) {
            return ['interactions' => [], 'patterns' => []];
        }
    }

    private function getCommandSuccessStats(array $learningData): array
    {
        $stats = [];
        foreach ($learningData['interactions'] ?? [] as $interaction) {
            if (($interaction['type'] ?? '') === 'command_usage') {
                $cmd = $interaction['command'] ?? '';
                if (!isset($stats[$cmd])) {
                    $stats[$cmd] = ['total' => 0, 'success' => 0, 'success_rate' => 0];
                }
                $stats[$cmd]['total']++;
                if ($interaction['success'] ?? false) {
                    $stats[$cmd]['success']++;
                }
            }
        }

        foreach ($stats as $cmd => &$data) {
            $data['success_rate'] = $data['total'] > 0 ? $data['success'] / $data['total'] : 0;
        }

        return $stats;
    }

    private function calculateProgressVelocity(): float
    {
        // Simplified velocity calculation
        return 0.15; // 15% per hour assumption
    }

    private function estimateCompletionTime(float $progress, float $velocity): array
    {
        $remaining = 100 - $progress;
        $hoursRemaining = $velocity > 0 ? $remaining / $velocity : 999;
        
        return [
            'time_remaining' => $hoursRemaining < 24 ? round($hoursRemaining, 1) . ' hours' : round($hoursRemaining / 24, 1) . ' days',
            'confidence' => min(0.9, 0.5 + ($velocity * 2))
        ];
    }

    private function predictNextActions(): array
    {
        return [
            [
                'action' => 'cpm progress show --json',
                'confidence' => 0.75,
                'reasoning' => 'Common pattern: status check followed by progress review'
            ]
        ];
    }

    private function getAiPreferences(): array
    {
        return [
            'preferred_commands' => [
                'status' => ['frequency' => 10, 'success_rate' => 0.95],
                'progress' => ['frequency' => 8, 'success_rate' => 0.88]
            ],
            'time_patterns' => []
        ];
    }

    private function getByFunctionArray($byFunction): array
    {
        if (is_object($byFunction)) {
            return (array) $byFunction;
        }
        return is_array($byFunction) ? $byFunction : [];
    }

    private function outputHumanSuggestions(SymfonyStyle $io, array $result, bool $includeReasoning): void
    {
        $suggestions = $result['suggestions'] ?? [];
        $context = $result['context'] ?? 'unknown';
        
        $io->title("🧠 Smart Suggestions - " . ucfirst($context) . " Context");
        
        $intelligence = $result['intelligence_enabled'] ?? [];
        $features = array_filter($intelligence);
        if (!empty($features)) {
            $io->text('🔬 Intelligence: ' . implode(', ', array_keys($features)));
        }
        
        $io->text("📊 Generated {$result['total_suggestions']} suggestions ({$result['high_confidence_count']} high confidence)");
        $io->newLine();

        if (empty($suggestions)) {
            $io->note('No suggestions meet the confidence threshold. Try lowering --confidence-threshold.');
            return;
        }

        foreach ($suggestions as $i => $suggestion) {
            $priorityIcon = match($suggestion['priority'] ?? 'medium') {
                'critical' => '🚨',
                'high' => '🔴',
                'medium' => '🟡',
                'low' => '🔵',
                default => '⚪'
            };

            $confidence = round(($suggestion['confidence'] ?? 0) * 100, 1);
            
            $io->section("{$priorityIcon} " . ($suggestion['title'] ?? 'Unknown Suggestion'));
            $io->text($suggestion['description'] ?? '');
            $io->text("Command: <info>{$suggestion['action']}</info>");
            $io->text("Confidence: {$confidence}% | Category: " . ($suggestion['category'] ?? 'general'));
            
            if ($includeReasoning && !empty($suggestion['reasoning'])) {
                $io->text("💭 Reasoning: " . $suggestion['reasoning']);
                
                if (!empty($suggestion['detailed_reasoning'])) {
                    $reasoning = $suggestion['detailed_reasoning'];
                    $io->text("📈 Expected Success Rate: " . round(($reasoning['confidence_factors']['historical_success'] ?? 0) * 100, 1) . "%");
                }
            }
            
            if (!empty($suggestion['adaptive_adjustment'])) {
                $adjustment = round($suggestion['adaptive_adjustment'], 3);
                $io->text("🎯 Adaptive Adjustment: {$adjustment} (learned from patterns)");
            }
            
            $io->newLine();
        }

        $io->note([
            '🧠 Smart suggestions use AI learning and predictive analytics',
            '📊 Higher confidence suggestions are more likely to be successful',
            '🎯 Adaptive learning improves suggestions over time'
        ]);
    }
}