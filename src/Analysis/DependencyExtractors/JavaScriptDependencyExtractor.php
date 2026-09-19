<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\DependencyExtractors;

/**
 * Extracts dependencies from JavaScript/TypeScript files
 * Analyzes import/export statements and function/class declarations
 */
class JavaScriptDependencyExtractor
{
    /**
     * Extract JavaScript/TypeScript dependencies from file
     * @param string $filePath Path to JS/TS file
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
        
        // Remove comments to avoid false matches
        $content = $this->removeComments($content);
        
        $imports = [];
        $exports = [];
        
        // Extract import statements
        $imports = array_merge($imports, $this->extractImports($content));
        
        // Extract require statements (Node.js style)
        $imports = array_merge($imports, $this->extractRequires($content));
        
        // Extract exports
        $exports = $this->extractExports($content);
        
        return [
            'imports' => array_unique($imports),
            'exports' => $exports,
            'file_dependencies' => [] // JS doesn't have file includes like PHP
        ];
    }
    
    /**
     * Extract ES6+ import statements
     * @param string $content JavaScript/TypeScript content
     * @return array List of imported modules
     */
    private function extractImports(string $content): array
    {
        $imports = [];
        
        // Match various import patterns
        $patterns = [
            // import defaultExport from "module-name"
            '/import\s+(\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/i',
            // import * as name from "module-name"
            '/import\s+\*\s+as\s+\w+\s+from\s+[\'"]([^\'"]+)[\'"]/i',
            // import { export1 } from "module-name"
            '/import\s+\{[^}]+\}\s+from\s+[\'"]([^\'"]+)[\'"]/i',
            // import { export1 as alias1 } from "module-name"
            '/import\s+\{[^}]*\}\s+from\s+[\'"]([^\'"]+)[\'"]/i',
            // import defaultExport, { export1 } from "module-name"
            '/import\s+\w+\s*,\s*\{[^}]*\}\s+from\s+[\'"]([^\'"]+)[\'"]/i',
            // import "module-name" (side-effect import)
            '/import\s+[\'"]([^\'"]+)[\'"]/i'
        ];
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] as $match) {
                $imports[] = $this->normalizeModuleName($match);
            }
        }
        
        return $imports;
    }
    
    /**
     * Extract CommonJS require statements
     * @param string $content JavaScript content
     * @return array List of required modules
     */
    private function extractRequires(string $content): array
    {
        $imports = [];
        
        // Match require statements
        preg_match_all('/require\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $content, $matches);
        foreach ($matches[1] as $match) {
            $imports[] = $this->normalizeModuleName($match);
        }
        
        return $imports;
    }
    
    /**
     * Extract exports from JavaScript/TypeScript content
     * @param string $content JavaScript/TypeScript content
     * @return array List of exported items
     */
    private function extractExports(string $content): array
    {
        $exports = [];
        
        // Extract function declarations
        $exports = array_merge($exports, $this->extractFunctions($content));
        
        // Extract class declarations
        $exports = array_merge($exports, $this->extractClasses($content));
        
        // Extract variable declarations
        $exports = array_merge($exports, $this->extractVariables($content));
        
        // Extract explicit exports
        $exports = array_merge($exports, $this->extractExplicitExports($content));
        
        return $exports;
    }
    
    /**
     * Extract function declarations
     * @param string $content JavaScript/TypeScript content
     * @return array List of functions
     */
    private function extractFunctions(string $content): array
    {
        $functions = [];
        
        // Regular function declarations
        preg_match_all('/(?:export\s+)?(?:async\s+)?function\s+(\w+)\s*\(/i', $content, $matches);
        foreach ($matches[1] as $functionName) {
            $isExported = $this->isExported($content, $functionName, 'function');
            $functions[] = [
                'type' => 'function',
                'name' => $functionName,
                'visibility' => $isExported ? 'public' : 'private',
                'is_async' => $this->isAsync($content, $functionName, 'function')
            ];
        }
        
        // Arrow functions assigned to variables
        preg_match_all('/(?:export\s+)?(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s+)?\([^)]*\)\s*=>/i', $content, $matches);
        foreach ($matches[1] as $functionName) {
            $isExported = $this->isExported($content, $functionName, 'variable');
            $functions[] = [
                'type' => 'function',
                'name' => $functionName,
                'visibility' => $isExported ? 'public' : 'private',
                'is_arrow' => true,
                'is_async' => $this->isAsync($content, $functionName, 'variable')
            ];
        }
        
        return $functions;
    }
    
    /**
     * Extract class declarations
     * @param string $content JavaScript/TypeScript content
     * @return array List of classes
     */
    private function extractClasses(string $content): array
    {
        $classes = [];
        
        preg_match_all('/(?:export\s+)?(?:abstract\s+)?class\s+(\w+)(?:\s+extends\s+\w+)?(?:\s+implements\s+[\w,\s]+)?\s*\{/i', $content, $matches);
        foreach ($matches[1] as $className) {
            $isExported = $this->isExported($content, $className, 'class');
            $classes[] = [
                'type' => 'class',
                'name' => $className,
                'visibility' => $isExported ? 'public' : 'private'
            ];
        }
        
        return $classes;
    }
    
    /**
     * Extract variable declarations
     * @param string $content JavaScript/TypeScript content
     * @return array List of variables
     */
    private function extractVariables(string $content): array
    {
        $variables = [];
        
        // Extract const/let/var declarations at top level
        preg_match_all('/(?:export\s+)?(?:const|let|var)\s+(\w+)(?:\s*:\s*\w+)?\s*=/i', $content, $matches);
        foreach ($matches[1] as $varName) {
            // Skip if it's a function (already captured)
            if (!preg_match('/' . preg_quote($varName) . '\s*=\s*(?:async\s+)?\([^)]*\)\s*=>/', $content) &&
                !preg_match('/' . preg_quote($varName) . '\s*=\s*(?:async\s+)?function/', $content)) {
                
                $isExported = $this->isExported($content, $varName, 'variable');
                $variables[] = [
                    'type' => 'variable',
                    'name' => $varName,
                    'visibility' => $isExported ? 'public' : 'private'
                ];
            }
        }
        
        return $variables;
    }
    
    /**
     * Extract explicit export statements
     * @param string $content JavaScript/TypeScript content
     * @return array List of explicit exports
     */
    private function extractExplicitExports(string $content): array
    {
        $exports = [];
        
        // export { name1, name2 }
        preg_match_all('/export\s*\{\s*([^}]+)\s*\}/i', $content, $matches);
        foreach ($matches[1] as $exportList) {
            $items = explode(',', $exportList);
            foreach ($items as $item) {
                $item = trim($item);
                // Handle "name as alias"
                if (preg_match('/^(\w+)(?:\s+as\s+\w+)?$/', $item, $itemMatch)) {
                    $exports[] = [
                        'type' => 'export',
                        'name' => $itemMatch[1],
                        'visibility' => 'public'
                    ];
                }
            }
        }
        
        // export default
        if (preg_match('/export\s+default\s+(\w+)/i', $content, $matches)) {
            $exports[] = [
                'type' => 'default_export',
                'name' => $matches[1],
                'visibility' => 'public'
            ];
        }
        
        return $exports;
    }
    
    /**
     * Check if an item is exported
     * @param string $content File content
     * @param string $name Item name
     * @param string $type Item type (function, class, variable)
     * @return bool True if exported
     */
    private function isExported(string $content, string $name, string $type): bool
    {
        // Check for export keyword before declaration
        $patterns = [
            '/export\s+(?:async\s+)?function\s+' . preg_quote($name) . '/i',
            '/export\s+(?:abstract\s+)?class\s+' . preg_quote($name) . '/i',
            '/export\s+(?:const|let|var)\s+' . preg_quote($name) . '/i',
            '/export\s*\{[^}]*\b' . preg_quote($name) . '\b[^}]*\}/i',
            '/export\s+default\s+' . preg_quote($name) . '/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a function is async
     * @param string $content File content
     * @param string $name Function name
     * @param string $type Declaration type
     * @return bool True if async
     */
    private function isAsync(string $content, string $name, string $type): bool
    {
        $patterns = [
            '/(?:export\s+)?async\s+function\s+' . preg_quote($name) . '/i',
            '/(?:export\s+)?(?:const|let|var)\s+' . preg_quote($name) . '\s*=\s*async\s+/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Normalize module name
     * @param string $moduleName Raw module name
     * @return string Normalized module name
     */
    private function normalizeModuleName(string $moduleName): string
    {
        return trim($moduleName, '"\'');
    }
    
    /**
     * Remove comments from JavaScript/TypeScript content
     * @param string $content Original content
     * @return string Content without comments
     */
    private function removeComments(string $content): string
    {
        // Remove single-line comments
        $content = preg_replace('/\/\/.*$/m', '', $content);
        
        // Remove multi-line comments
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        return $content;
    }
}