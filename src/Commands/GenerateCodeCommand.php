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
 * Pattern-aware code generation engine
 * Generates code that follows project conventions and learned patterns
 */
class GenerateCodeCommand extends Command
{
    use JsonResponseTrait;

    private DatabaseManager $database;
    private SessionManager $sessionManager;

    protected static $defaultName = 'generate-code';
    protected static $defaultDescription = 'Pattern-aware code generation engine';

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
                'type',
                InputArgument::REQUIRED,
                'Code type to generate: function, class, test, api, crud, service'
            )
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Name for the generated code element'
            )
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'Specific pattern to follow'
            )
            ->addOption(
                'context',
                'c',
                InputOption::VALUE_REQUIRED,
                'Context for generation (api, service, model, etc.)',
                'general'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Target programming language',
                'php'
            )
            ->addOption(
                'output-path',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path (optional - will suggest based on conventions)'
            )
            ->addOption(
                'dependencies',
                'd',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Dependencies to include'
            )
            ->addOption(
                'interface',
                'i',
                InputOption::VALUE_REQUIRED,
                'Interface to implement'
            )
            ->addOption(
                'extends',
                'e',
                InputOption::VALUE_REQUIRED,
                'Parent class to extend'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Generate code without writing to file'
            )
            ->addOption(
                'learn',
                null,
                InputOption::VALUE_NONE,
                'Learn from this generation for future improvements'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output JSON format for AI consumption'
            )
            ->setHelp('
Pattern-aware code generation that follows project conventions and learned patterns.

The engine analyzes existing code to understand:
- Naming conventions and style patterns
- Architectural patterns and structures
- Error handling approaches
- Documentation styles
- Testing patterns

Code Types:
  function           Generate function/method with proper signature
  class             Generate class with constructor and basic methods
  test              Generate test cases following project patterns
  api               Generate API endpoint with validation and responses
  crud              Generate full CRUD operations for entity
  service           Generate service class with dependency injection

Examples:
  cpm generate-code function validateUser --context=auth --pattern=validation
  cpm generate-code class UserService --interface=UserServiceInterface --output-path=src/Services/
  cpm generate-code test UserServiceTest --context=unit --dependencies=UserService,Database
  cpm generate-code api /users --context=rest --pattern=json_api
  cpm generate-code crud User --context=eloquent --learn

Pattern Learning:
- Analyzes successful code implementations
- Identifies common architectural patterns
- Adapts to project-specific conventions
- Improves suggestions based on usage patterns
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');
        $name = $input->getArgument('name');
        $pattern = $input->getOption('pattern');
        $context = $input->getOption('context');
        $language = $input->getOption('language');
        $outputPath = $input->getOption('output-path');
        $dependencies = $input->getOption('dependencies') ?? [];
        $interface = $input->getOption('interface');
        $extends = $input->getOption('extends');
        $dryRun = (bool) $input->getOption('dry-run');
        $learn = (bool) $input->getOption('learn');
        $json = $this->isJsonRequested($input);
        $io = $this->createStyleIfNeeded($input, $output);

        try {
            $generationSpecs = [
                'type' => $type,
                'name' => $name,
                'pattern' => $pattern,
                'context' => $context,
                'language' => $language,
                'output_path' => $outputPath,
                'dependencies' => $dependencies,
                'interface' => $interface,
                'extends' => $extends,
                'dry_run' => $dryRun,
                'learn' => $learn
            ];

            $result = $this->generateCode($generationSpecs);

            if ($json) {
                $this->outputJsonSuccess($output, 'generate-code', $result, [
                    'type' => $type,
                    'language' => $language,
                    'pattern_applied' => $pattern !== null
                ]);
            } else {
                $this->outputHumanResult($io, $result);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            return $this->handleJsonException($output, 'generate-code', $e, [
                'type' => $type,
                'name' => $name
            ]);
        }
    }

    private function generateCode(array $specs): array
    {
        $projectPatterns = $this->analyzeProjectPatterns($specs['language'], $specs['context']);
        $codeTemplate = $this->selectCodeTemplate($specs['type'], $projectPatterns);
        $generatedCode = $this->applyPatterns($codeTemplate, $specs, $projectPatterns);
        
        $suggestedPath = $specs['output_path'] ?? $this->suggestFilePath($specs, $projectPatterns);
        
        $result = [
            'code_generated' => true,
            'type' => $specs['type'],
            'name' => $specs['name'],
            'language' => $specs['language'],
            'generated_code' => $generatedCode,
            'suggested_path' => $suggestedPath,
            'patterns_applied' => count($projectPatterns),
            'confidence_score' => $this->calculateGenerationConfidence($specs, $projectPatterns),
            'file_written' => false
        ];

        if (!$specs['dry_run']) {
            $writeResult = $this->writeGeneratedCode($generatedCode, $suggestedPath, $specs);
            $result['file_written'] = $writeResult['success'];
            if ($writeResult['success']) {
                $result['actual_path'] = $writeResult['path'];
            }
        }

        if ($specs['learn']) {
            $this->recordGenerationForLearning($specs, $result, $projectPatterns);
        }

        $result['suggestions'] = $this->generateImprovementSuggestions($specs, $result);
        
        return $result;
    }

    private function analyzeProjectPatterns(string $language, string $context): array
    {
        try {
            $patternsData = $this->database->read('learned_patterns');
            $patterns = $patternsData['patterns'][$language][$context] ?? [];
            
            // Add project-specific conventions
            $conventions = $this->detectProjectConventions($language);
            
            return array_merge($patterns, $conventions);
            
        } catch (\Exception $e) {
            return $this->getDefaultPatterns($language, $context);
        }
    }

    private function selectCodeTemplate(string $type, array $patterns): array
    {
        $templates = [
            'function' => [
                'php' => "<?php\n\n/**\n * {{description}}\n{{docblock}}\n */\n{{visibility}} function {{name}}({{parameters}}){{return_type}}\n{\n{{body}}\n}",
                'confidence' => 0.9
            ],
            'class' => [
                'php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{namespace}};\n\n{{imports}}\n\n/**\n * {{description}}\n */\nclass {{name}}{{inheritance}}\n{\n{{properties}}\n\n{{constructor}}\n\n{{methods}}\n}",
                'confidence' => 0.95
            ],
            'test' => [
                'php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{namespace}};\n\nuse PHPUnit\\Framework\\TestCase;\n{{imports}}\n\n/**\n * {{description}}\n */\nclass {{name}} extends TestCase\n{\n{{setup}}\n\n{{tests}}\n\n{{teardown}}\n}",
                'confidence' => 0.88
            ],
            'api' => [
                'php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{namespace}};\n\n{{imports}}\n\n/**\n * {{description}}\n */\nclass {{name}}\n{\n    public function {{method}}(Request \$request): Response\n    {\n{{validation}}\n\n{{logic}}\n\n{{response}}\n    }\n}",
                'confidence' => 0.92
            ],
            'service' => [
                'php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{namespace}};\n\n{{imports}}\n\n/**\n * {{description}}\n */\nclass {{name}}{{interface}}\n{\n{{properties}}\n\n    public function __construct({{dependencies}})\n    {\n{{constructor_body}}\n    }\n\n{{methods}}\n}",
                'confidence' => 0.91
            ],
            'crud' => [
                'php' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace {{namespace}};\n\n{{imports}}\n\n/**\n * {{description}}\n */\nclass {{name}}\n{\n{{create_method}}\n\n{{read_method}}\n\n{{update_method}}\n\n{{delete_method}}\n\n{{list_method}}\n}",
                'confidence' => 0.87
            ]
        ];

        return $templates[$type] ?? [
            'php' => "<?php\n\n// Generated {{type}}: {{name}}\n{{content}}",
            'confidence' => 0.5
        ];
    }

    private function applyPatterns(array $template, array $specs, array $patterns): string
    {
        $code = $template['php'] ?? '';
        
        // Apply basic substitutions
        $substitutions = $this->generateSubstitutions($specs, $patterns);
        
        foreach ($substitutions as $placeholder => $value) {
            $code = str_replace("{{" . $placeholder . "}}", $value, $code);
        }
        
        // Apply pattern-specific modifications
        $code = $this->applyPatternModifications($code, $patterns, $specs);
        
        return $code;
    }

    private function generateSubstitutions(array $specs, array $patterns): array
    {
        $substitutions = [
            'name' => $specs['name'],
            'description' => $this->generateDescription($specs),
            'namespace' => $this->determineNamespace($specs, $patterns),
            'visibility' => $this->determineVisibility($specs, $patterns),
            'return_type' => $this->determineReturnType($specs, $patterns)
        ];

        // Type-specific substitutions
        switch ($specs['type']) {
            case 'function':
                $substitutions = array_merge($substitutions, [
                    'parameters' => $this->generateParameters($specs),
                    'body' => $this->generateFunctionBody($specs, $patterns),
                    'docblock' => $this->generateDocblock($specs)
                ]);
                break;
                
            case 'class':
                $substitutions = array_merge($substitutions, [
                    'imports' => $this->generateImports($specs),
                    'inheritance' => $this->generateInheritance($specs),
                    'properties' => $this->generateProperties($specs, $patterns),
                    'constructor' => $this->generateConstructor($specs, $patterns),
                    'methods' => $this->generateMethods($specs, $patterns)
                ]);
                break;
                
            case 'test':
                $substitutions = array_merge($substitutions, [
                    'imports' => $this->generateTestImports($specs),
                    'setup' => $this->generateTestSetup($specs),
                    'tests' => $this->generateTestMethods($specs, $patterns),
                    'teardown' => $this->generateTestTeardown($specs)
                ]);
                break;
                
            case 'api':
                $substitutions = array_merge($substitutions, [
                    'imports' => $this->generateApiImports($specs),
                    'method' => $this->determineHttpMethod($specs),
                    'validation' => $this->generateValidation($specs, $patterns),
                    'logic' => $this->generateApiLogic($specs, $patterns),
                    'response' => $this->generateApiResponse($specs, $patterns)
                ]);
                break;
        }

        return $substitutions;
    }

    private function suggestFilePath(array $specs, array $patterns): string
    {
        $basePath = getcwd() . '/src/';
        
        switch ($specs['type']) {
            case 'function':
                return $basePath . 'Functions/' . $specs['name'] . '.php';
            case 'class':
                return $basePath . $this->determineClassDirectory($specs['context']) . '/' . $specs['name'] . '.php';
            case 'test':
                return getcwd() . '/tests/' . $specs['name'] . '.php';
            case 'api':
                return $basePath . 'Controllers/' . $specs['name'] . 'Controller.php';
            case 'service':
                return $basePath . 'Services/' . $specs['name'] . '.php';
            default:
                return $basePath . $specs['name'] . '.php';
        }
    }

    private function calculateGenerationConfidence(array $specs, array $patterns): float
    {
        $baseConfidence = 0.7;
        $patternBonus = count($patterns) * 0.05;
        $specificityBonus = 0;
        
        if ($specs['context'] !== 'general') $specificityBonus += 0.1;
        if ($specs['pattern']) $specificityBonus += 0.1;
        if (!empty($specs['dependencies'])) $specificityBonus += 0.05;
        
        return min(0.99, $baseConfidence + $patternBonus + $specificityBonus);
    }

    private function writeGeneratedCode(string $code, string $path, array $specs): array
    {
        try {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            if (file_exists($path) && !$specs['dry_run']) {
                // Create backup
                $backupPath = $path . '.backup.' . time();
                copy($path, $backupPath);
            }
            
            file_put_contents($path, $code);
            
            return [
                'success' => true,
                'path' => $path,
                'size' => strlen($code)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function recordGenerationForLearning(array $specs, array $result, array $patterns): void
    {
        $learningData = [
            'generation_id' => 'gen_' . time() . '_' . substr(md5($specs['name']), 0, 8),
            'timestamp' => date('Y-m-d H:i:s'),
            'specs' => $specs,
            'result' => $result,
            'patterns_used' => $patterns,
            'success' => $result['file_written'] ?? false,
            'confidence' => $result['confidence_score']
        ];

        try {
            $existing = $this->database->read('generation_history');
        } catch (\Exception $e) {
            $existing = ['generations' => []];
        }
        
        $existing['generations'][] = $learningData;
        
        // Keep only last 100 generations
        if (count($existing['generations']) > 100) {
            $existing['generations'] = array_slice($existing['generations'], -100);
        }
        
        $this->database->write('generation_history', $existing);
    }

    private function generateImprovementSuggestions(array $specs, array $result): array
    {
        $suggestions = [];
        
        if ($result['confidence_score'] < 0.8) {
            $suggestions[] = "Consider providing more specific context or pattern information";
        }
        
        if ($result['patterns_applied'] < 3) {
            $suggestions[] = "Run 'cpm template analyze --learning' to improve pattern detection";
        }
        
        if (empty($specs['dependencies']) && in_array($specs['type'], ['class', 'service'])) {
            $suggestions[] = "Consider specifying dependencies for better dependency injection";
        }
        
        return $suggestions;
    }

    // Helper methods for code generation
    private function detectProjectConventions(string $language): array
    {
        return [
            [
                'type' => 'indentation',
                'value' => '4 spaces',
                'confidence' => 0.9
            ],
            [
                'type' => 'namespace_style',
                'value' => 'PSR-4',
                'confidence' => 0.95
            ]
        ];
    }

    private function getDefaultPatterns(string $language, string $context): array
    {
        return [
            [
                'type' => 'error_handling',
                'value' => 'exceptions',
                'confidence' => 0.8
            ],
            [
                'type' => 'documentation',
                'value' => 'docblock',
                'confidence' => 0.85
            ]
        ];
    }

    private function generateDescription(array $specs): string
    {
        $typeDescriptions = [
            'function' => "Function to handle {$specs['name']} operations",
            'class' => "Class for {$specs['name']} functionality",
            'test' => "Test cases for {$specs['name']}",
            'api' => "API endpoint for {$specs['name']}",
            'service' => "Service class for {$specs['name']} operations",
            'crud' => "CRUD operations for {$specs['name']}"
        ];
        
        return $typeDescriptions[$specs['type']] ?? "Generated {$specs['type']}: {$specs['name']}";
    }

    private function determineNamespace(array $specs, array $patterns): string
    {
        $contextNamespaces = [
            'api' => 'App\\Controllers',
            'service' => 'App\\Services', 
            'model' => 'App\\Models',
            'test' => 'Tests'
        ];
        
        return $contextNamespaces[$specs['context']] ?? 'App';
    }

    private function determineVisibility(array $specs, array $patterns): string
    {
        return $specs['type'] === 'function' ? 'public' : '';
    }

    private function determineReturnType(array $specs, array $patterns): string
    {
        if ($specs['type'] === 'function') {
            return ': mixed';
        }
        return '';
    }

    private function generateParameters(array $specs): string
    {
        if (!empty($specs['dependencies'])) {
            return implode(', ', array_map(fn($dep) => "\${$dep}", $specs['dependencies']));
        }
        return '';
    }

    private function generateFunctionBody(array $specs, array $patterns): string
    {
        return "    // TODO: Implement {$specs['name']} logic\n    throw new \\Exception('Not implemented');";
    }

    private function generateDocblock(array $specs): string
    {
        $params = '';
        if (!empty($specs['dependencies'])) {
            foreach ($specs['dependencies'] as $dep) {
                $params .= " * @param mixed \${$dep}\n";
            }
        }
        return $params . " * @return mixed";
    }

    private function generateImports(array $specs): string
    {
        $imports = [];
        
        if ($specs['interface']) {
            $imports[] = "use {$specs['interface']};";
        }
        
        if ($specs['extends']) {
            $imports[] = "use {$specs['extends']};";
        }
        
        return implode("\n", $imports);
    }

    private function generateInheritance(array $specs): string
    {
        $inheritance = [];
        
        if ($specs['extends']) {
            $inheritance[] = "extends " . basename($specs['extends']);
        }
        
        if ($specs['interface']) {
            $inheritance[] = "implements " . basename($specs['interface']);
        }
        
        return empty($inheritance) ? '' : ' ' . implode(' ', $inheritance);
    }

    private function generateProperties(array $specs, array $patterns): string
    {
        if (!empty($specs['dependencies'])) {
            $properties = [];
            foreach ($specs['dependencies'] as $dep) {
                $properties[] = "    private \${$dep};";
            }
            return implode("\n", $properties);
        }
        return '    // Properties will be added here';
    }

    private function generateConstructor(array $specs, array $patterns): string
    {
        if (!empty($specs['dependencies'])) {
            $params = implode(', ', array_map(fn($dep) => "\${$dep}", $specs['dependencies']));
            $assignments = implode("\n        ", array_map(fn($dep) => "\$this->{$dep} = \${$dep};", $specs['dependencies']));
            
            return "    public function __construct({$params})\n    {\n        {$assignments}\n    }";
        }
        return '    // Constructor will be added here';
    }

    private function generateMethods(array $specs, array $patterns): string
    {
        return "    // Methods will be added here";
    }

    private function generateTestImports(array $specs): string
    {
        return "use PHPUnit\\Framework\\TestCase;";
    }

    private function generateTestSetup(array $specs): string
    {
        return "    protected function setUp(): void\n    {\n        parent::setUp();\n        // Test setup\n    }";
    }

    private function generateTestMethods(array $specs, array $patterns): string
    {
        return "    public function testExample(): void\n    {\n        \$this->assertTrue(true);\n    }";
    }

    private function generateTestTeardown(array $specs): string
    {
        return "    protected function tearDown(): void\n    {\n        // Test cleanup\n        parent::tearDown();\n    }";
    }

    private function generateApiImports(array $specs): string
    {
        return "use Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Response;";
    }

    private function determineHttpMethod(array $specs): string
    {
        $name = strtolower($specs['name']);
        if (strpos($name, 'create') !== false || strpos($name, 'store') !== false) return 'store';
        if (strpos($name, 'update') !== false) return 'update';
        if (strpos($name, 'delete') !== false) return 'destroy';
        if (strpos($name, 'show') !== false || strpos($name, 'get') !== false) return 'show';
        return 'index';
    }

    private function generateValidation(array $specs, array $patterns): string
    {
        return "        // Validation logic\n        \$request->validate([\n            // validation rules\n        ]);";
    }

    private function generateApiLogic(array $specs, array $patterns): string
    {
        return "        // Business logic\n        \$result = null; // TODO: Implement logic";
    }

    private function generateApiResponse(array $specs, array $patterns): string
    {
        return "        return response()->json([\n            'success' => true,\n            'data' => \$result\n        ]);";
    }

    private function determineClassDirectory(string $context): string
    {
        $directories = [
            'api' => 'Controllers',
            'service' => 'Services',
            'model' => 'Models',
            'repository' => 'Repositories',
            'middleware' => 'Middleware'
        ];
        
        return $directories[$context] ?? 'Classes';
    }

    private function applyPatternModifications(string $code, array $patterns, array $specs): string
    {
        // Apply learned patterns to improve generated code
        foreach ($patterns as $pattern) {
            if ($pattern['type'] === 'error_handling' && $pattern['confidence'] > 0.8) {
                $code = str_replace(
                    "throw new \\Exception('Not implemented');",
                    "throw new \\RuntimeException('Method not implemented: ' . __METHOD__);",
                    $code
                );
            }
        }
        
        return $code;
    }

    private function outputHumanResult(?SymfonyStyle $io, array $result): void
    {
        if (!$io) return;

        $io->success('✅ Code generated successfully');
        $io->text('Type: ' . $result['type']);
        $io->text('Name: ' . $result['name']);
        $io->text('Language: ' . $result['language']);
        $io->text('Confidence: ' . round($result['confidence_score'] * 100, 1) . '%');
        $io->text('Patterns Applied: ' . $result['patterns_applied']);
        
        if ($result['file_written']) {
            $io->text('✅ File written: ' . ($result['actual_path'] ?? $result['suggested_path']));
        } else {
            $io->text('📄 Suggested path: ' . $result['suggested_path']);
        }
        
        if (!empty($result['suggestions'])) {
            $io->newLine();
            $io->text('💡 Suggestions:');
            foreach ($result['suggestions'] as $suggestion) {
                $io->text('  • ' . $suggestion);
            }
        }
        
        if ($result['confidence_score'] < 0.7) {
            $io->warning('Low confidence score. Consider providing more context or running pattern analysis.');
        }
    }
}