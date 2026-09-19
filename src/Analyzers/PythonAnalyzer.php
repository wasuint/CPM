<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analyzers;

use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\FileAnalysis;

/**
 * Specialized analyzer for Python files using AST parsing
 * Extracts functions, classes, and performs Python-specific analysis
 */
class PythonAnalyzer
{
    private ConfigManager $config;
    private array $analysisRules;
    private string $pythonExecutable;
    private array $astCache;

    /**
     * Initializes Python analyzer with analysis rules
     *
     * @param ConfigManager $config Configuration manager for analysis rules
     */
    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->analysisRules = $config->getAnalysisRules('python');
        $this->pythonExecutable = 'python3';
        $this->astCache = [];
    }

    /**
     * Analyzes a Python file and extracts all relevant information
     *
     * @param string $filePath Path to the Python file to analyze
     * @return FileAnalysis Complete analysis results for the file
     */
    public function analyzeFile(string $filePath): FileAnalysis
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        try {
            $code = file_get_contents($filePath);
            $lines = count(file($filePath));
            $size = filesize($filePath);

            // Basic analysis using regex patterns for simplicity
            $functions = $this->extractFunctions($code);
            $classes = $this->extractClasses($code);

            $metrics = [
                'lines' => $lines,
                'size' => $size,
                'functions_count' => count($functions),
                'classes_count' => count($classes)
            ];

            $issues = [];
            if ($lines > 500) {
                $issues[] = [
                    'type' => 'file_size',
                    'severity' => 'warning',
                    'message' => "File exceeds 500 lines ({$lines} lines)"
                ];
            }

            return new FileAnalysis(
                $filePath,
                'python',
                $functions,
                $classes,
                $metrics,
                $issues
            );

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to analyze Python file {$filePath}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts functions from Python code using advanced pattern matching
     *
     * @param string $code Python source code
     * @return array Array of function information
     */
    private function extractFunctions(string $code): array
    {
        $functions = [];
        $lines = explode("\n", $code);
        $indentStack = [];
        $currentFunction = null;
        
        foreach ($lines as $lineNum => $line) {
            $indent = strlen($line) - strlen(ltrim($line));
            $trimmedLine = trim($line);
            
            // Regular function definitions
            if (preg_match('/^def\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)\s*(?:->\s*([^:]+))?\s*:/', $trimmedLine, $matches)) {
                $functionName = $matches[1];
                $visibility = $this->getVisibilityFromName($functionName);
                $context = implode('::', array_column($indentStack, 'name'));
                
                $currentFunction = [
                    'name' => $functionName,
                    'start_line' => $lineNum + 1,
                    'end_line' => $lineNum + 1, // Will be updated when we find the end
                    'parameters' => $this->parseParameters($matches[2]),
                    'return_type' => isset($matches[3]) ? trim($matches[3]) : null,
                    'visibility' => $visibility,
                    'type' => 'function',
                    'context' => $context,
                    'body' => '',
                    'decorators' => $this->extractDecorators($lines, $lineNum),
                    'async' => strpos($trimmedLine, 'async def') === 0
                ];
                
                $indentStack[] = ['indent' => $indent, 'name' => $functionName, 'line' => $lineNum + 1];
                $functions[] = &$currentFunction;
            }
            
            // Lambda functions
            elseif (preg_match('/lambda\s+([^:]*)\s*:\s*(.+)/', $trimmedLine, $matches)) {
                $lambdaParams = trim($matches[1]);
                $lambdaBody = trim($matches[2]);
                $context = implode('::', array_column($indentStack, 'name'));
                
                $functions[] = [
                    'name' => 'lambda@' . ($lineNum + 1),
                    'start_line' => $lineNum + 1,
                    'end_line' => $lineNum + 1,
                    'parameters' => $this->parseParameters($lambdaParams),
                    'return_type' => null,
                    'visibility' => 'public',
                    'type' => 'lambda',
                    'context' => $context,
                    'body' => $lambdaBody,
                    'decorators' => []
                ];
            }
            
            // Update current function body if we're inside one
            if ($currentFunction && $lineNum > $currentFunction['start_line'] - 1) {
                $currentFunction['body'] .= $line . "\n";
            }
            
            // Track context for nested functions/classes
            if (preg_match('/^class\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\(([^)]*)\))?\s*:/', $trimmedLine, $matches)) {
                $indentStack[] = ['indent' => $indent, 'name' => $matches[1], 'line' => $lineNum + 1];
            }
            
            // Pop from stack when indentation decreases (end of function/class)
            while (!empty($indentStack) && $indent <= end($indentStack)['indent'] && !empty($trimmedLine)) {
                $popped = array_pop($indentStack);
                // Update end line for functions
                foreach ($functions as &$func) {
                    if ($func['start_line'] == $popped['line'] && $func['end_line'] == $popped['line']) {
                        $func['end_line'] = $lineNum;
                    }
                }
            }
        }

        return $functions;
    }

    /**
     * Extracts classes from Python code using regex
     *
     * @param string $code Python source code
     * @return array Array of class information
     */
    private function extractClasses(string $code): array
    {
        $classes = [];
        $lines = explode("\n", $code);
        
        foreach ($lines as $lineNum => $line) {
            if (preg_match('/^\s*class\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\(([^)]*)\))?\s*:/', $line, $matches)) {
                $classes[] = [
                    'name' => $matches[1],
                    'line_start' => $lineNum + 1,
                    'line_end' => $lineNum + 1, // Simplified
                    'methods' => [], // Would need more complex parsing
                    'extends' => isset($matches[2]) ? trim($matches[2]) : null,
                    'implements' => []
                ];
            }
        }

        return $classes;
    }

    /**
     * Parses function parameters from parameter string
     *
     * @param string $paramString Parameter string from function definition
     * @return array Array of parameter information
     */
    private function parseParameters(string $paramString): array
    {
        if (empty(trim($paramString))) {
            return [];
        }

        $parameters = [];
        $params = array_map('trim', explode(',', $paramString));

        foreach ($params as $param) {
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(?::\s*([^=]+))?\s*(?:=\s*(.+))?$/', $param, $matches)) {
                $parameters[] = [
                    'name' => $matches[1],
                    'type' => isset($matches[2]) ? trim($matches[2]) : null,
                    'default' => isset($matches[3]) ? 'yes' : 'no'
                ];
            }
        }

        return $parameters;
    }
    
    /**
     * Determines visibility from function name (Python convention)
     *
     * @param string $functionName Function name
     * @return string Visibility level
     */
    private function getVisibilityFromName(string $functionName): string
    {
        if (str_starts_with($functionName, '__') && str_ends_with($functionName, '__')) {
            return 'special'; // Magic methods
        } elseif (str_starts_with($functionName, '__')) {
            return 'private'; // Name mangling
        } elseif (str_starts_with($functionName, '_')) {
            return 'protected'; // Convention for internal use
        }
        return 'public';
    }
    
    /**
     * Extracts decorators from lines preceding a function definition
     *
     * @param array $lines All lines of code
     * @param int $functionLineNum Line number of function definition
     * @return array Array of decorator strings
     */
    private function extractDecorators(array $lines, int $functionLineNum): array
    {
        $decorators = [];
        $lineNum = $functionLineNum - 1;
        
        // Look backwards for decorators
        while ($lineNum >= 0) {
            $line = trim($lines[$lineNum]);
            
            if (preg_match('/^@([a-zA-Z_][a-zA-Z0-9_.]*)(?:\(.*\))?$/', $line, $matches)) {
                $decorators[] = $matches[1];
                $lineNum--;
            } elseif (empty($line) || str_starts_with($line, '#')) {
                // Skip empty lines and comments
                $lineNum--;
            } else {
                // Found non-decorator, non-comment line
                break;
            }
        }
        
        return array_reverse($decorators); // Return in original order
    }
}