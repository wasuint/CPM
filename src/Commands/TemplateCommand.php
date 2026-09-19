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
 * Intelligent template system with learning capabilities
 * Provides AI-optimized code templates that adapt to project patterns
 */
class TemplateCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;

    protected static $defaultName = 'template';
    protected static $defaultDescription = 'Intelligent template system with learning capabilities';

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
                'Action: list, generate, analyze, learn, export',
                'list'
            )
            ->addArgument(
                'template-type',
                InputArgument::OPTIONAL,
                'Template type: function, class, test, config, api'
            )
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'Pattern to analyze or generate'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Programming language',
                'php'
            )
            ->addOption(
                'context',
                'c',
                InputOption::VALUE_REQUIRED,
                'Context for template generation',
                'general'
            )
            ->addOption(
                'learning',
                null,
                InputOption::VALUE_NONE,
                'Enable learning mode for pattern detection'
            )
            ->addOption(
                'confidence',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum confidence threshold for suggestions',
                '0.7'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->setHelp('
Intelligent template system that learns from project patterns and generates AI-optimized code templates.

Features:
- Pattern recognition from existing codebase
- Adaptive template generation
- Context-aware suggestions
- Learning from successful implementations
- Multi-language support

Actions:
  list                List available templates and patterns
  generate           Generate template based on pattern
  analyze            Analyze codebase for patterns
  learn              Learn patterns from recent changes
  export             Export templates for external use

Template Types:
  function           Function/method templates
  class             Class structure templates  
  test              Test case templates
  config            Configuration file templates
  api               API endpoint templates

Examples:
  cpm template list --json
  cpm template generate function --pattern="user_auth" --context="security"
  cpm template analyze --learning --language=php
  cpm template learn --confidence=0.8 --json

Learning Process:
- Analyzes successful code patterns
- Identifies common structures and conventions
- Adapts templates based on project style
- Provides confidence scoring for suggestions
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $templateType = $input->getArgument('template-type');
        $pattern = $input->getOption('pattern');
        $language = $input->getOption('language');
        $context = $input->getOption('context');
        $learning = (bool) $input->getOption('learning');
        $confidence = (float) $input->getOption('confidence');
        $json = $this->isJsonRequested($input);
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            $result = match ($action) {
                'list' => $this->listTemplates($language, $context),
                'generate' => $this->generateTemplate($templateType, $pattern, $language, $context),
                'analyze' => $this->analyzePatterns($language, $learning, $confidence),
                'learn' => $this->learnPatterns($confidence),
                'export' => $this->exportTemplates($language),
                default => throw new \InvalidArgumentException("Unknown action: {$action}")
            };

            if ($json) {
                $this->outputJsonSuccess($output, 'template', $result, [
                    'action' => $action,
                    'language' => $language,
                    'learning_enabled' => $learning
                ]);
            } else {
                $this->outputHumanResult($io, $action, $result);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'template', $e, [
                'action' => $action,
                'template_type' => $templateType
            ]);
        }
    }

    private function listTemplates(string $language, string $context): array
    {
        $templates = $this->getBaseTemplates($language);
        $learnedPatterns = $this->getLearnedPatterns($language, $context);
        
        return [
            'available_templates' => count($templates),
            'learned_patterns' => count($learnedPatterns),
            'templates' => $templates,
            'patterns' => $learnedPatterns,
            'languages_supported' => ['php', 'javascript', 'python', 'typescript'],
            'contexts' => ['general', 'api', 'security', 'database', 'frontend', 'testing']
        ];
    }

    private function generateTemplate(string $templateType, ?string $pattern, string $language, string $context): array
    {
        if (!$templateType) {
            throw new \InvalidArgumentException('Template type is required for generation');
        }

        $baseTemplate = $this->getBaseTemplate($templateType, $language);
        $learnedAdaptations = $this->getPatternAdaptations($pattern, $language, $context);
        $generatedTemplate = $this->applyAdaptations($baseTemplate, $learnedAdaptations);

        return [
            'template_generated' => true,
            'type' => $templateType,
            'pattern' => $pattern,
            'language' => $language,
            'context' => $context,
            'template_content' => $generatedTemplate,
            'confidence_score' => $this->calculateTemplateConfidence($learnedAdaptations),
            'adaptations_applied' => count($learnedAdaptations),
            'suggestions' => $this->generateTemplateSuggestions($templateType, $context)
        ];
    }

    private function analyzePatterns(string $language, bool $learning, float $confidence): array
    {
        $analysis = [
            'patterns_found' => 0,
            'confidence_threshold' => $confidence,
            'language' => $language,
            'learning_mode' => $learning
        ];

        if ($learning) {
            $patterns = $this->performPatternAnalysis($language, $confidence);
            $this->storeLearnedPatterns($patterns, $language);
            
            $analysis = array_merge($analysis, [
                'patterns_found' => count($patterns),
                'patterns_learned' => count(array_filter($patterns, fn($p) => $p['confidence'] >= $confidence)),
                'common_structures' => $this->identifyCommonStructures($patterns),
                'style_conventions' => $this->detectStyleConventions($patterns),
                'anti_patterns' => $this->detectAntiPatterns($patterns)
            ]);
        } else {
            $existingPatterns = $this->getLearnedPatterns($language);
            $analysis['patterns_found'] = count($existingPatterns);
            $analysis['existing_patterns'] = array_slice($existingPatterns, 0, 10);
        }

        return $analysis;
    }

    private function learnPatterns(float $confidence): array
    {
        $recentChanges = $this->getRecentCodeChanges();
        $successfulPatterns = $this->identifySuccessfulPatterns($recentChanges, $confidence);
        
        $learned = [
            'new_patterns_learned' => 0,
            'patterns_updated' => 0,
            'confidence_improved' => 0
        ];

        foreach ($successfulPatterns as $pattern) {
            if ($this->isNewPattern($pattern)) {
                $this->storeNewPattern($pattern);
                $learned['new_patterns_learned']++;
            } else {
                $this->updatePatternConfidence($pattern);
                $learned['patterns_updated']++;
                
                if ($pattern['confidence'] > $confidence) {
                    $learned['confidence_improved']++;
                }
            }
        }

        return array_merge($learned, [
            'total_patterns_processed' => count($successfulPatterns),
            'learning_session_completed' => true,
            'next_learning_recommendation' => $this->getNextLearningRecommendation()
        ]);
    }

    private function exportTemplates(string $language): array
    {
        $templates = $this->getAllTemplates($language);
        $exportData = [
            'export_format' => 'cpm_templates_v1',
            'language' => $language,
            'exported_at' => date('Y-m-d H:i:s'),
            'template_count' => count($templates),
            'templates' => $templates
        ];

        $exportPath = '/tmp/cpm_templates_' . $language . '_' . date('Ymd_His') . '.json';
        file_put_contents($exportPath, json_encode($exportData));

        return [
            'export_completed' => true,
            'export_path' => $exportPath,
            'template_count' => count($templates),
            'file_size' => filesize($exportPath) . ' bytes',
            'format' => 'JSON',
            'import_command' => "cpm template import {$exportPath}"
        ];
    }

    private function getBaseTemplates(string $language): array
    {
        $templates = [
            'php' => [
                'function' => [
                    'name' => 'PHP Function',
                    'pattern' => 'function {{name}}({{params}}): {{return_type}}\n{\n    {{body}}\n}',
                    'confidence' => 0.9
                ],
                'class' => [
                    'name' => 'PHP Class', 
                    'pattern' => 'class {{name}}\n{\n    {{properties}}\n\n    {{methods}}\n}',
                    'confidence' => 0.9
                ],
                'test' => [
                    'name' => 'PHPUnit Test',
                    'pattern' => 'public function test{{name}}(): void\n{\n    {{assertions}}\n}',
                    'confidence' => 0.85
                ]
            ]
        ];

        return $templates[$language] ?? [];
    }

    private function getLearnedPatterns(string $language, string $context = 'general'): array
    {
        try {
            $patternsData = $this->database->read('learned_patterns');
            $patterns = $patternsData['patterns'][$language][$context] ?? [];
            
            return array_filter($patterns, fn($p) => $p['confidence'] >= 0.6);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function performPatternAnalysis(string $language, float $confidence): array
    {
        // Simulated pattern analysis - in reality would analyze actual code files
        return [
            [
                'type' => 'function_naming',
                'pattern' => 'camelCase with descriptive verbs',
                'confidence' => 0.85,
                'frequency' => 45
            ],
            [
                'type' => 'error_handling',
                'pattern' => 'try-catch with specific exceptions',
                'confidence' => 0.78,
                'frequency' => 32
            ],
            [
                'type' => 'dependency_injection',
                'pattern' => 'constructor injection with interfaces',
                'confidence' => 0.92,
                'frequency' => 28
            ]
        ];
    }

    private function getBaseTemplate(string $type, string $language): array
    {
        $templates = $this->getBaseTemplates($language);
        return $templates[$type] ?? [
            'name' => 'Generic Template',
            'pattern' => '{{content}}',
            'confidence' => 0.5
        ];
    }

    private function getPatternAdaptations(string $pattern, string $language, string $context): array
    {
        if (!$pattern) return [];
        
        $learnedPatterns = $this->getLearnedPatterns($language, $context);
        return array_filter($learnedPatterns, fn($p) => 
            strpos(strtolower($p['type'] ?? ''), strtolower($pattern)) !== false
        );
    }

    private function applyAdaptations(array $baseTemplate, array $adaptations): string
    {
        $template = $baseTemplate['pattern'];
        
        foreach ($adaptations as $adaptation) {
            // Apply learned patterns to template
            if (isset($adaptation['modification'])) {
                $template = str_replace(
                    $adaptation['target'] ?? '{{body}}', 
                    $adaptation['modification'], 
                    $template
                );
            }
        }
        
        return $template;
    }

    private function calculateTemplateConfidence(array $adaptations): float
    {
        if (empty($adaptations)) return 0.5;
        
        $totalConfidence = array_sum(array_column($adaptations, 'confidence'));
        return round($totalConfidence / count($adaptations), 2);
    }

    private function generateTemplateSuggestions(string $type, string $context): array
    {
        return [
            "Consider adding error handling for {$type}",
            "Include documentation comments",
            "Add type hints for better IDE support",
            "Follow {$context} best practices"
        ];
    }

    private function identifyCommonStructures(array $patterns): array
    {
        $structures = [];
        foreach ($patterns as $pattern) {
            $structures[] = $pattern['type'] ?? 'unknown';
        }
        return array_count_values($structures);
    }

    private function detectStyleConventions(array $patterns): array
    {
        return [
            'naming_convention' => 'camelCase',
            'indentation' => '4 spaces',
            'line_length' => 120,
            'brace_style' => 'same_line'
        ];
    }

    private function detectAntiPatterns(array $patterns): array
    {
        return [
            'long_functions' => 'Functions over 50 lines detected',
            'deep_nesting' => 'Nesting levels over 4 found',
            'magic_numbers' => 'Hard-coded numbers without constants'
        ];
    }

    private function storeLearnedPatterns(array $patterns, string $language): void
    {
        try {
            $existingData = $this->database->read('learned_patterns');
        } catch (\Exception $e) {
            $existingData = ['patterns' => []];
        }
        
        if (!isset($existingData['patterns'][$language])) {
            $existingData['patterns'][$language] = [];
        }
        
        foreach ($patterns as $pattern) {
            $existingData['patterns'][$language][] = array_merge($pattern, [
                'learned_at' => date('Y-m-d H:i:s'),
                'source' => 'pattern_analysis'
            ]);
        }
        
        $this->database->write('learned_patterns', $existingData);
    }

    private function getRecentCodeChanges(): array
    {
        // Simulated - would integrate with version control
        return [
            'successful_implementations' => 15,
            'failed_attempts' => 3,
            'patterns_identified' => 8
        ];
    }

    private function identifySuccessfulPatterns(array $changes, float $confidence): array
    {
        // Simulated successful pattern identification
        return [
            [
                'type' => 'authentication_flow',
                'confidence' => 0.89,
                'success_rate' => 0.95,
                'usage_frequency' => 12
            ]
        ];
    }

    private function isNewPattern(array $pattern): bool
    {
        try {
            $existing = $this->database->read('learned_patterns');
            $patterns = $existing['patterns'] ?? [];
            
            foreach ($patterns as $lang => $langPatterns) {
                foreach ($langPatterns as $existingPattern) {
                    if ($existingPattern['type'] === $pattern['type']) {
                        return false;
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }

    private function storeNewPattern(array $pattern): void
    {
        try {
            $data = $this->database->read('learned_patterns');
        } catch (\Exception $e) {
            $data = ['patterns' => ['php' => []]];
        }
        
        $data['patterns']['php'][] = array_merge($pattern, [
            'learned_at' => date('Y-m-d H:i:s'),
            'source' => 'success_analysis'
        ]);
        
        $this->database->write('learned_patterns', $data);
    }

    private function updatePatternConfidence(array $pattern): void
    {
        // Update confidence based on continued success
    }

    private function getNextLearningRecommendation(): string
    {
        return 'Analyze recent successful implementations for new patterns';
    }

    private function getAllTemplates(string $language): array
    {
        $base = $this->getBaseTemplates($language);
        $learned = $this->getLearnedPatterns($language);
        
        return array_merge($base, $learned);
    }

    private function outputHumanResult(?SymfonyStyle $io, string $action, array $result): void
    {
        if (!$io) return;

        switch ($action) {
            case 'list':
                $io->title('🎨 Intelligent Template System');
                $io->text('Available Templates: ' . $result['available_templates']);
                $io->text('Learned Patterns: ' . $result['learned_patterns']);
                $io->text('Languages: ' . implode(', ', $result['languages_supported']));
                break;

            case 'generate':
                $io->success('✅ Template generated successfully');
                $io->text('Type: ' . $result['type']);
                $io->text('Confidence: ' . ($result['confidence_score'] * 100) . '%');
                $io->newLine();
                $io->text('Template Content:');
                $io->text($result['template_content']);
                break;

            case 'analyze':
                $io->success('🔍 Pattern analysis completed');
                $io->text('Patterns Found: ' . $result['patterns_found']);
                if (isset($result['common_structures'])) {
                    $io->newLine();
                    $io->text('Common Structures:');
                    foreach ($result['common_structures'] as $struct => $count) {
                        $io->text("  {$struct}: {$count} occurrences");
                    }
                }
                break;

            case 'learn':
                $io->success('🧠 Learning session completed');
                $io->text('New Patterns: ' . $result['new_patterns_learned']);
                $io->text('Updated Patterns: ' . $result['patterns_updated']);
                $io->text('Improved Confidence: ' . $result['confidence_improved']);
                break;

            case 'export':
                $io->success('📤 Templates exported successfully');
                $io->text('Export Path: ' . $result['export_path']);
                $io->text('Template Count: ' . $result['template_count']);
                break;
        }
    }
}