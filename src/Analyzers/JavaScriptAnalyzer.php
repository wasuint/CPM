<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analyzers;

use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\FileAnalysis;

/**
 * Specialized analyzer for JavaScript/TypeScript files
 * Extracts functions, classes, arrow functions, and async functions
 */
class JavaScriptAnalyzer
{
    private ConfigManager $config;
    private array $analysisRules;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->analysisRules = $config->getAnalysisRules('javascript');
    }

    /**
     * Analyzes a JavaScript/TypeScript file and extracts all relevant information
     *
     * @param string $filePath Path to the JS/TS file to analyze
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
            $isTypeScript = str_ends_with($filePath, '.ts') || str_ends_with($filePath, '.tsx');

            $functions = $this->extractFunctions($code, $isTypeScript);
            $classes = $this->extractClasses($code, $isTypeScript);

            $metrics = [
                'lines' => $lines,
                'size' => $size,
                'functions_count' => count($functions),
                'classes_count' => count($classes),
                'is_typescript' => $isTypeScript
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
                $isTypeScript ? 'typescript' : 'javascript',
                $functions,
                $classes,
                $metrics,
                $issues
            );

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to analyze JS/TS file {$filePath}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extracts functions from JavaScript/TypeScript code
     *
     * @param string $code JavaScript/TypeScript source code
     * @param bool $isTypeScript Whether this is TypeScript
     * @return array Array of function information
     */
    private function extractFunctions(string $code, bool $isTypeScript): array
    {
        $functions = [];
        $lines = explode("\n", $code);
        $bracketStack = 0;
        $currentFunction = null;
        
        foreach ($lines as $lineNum => $line) {
            $trimmedLine = trim($line);
            
            // Regular function declarations
            if (preg_match('/^\s*(?:export\s+)?(?:async\s+)?function\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*\(([^)]*)\)(?:\s*:\s*([^{]+))?\s*\{?/', $line, $matches)) {
                $functions[] = [
                    'name' => $matches[1],
                    'start_line' => $lineNum + 1,
                    'end_line' => $this->findFunctionEnd($lines, $lineNum),
                    'parameters' => $this->parseJSParameters($matches[2], $isTypeScript),
                    'return_type' => $isTypeScript && isset($matches[3]) ? trim($matches[3]) : null,
                    'type' => 'function',
                    'async' => str_contains($line, 'async'),
                    'exported' => str_contains($line, 'export'),
                    'body' => $this->extractFunctionBody($lines, $lineNum)
                ];
            }
            
            // Arrow functions - assigned to variables
            elseif (preg_match('/^\s*(?:const|let|var)\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*=\s*(?:async\s+)?\(([^)]*)\)(?:\s*:\s*([^=]+))?\s*=>\s*/', $line, $matches)) {
                $functions[] = [
                    'name' => $matches[1],
                    'start_line' => $lineNum + 1,
                    'end_line' => $this->findArrowFunctionEnd($lines, $lineNum),
                    'parameters' => $this->parseJSParameters($matches[2], $isTypeScript),
                    'return_type' => $isTypeScript && isset($matches[3]) ? trim($matches[3]) : null,
                    'type' => 'arrow_function',
                    'async' => str_contains($line, 'async'),
                    'exported' => false,
                    'body' => $this->extractArrowFunctionBody($lines, $lineNum)
                ];
            }
            
            // Arrow functions - property assignments
            elseif (preg_match('/^\s*([a-zA-Z_$][a-zA-Z0-9_$]*)\s*:\s*(?:async\s+)?\(([^)]*)\)(?:\s*:\s*([^=]+))?\s*=>\s*/', $line, $matches)) {
                $functions[] = [
                    'name' => $matches[1],
                    'start_line' => $lineNum + 1,
                    'end_line' => $this->findArrowFunctionEnd($lines, $lineNum),
                    'parameters' => $this->parseJSParameters($matches[2], $isTypeScript),
                    'return_type' => $isTypeScript && isset($matches[3]) ? trim($matches[3]) : null,
                    'type' => 'arrow_function',
                    'async' => str_contains($line, 'async'),
                    'exported' => false,
                    'body' => $this->extractArrowFunctionBody($lines, $lineNum)
                ];
            }
            
            // Anonymous arrow functions in callbacks
            elseif (preg_match('/(?:async\s+)?\(([^)]*)\)(?:\s*:\s*([^=]+))?\s*=>\s*/', $line, $matches)) {
                $functions[] = [
                    'name' => 'anonymous@' . ($lineNum + 1),
                    'start_line' => $lineNum + 1,
                    'end_line' => $this->findArrowFunctionEnd($lines, $lineNum),
                    'parameters' => $this->parseJSParameters($matches[1], $isTypeScript),
                    'return_type' => $isTypeScript && isset($matches[2]) ? trim($matches[2]) : null,
                    'type' => 'anonymous_arrow',
                    'async' => str_contains($line, 'async'),
                    'exported' => false,
                    'body' => $this->extractArrowFunctionBody($lines, $lineNum)
                ];
            }
        }

        return $functions;
    }

    /**
     * Extracts classes from JavaScript/TypeScript code
     *
     * @param string $code JavaScript/TypeScript source code
     * @param bool $isTypeScript Whether this is TypeScript
     * @return array Array of class information
     */
    private function extractClasses(string $code, bool $isTypeScript): array
    {
        $classes = [];
        $lines = explode("\n", $code);
        
        foreach ($lines as $lineNum => $line) {
            if (preg_match('/^\s*(?:export\s+)?class\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*(?:extends\s+([a-zA-Z_$][a-zA-Z0-9_$.]*))?\s*(?:implements\s+([^{]+))?\s*\{/', $line, $matches)) {
                $className = $matches[1];
                $extends = isset($matches[2]) ? trim($matches[2]) : null;
                $implements = isset($matches[3]) ? array_map('trim', explode(',', $matches[3])) : [];
                
                $methods = $this->extractClassMethods($lines, $lineNum, $isTypeScript);
                
                $classes[] = [
                    'name' => $className,
                    'start_line' => $lineNum + 1,
                    'end_line' => $this->findClassEnd($lines, $lineNum),
                    'methods' => $methods,
                    'extends' => $extends,
                    'implements' => $implements,
                    'exported' => str_contains($line, 'export')
                ];
            }
        }

        return $classes;
    }

    /**
     * Extracts methods from a class definition
     *
     * @param array $lines All lines of code
     * @param int $classStartLine Line where class starts
     * @param bool $isTypeScript Whether this is TypeScript
     * @return array Array of method information
     */
    private function extractClassMethods(array $lines, int $classStartLine, bool $isTypeScript): array
    {
        $methods = [];
        $classEndLine = $this->findClassEnd($lines, $classStartLine);
        
        for ($lineNum = $classStartLine + 1; $lineNum < $classEndLine; $lineNum++) {
            $line = $lines[$lineNum];
            $trimmedLine = trim($line);
            
            // Method definitions
            if (preg_match('/^\s*(?:(static|private|protected|public)\s+)?(?:async\s+)?([a-zA-Z_$][a-zA-Z0-9_$]*)\s*\(([^)]*)\)(?:\s*:\s*([^{]+))?\s*\{/', $line, $matches)) {
                $visibility = $matches[1] ?: 'public';
                $methodName = $matches[2];
                $parameters = $matches[3];
                $returnType = $isTypeScript && isset($matches[4]) ? trim($matches[4]) : null;
                
                $methods[] = [
                    'name' => $methodName,
                    'start_line' => $lineNum + 1,
                    'end_line' => $this->findFunctionEnd($lines, $lineNum),
                    'parameters' => $this->parseJSParameters($parameters, $isTypeScript),
                    'return_type' => $returnType,
                    'visibility' => $visibility,
                    'static' => str_contains($line, 'static'),
                    'async' => str_contains($line, 'async'),
                    'type' => 'method',
                    'body' => $this->extractFunctionBody($lines, $lineNum)
                ];
            }
        }
        
        return $methods;
    }

    /**
     * Parse JavaScript/TypeScript function parameters
     *
     * @param string $paramString Parameter string
     * @param bool $isTypeScript Whether this is TypeScript
     * @return array Array of parameter information
     */
    private function parseJSParameters(string $paramString, bool $isTypeScript): array
    {
        if (empty(trim($paramString))) {
            return [];
        }

        $parameters = [];
        $params = $this->splitParameters($paramString);

        foreach ($params as $param) {
            $param = trim($param);
            
            if ($isTypeScript && preg_match('/^([a-zA-Z_$][a-zA-Z0-9_$]*)\s*\??\s*:\s*([^=]+)(?:\s*=\s*(.+))?$/', $param, $matches)) {
                // TypeScript parameter with type
                $parameters[] = [
                    'name' => $matches[1],
                    'type' => trim($matches[2]),
                    'optional' => str_contains($param, '?'),
                    'default' => isset($matches[3]) ? 'yes' : 'no'
                ];
            } elseif (preg_match('/^([a-zA-Z_$][a-zA-Z0-9_$]*)\s*(?:=\s*(.+))?$/', $param, $matches)) {
                // JavaScript parameter or TypeScript without explicit type
                $parameters[] = [
                    'name' => $matches[1],
                    'type' => null,
                    'optional' => false,
                    'default' => isset($matches[2]) ? 'yes' : 'no'
                ];
            }
        }

        return $parameters;
    }

    /**
     * Split parameter string handling nested parentheses and commas
     */
    private function splitParameters(string $paramString): array
    {
        $params = [];
        $current = '';
        $depth = 0;
        
        for ($i = 0; $i < strlen($paramString); $i++) {
            $char = $paramString[$i];
            
            if ($char === ',' && $depth === 0) {
                $params[] = $current;
                $current = '';
            } else {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                }
                $current .= $char;
            }
        }
        
        if (!empty($current)) {
            $params[] = $current;
        }
        
        return $params;
    }

    /**
     * Find the end line of a function by tracking braces
     */
    private function findFunctionEnd(array $lines, int $startLine): int
    {
        $braceCount = 0;
        $started = false;
        
        for ($i = $startLine; $i < count($lines); $i++) {
            $line = $lines[$i];
            
            for ($j = 0; $j < strlen($line); $j++) {
                $char = $line[$j];
                if ($char === '{') {
                    $braceCount++;
                    $started = true;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($started && $braceCount === 0) {
                        return $i + 1;
                    }
                }
            }
        }
        
        return $startLine + 1; // Fallback
    }

    /**
     * Find the end line of an arrow function
     */
    private function findArrowFunctionEnd(array $lines, int $startLine): int
    {
        $line = $lines[$startLine];
        
        // If it starts with {, find matching }
        if (str_contains($line, '{')) {
            return $this->findFunctionEnd($lines, $startLine);
        }
        
        // Single expression arrow function - likely ends on same line or continues
        // Look for semicolon or next statement
        for ($i = $startLine; $i < count($lines); $i++) {
            if (str_ends_with(trim($lines[$i]), ';') || str_ends_with(trim($lines[$i]), ',')) {
                return $i + 1;
            }
        }
        
        return $startLine + 1;
    }

    /**
     * Find the end line of a class
     */
    private function findClassEnd(array $lines, int $startLine): int
    {
        return $this->findFunctionEnd($lines, $startLine);
    }

    /**
     * Extract function body as string
     */
    private function extractFunctionBody(array $lines, int $startLine): string
    {
        $endLine = $this->findFunctionEnd($lines, $startLine);
        $bodyLines = [];
        
        for ($i = $startLine; $i < $endLine; $i++) {
            $bodyLines[] = $lines[$i];
        }
        
        return implode("\n", $bodyLines);
    }

    /**
     * Extract arrow function body as string
     */
    private function extractArrowFunctionBody(array $lines, int $startLine): string
    {
        $line = $lines[$startLine];
        
        // Extract everything after =>
        if (preg_match('/=>\s*(.*)$/', $line, $matches)) {
            $body = $matches[1];
            
            // If it doesn't start with {, it's likely a single expression
            if (!str_starts_with(trim($body), '{')) {
                return $body;
            }
        }
        
        // Multi-line arrow function
        return $this->extractFunctionBody($lines, $startLine);
    }
}