<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\DependencyExtractors;

/**
 * Extracts dependencies from Python files
 * Analyzes import statements and function/class declarations
 */
class PythonDependencyExtractor
{
    /**
     * Extract Python dependencies from file
     * @param string $filePath Path to Python file
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
        
        // Extract import statements
        $imports = array_merge($imports, $this->extractImports($content));
        
        // Extract class and function declarations (exports)
        $exports = $this->extractExports($content);
        
        return [
            'imports' => array_unique($imports),
            'exports' => $exports,
            'file_dependencies' => [] // Python doesn't have file includes like PHP
        ];
    }
    
    /**
     * Extract import statements from Python content
     * @param string $content Python file content
     * @return array List of imported modules/functions
     */
    private function extractImports(string $content): array
    {
        $imports = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // Handle "import module" statements
            if (preg_match('/^import\s+(.+)$/', $line, $matches)) {
                $modules = explode(',', $matches[1]);
                foreach ($modules as $module) {
                    $module = trim($module);
                    // Handle "import module as alias"
                    if (preg_match('/^(.+?)\s+as\s+(.+)$/', $module, $asMatch)) {
                        $imports[] = trim($asMatch[1]);
                    } else {
                        $imports[] = $module;
                    }
                }
            }
            
            // Handle "from module import ..." statements
            if (preg_match('/^from\s+(.+?)\s+import\s+(.+)$/', $line, $matches)) {
                $module = trim($matches[1]);
                $items = trim($matches[2]);
                
                if ($items === '*') {
                    // "from module import *" - import the whole module
                    $imports[] = $module;
                } else {
                    // "from module import item1, item2"
                    $importItems = explode(',', $items);
                    foreach ($importItems as $item) {
                        $item = trim($item);
                        // Handle "from module import item as alias"
                        if (preg_match('/^(.+?)\s+as\s+(.+)$/', $item, $asMatch)) {
                            $imports[] = $module . '.' . trim($asMatch[1]);
                        } else {
                            $imports[] = $module . '.' . $item;
                        }
                    }
                }
            }
        }
        
        return $imports;
    }
    
    /**
     * Extract class and function declarations (what this file exports)
     * @param string $content Python file content
     * @return array List of exported items
     */
    private function extractExports(string $content): array
    {
        $exports = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            $originalLine = $line;
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // Extract class declarations
            if (preg_match('/^class\s+(\w+)(?:\([^)]*\))?:\s*/', $line, $matches)) {
                $className = $matches[1];
                $visibility = $this->determinePythonVisibility($className);
                
                $exports[] = [
                    'type' => 'class',
                    'name' => $className,
                    'visibility' => $visibility,
                    'line' => $lineNum + 1
                ];
            }
            
            // Extract function declarations (including methods)
            if (preg_match('/^(?:async\s+)?def\s+(\w+)\s*\(/', $line, $matches)) {
                $functionName = $matches[1];
                $visibility = $this->determinePythonVisibility($functionName);
                $isAsync = str_contains($line, 'async def');
                $isMethod = $this->isInsideClass($lines, $lineNum);
                
                // Skip magic methods but include __init__
                if (!$this->shouldSkipFunction($functionName)) {
                    $exports[] = [
                        'type' => $isMethod ? 'method' : 'function',
                        'name' => $functionName,
                        'visibility' => $visibility,
                        'is_async' => $isAsync,
                        'line' => $lineNum + 1
                    ];
                }
            }
            
            // Extract variable assignments at module level (potential exports)
            if (preg_match('/^(\w+)\s*=/', $line, $matches) && !$this->isInsideClassOrFunction($lines, $lineNum)) {
                $varName = $matches[1];
                $visibility = $this->determinePythonVisibility($varName);
                
                // Only include if it looks like a constant or important variable
                if (ctype_upper($varName) || !str_starts_with($varName, '_')) {
                    $exports[] = [
                        'type' => 'variable',
                        'name' => $varName,
                        'visibility' => $visibility,
                        'line' => $lineNum + 1
                    ];
                }
            }
        }
        
        return $exports;
    }
    
    /**
     * Determine Python visibility based on naming conventions
     * @param string $name Item name
     * @return string Visibility (public, private, protected)
     */
    private function determinePythonVisibility(string $name): string
    {
        if (str_starts_with($name, '__') && str_ends_with($name, '__')) {
            return 'magic'; // Magic methods like __init__
        }
        
        if (str_starts_with($name, '__')) {
            return 'private'; // Name mangling
        }
        
        if (str_starts_with($name, '_')) {
            return 'protected'; // Protected convention
        }
        
        return 'public';
    }
    
    /**
     * Check if a line is inside a class definition
     * @param array $lines All file lines
     * @param int $currentLine Current line number
     * @return bool True if inside class
     */
    private function isInsideClass(array $lines, int $currentLine): bool
    {
        // Look backwards for class definition
        for ($i = $currentLine - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // If we find a class definition and current line is indented, we're inside
            if (preg_match('/^class\s+\w+/', $line)) {
                $currentIndent = $this->getIndentLevel($lines[$currentLine]);
                $classIndent = $this->getIndentLevel($lines[$i]);
                return $currentIndent > $classIndent;
            }
            
            // If we find another function/class at same or lower indent level, we're not inside original class
            if (preg_match('/^(?:class|def)\s+/', $line)) {
                $currentIndent = $this->getIndentLevel($lines[$currentLine]);
                $otherIndent = $this->getIndentLevel($lines[$i]);
                if ($otherIndent <= $currentIndent) {
                    return false;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if a line is inside a class or function definition
     * @param array $lines All file lines
     * @param int $currentLine Current line number
     * @return bool True if inside class or function
     */
    private function isInsideClassOrFunction(array $lines, int $currentLine): bool
    {
        // Look backwards for class/function definition
        for ($i = $currentLine - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            // If we find a class/function definition and current line is indented, we're inside
            if (preg_match('/^(?:class|def)\s+/', $line)) {
                $currentIndent = $this->getIndentLevel($lines[$currentLine]);
                $definitionIndent = $this->getIndentLevel($lines[$i]);
                return $currentIndent > $definitionIndent;
            }
        }
        
        return false;
    }
    
    /**
     * Get indentation level of a line
     * @param string $line Line to analyze
     * @return int Indentation level
     */
    private function getIndentLevel(string $line): int
    {
        $spaces = 0;
        for ($i = 0; $i < strlen($line); $i++) {
            if ($line[$i] === ' ') {
                $spaces++;
            } elseif ($line[$i] === "\t") {
                $spaces += 4; // Treat tab as 4 spaces
            } else {
                break;
            }
        }
        return $spaces;
    }
    
    /**
     * Check if function should be skipped from exports
     * @param string $functionName Function name
     * @return bool True if should skip
     */
    private function shouldSkipFunction(string $functionName): bool
    {
        $skipPatterns = [
            '__str__', '__repr__', '__len__', '__iter__', '__next__',
            '__enter__', '__exit__', '__call__', '__getitem__', '__setitem__',
            '__delitem__', '__contains__', '__add__', '__sub__', '__mul__',
            '__div__', '__eq__', '__ne__', '__lt__', '__le__', '__gt__', '__ge__'
        ];
        
        // Keep __init__ but skip other common magic methods
        if ($functionName === '__init__') {
            return false;
        }
        
        return in_array($functionName, $skipPatterns);
    }
}