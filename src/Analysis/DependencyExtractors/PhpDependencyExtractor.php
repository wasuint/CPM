<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\DependencyExtractors;

/**
 * Extracts dependencies from PHP files
 * Analyzes use statements, require/include statements, and class/function declarations
 */
class PhpDependencyExtractor
{
    /**
     * Extract PHP dependencies from file
     * @param string $filePath Path to PHP file
     * @return array Dependencies and exports
     */
    public function extractDependencies(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Could not read file: {$filePath}");
        }
        
        $imports = [];
        $exports = [];
        $fileIncludes = [];
        
        // Extract use statements
        $imports = array_merge($imports, $this->extractUseStatements($content));
        
        // Extract require/include statements
        $fileIncludes = $this->extractFileIncludes($content);
        
        // Extract class/function declarations (exports)
        $exports = $this->extractExports($content);
        
        return [
            'imports' => array_unique($imports),
            'exports' => $exports,
            'file_dependencies' => array_unique($fileIncludes)
        ];
    }
    
    /**
     * Extract use statements from PHP content
     * @param string $content PHP file content
     * @return array List of imported classes/functions
     */
    private function extractUseStatements(string $content): array
    {
        $imports = [];
        
        // Match use statements
        preg_match_all('/use\s+([^;]+);/i', $content, $useMatches);
        foreach ($useMatches[1] as $use) {
            $use = trim($use);
            
            // Handle "use Foo\Bar as Baz"
            if (preg_match('/^(.+?)\s+as\s+(.+)$/', $use, $asMatch)) {
                $imports[] = $this->normalizeClassName(trim($asMatch[1]));
            } else {
                $imports[] = $this->normalizeClassName($use);
            }
        }
        
        // Match grouped use statements: use Foo\{Bar, Baz};
        preg_match_all('/use\s+([^{]+)\{([^}]+)\};/i', $content, $groupMatches);
        for ($i = 0; $i < count($groupMatches[0]); $i++) {
            $namespace = trim($groupMatches[1][$i]);
            $classes = explode(',', $groupMatches[2][$i]);
            
            foreach ($classes as $class) {
                $class = trim($class);
                // Handle "Bar as Baz" within group
                if (preg_match('/^(.+?)\s+as\s+(.+)$/', $class, $asMatch)) {
                    $imports[] = $this->normalizeClassName($namespace . trim($asMatch[1]));
                } else {
                    $imports[] = $this->normalizeClassName($namespace . $class);
                }
            }
        }
        
        return $imports;
    }
    
    /**
     * Extract require/include statements
     * @param string $content PHP file content
     * @return array List of included files
     */
    private function extractFileIncludes(string $content): array
    {
        $includes = [];
        
        // Match require/include statements
        preg_match_all('/(require|include)(?:_once)?\s*\(?[\'"]([^\'"]+)[\'"]\)?;/i', $content, $matches);
        
        foreach ($matches[2] as $path) {
            $includes[] = $this->normalizePath($path);
        }
        
        return $includes;
    }
    
    /**
     * Extract class and function declarations (what this file exports)
     * @param string $content PHP file content
     * @return array List of exported items
     */
    private function extractExports(string $content): array
    {
        $exports = [];
        
        // Extract class declarations
        preg_match_all('/(class|interface|trait)\s+(\w+)/i', $content, $classMatches);
        for ($i = 0; $i < count($classMatches[0]); $i++) {
            $exports[] = [
                'type' => strtolower($classMatches[1][$i]),
                'name' => $classMatches[2][$i],
                'visibility' => 'public' // Classes are generally public
            ];
        }
        
        // Extract function declarations
        preg_match_all('/function\s+(\w+)\s*\(/i', $content, $functionMatches);
        foreach ($functionMatches[1] as $functionName) {
            // Skip magic methods and constructors
            if (!in_array($functionName, ['__construct', '__destruct', '__call', '__get', '__set'])) {
                $exports[] = [
                    'type' => 'function',
                    'name' => $functionName,
                    'visibility' => $this->determineFunctionVisibility($content, $functionName)
                ];
            }
        }
        
        // Extract constants
        preg_match_all('/const\s+(\w+)\s*=/i', $content, $constMatches);
        foreach ($constMatches[1] as $constName) {
            $exports[] = [
                'type' => 'constant',
                'name' => $constName,
                'visibility' => 'public'
            ];
        }
        
        return $exports;
    }
    
    /**
     * Normalize class name by removing leading backslashes
     * @param string $className Class name to normalize
     * @return string Normalized class name
     */
    private function normalizeClassName(string $className): string
    {
        return ltrim(trim($className), '\\');
    }
    
    /**
     * Normalize file path
     * @param string $path File path to normalize
     * @return string Normalized path
     */
    private function normalizePath(string $path): string
    {
        // Convert relative paths and resolve basic path operations
        $path = str_replace(['\\', '//'], '/', $path);
        
        // Remove quotes if present
        $path = trim($path, '"\'');
        
        return $path;
    }
    
    /**
     * Determine function visibility by analyzing context
     * @param string $content File content
     * @param string $functionName Function name
     * @return string Visibility (public, private, protected)
     */
    private function determineFunctionVisibility(string $content, string $functionName): string
    {
        // Look for the function declaration with visibility modifiers
        if (preg_match('/(?:public|private|protected)\s+function\s+' . preg_quote($functionName) . '\s*\(/i', $content, $matches)) {
            preg_match('/(?:public|private|protected)/i', $matches[0], $visibilityMatch);
            return strtolower($visibilityMatch[0] ?? 'public');
        }
        
        // If no explicit visibility, assume public for global functions, private for class methods
        if (preg_match('/class\s+\w+.*?{.*?function\s+' . preg_quote($functionName) . '/si', $content)) {
            return 'private'; // Method without explicit visibility in class
        }
        
        return 'public'; // Global function
    }
}