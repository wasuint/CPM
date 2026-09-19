<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Analysis\DependencyExtractors\PhpDependencyExtractor;
use ClaudeProjectManager\Analysis\DependencyExtractors\PythonDependencyExtractor;
use ClaudeProjectManager\Analysis\DependencyExtractors\JavaScriptDependencyExtractor;

/**
 * Analyzes import/export relationships between files
 * Builds dependency graph for impact analysis
 */
class DependencyAnalyzer
{
    private DatabaseManager $database;
    private array $languageExtractors;
    
    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
        $this->languageExtractors = [
            'php' => new PhpDependencyExtractor(),
            'python' => new PythonDependencyExtractor(),
            'javascript' => new JavaScriptDependencyExtractor(),
            'typescript' => new JavaScriptDependencyExtractor()
        ];
    }

    /**
     * Analyze all project files for dependencies
     * @param array $files List of files to analyze
     * @return array Complete dependency mapping
     */
    public function analyzeDependencies(array $files): array
    {
        $dependencies = [];
        $dependents = [];
        
        foreach ($files as $filePath => $fileData) {
            $language = $this->detectLanguage($filePath);
            if (!isset($this->languageExtractors[$language])) {
                continue;
            }
            
            try {
                $fileDeps = $this->languageExtractors[$language]->extractDependencies($filePath);
                $dependencies[$filePath] = array_merge($fileDeps, [
                    'language' => $language,
                    'dependency_depth' => 0 // Will be calculated later
                ]);
                
                // Build reverse mapping (who depends on this file)
                foreach ($fileDeps['imports'] as $imported) {
                    if (!isset($dependents[$imported])) {
                        $dependents[$imported] = [];
                    }
                    $dependents[$imported][] = $filePath;
                }
                
            } catch (\Exception $e) {
                // Log error but continue processing other files
                error_log("Error analyzing dependencies for {$filePath}: " . $e->getMessage());
                continue;
            }
        }
        
        // Add reverse dependency information
        foreach ($dependencies as $filePath => $fileData) {
            $dependencies[$filePath]['imported_by'] = $dependents[$filePath] ?? [];
        }
        
        // Calculate dependency depths
        $this->calculateDependencyDepths($dependencies);
        
        $result = [
            'generated_at' => date('c'),
            'files' => $dependencies,
            'dependency_graph' => $this->buildDependencyGraph($dependencies)
        ];
        
        // Store in database
        $this->database->write('dependencies', $result);
        
        return $result;
    }

    /**
     * Get dependencies for a specific file
     * @param string $filePath Path to file
     * @return array|null File dependency data
     */
    public function getFileDependencies(string $filePath): ?array
    {
        $dependencies = $this->database->read('dependencies');
        return $dependencies['files'][$filePath] ?? null;
    }

    /**
     * Find all files that depend on the given file
     * @param string $filePath Path to file
     * @return array List of dependent files
     */
    public function findDependentFiles(string $filePath): array
    {
        $dependencies = $this->database->read('dependencies');
        return $dependencies['files'][$filePath]['imported_by'] ?? [];
    }

    /**
     * Detect circular dependencies in the project
     * @return array List of circular dependency chains
     */
    public function detectCircularDependencies(?array $dependencyData = null): array
    {
        if ($dependencyData === null) {
            $dependencies = $this->database->read('dependencies');
            $files = $dependencies['files'] ?? [];
        } else {
            $files = $dependencyData;
        }

        $circular = [];
        $visited = [];
        $recursionStack = [];
        
        foreach ($files as $file => $data) {
            if (!isset($visited[$file])) {
                $cycle = $this->detectCycleRecursive($file, $files, $visited, $recursionStack, []);
                if (!empty($cycle)) {
                    $circular[] = $cycle;
                }
            }
        }
        
        return $circular;
    }

    /**
     * Detect programming language based on file extension
     * @param string $filePath Path to file
     * @return string Language identifier
     */
    private function detectLanguage(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $languageMap = [
            'php' => 'php',
            'py' => 'python',
            'js' => 'javascript',
            'jsx' => 'javascript',
            'ts' => 'typescript',
            'tsx' => 'typescript'
        ];
        
        return $languageMap[$extension] ?? 'unknown';
    }

    /**
     * Calculate dependency depths for all files
     * @param array &$dependencies Dependencies array to modify
     */
    private function calculateDependencyDepths(array &$dependencies): void
    {
        $calculated = [];
        
        foreach ($dependencies as $filePath => $fileData) {
            if (!isset($calculated[$filePath])) {
                $depth = $this->calculateDepthRecursive($filePath, $dependencies, $calculated, []);
                $dependencies[$filePath]['dependency_depth'] = $depth;
            }
        }
    }

    /**
     * Recursively calculate dependency depth for a file
     * @param string $filePath Current file
     * @param array $dependencies All dependencies
     * @param array &$calculated Already calculated depths
     * @param array $visiting Currently visiting (to detect cycles)
     * @return int Dependency depth
     */
    private function calculateDepthRecursive(string $filePath, array $dependencies, array &$calculated, array $visiting): int
    {
        if (isset($calculated[$filePath])) {
            return $calculated[$filePath];
        }
        
        if (in_array($filePath, $visiting)) {
            // Circular dependency detected
            return 0;
        }
        
        $visiting[] = $filePath;
        $maxDepth = 0;
        
        $imports = $dependencies[$filePath]['imports'] ?? [];
        foreach ($imports as $import) {
            // Try to find the actual file path for this import
            $importFile = $this->resolveImportToFile($import, $dependencies);
            if ($importFile && isset($dependencies[$importFile])) {
                $depth = $this->calculateDepthRecursive($importFile, $dependencies, $calculated, $visiting);
                $maxDepth = max($maxDepth, $depth + 1);
            }
        }
        
        array_pop($visiting);
        $calculated[$filePath] = $maxDepth;
        
        return $maxDepth;
    }

    /**
     * Try to resolve an import name to actual file path
     * @param string $import Import name/path
     * @param array $dependencies All dependencies
     * @return string|null Resolved file path
     */
    private function resolveImportToFile(string $import, array $dependencies): ?string
    {
        // Simple resolution - look for files that export this import
        foreach ($dependencies as $filePath => $fileData) {
            $exports = $fileData['exports'] ?? [];
            foreach ($exports as $export) {
                if (is_array($export) && ($export['name'] ?? '') === $import) {
                    return $filePath;
                } elseif (is_string($export) && $export === $import) {
                    return $filePath;
                }
            }
        }
        
        return null;
    }

    /**
     * Build dependency graph with statistics
     * @param array $dependencies Dependency data
     * @return array Graph data with statistics
     */
    private function buildDependencyGraph(array $dependencies): array
    {
        $totalDependencies = 0;
        $totalDepth = 0;
        $isolatedFiles = 0;
        $mostDependedOn = '';
        $maxDependents = 0;
        
        foreach ($dependencies as $filePath => $fileData) {
            $imports = count($fileData['imports'] ?? []);
            $importedBy = count($fileData['imported_by'] ?? []);
            
            $totalDependencies += $imports;
            $totalDepth += $fileData['dependency_depth'] ?? 0;
            
            if ($imports === 0 && $importedBy === 0) {
                $isolatedFiles++;
            }
            
            if ($importedBy > $maxDependents) {
                $maxDependents = $importedBy;
                $mostDependedOn = $filePath;
            }
        }
        
        $fileCount = count($dependencies);
        $circular = $this->detectCircularDependencies($dependencies);
        
        return [
            'circular_dependencies' => $circular,
            'dependency_chains' => [
                'longest_chain' => $fileCount > 0 ? max(array_column($dependencies, 'dependency_depth')) : 0,
                'most_depended_on' => $mostDependedOn
            ],
            'statistics' => [
                'total_files' => $fileCount,
                'total_dependencies' => $totalDependencies,
                'average_depth' => $fileCount > 0 ? round($totalDepth / $fileCount, 2) : 0,
                'isolated_files' => $isolatedFiles,
                'circular_dependencies_count' => count($circular)
            ]
        ];
    }

    /**
     * Recursive helper for circular dependency detection
     * @param string $file Current file
     * @param array $dependencies All dependencies
     * @param array &$visited Visited files
     * @param array &$recursionStack Current recursion stack
     * @param array $path Current path
     * @return array Circular dependency path if found
     */
    private function detectCycleRecursive(string $file, array $dependencies, array &$visited, array &$recursionStack, array $path): array
    {
        $visited[$file] = true;
        $recursionStack[$file] = true;
        $path[] = $file;
        
        $imports = $dependencies[$file]['imports'] ?? [];
        foreach ($imports as $import) {
            $importFile = $this->resolveImportToFile($import, $dependencies);
            
            if ($importFile && isset($dependencies[$importFile])) {
                if (!isset($visited[$importFile])) {
                    $cycle = $this->detectCycleRecursive($importFile, $dependencies, $visited, $recursionStack, $path);
                    if (!empty($cycle)) {
                        return $cycle;
                    }
                } elseif (isset($recursionStack[$importFile]) && $recursionStack[$importFile]) {
                    // Found a cycle - return the cycle path
                    $cycleStart = array_search($importFile, $path);
                    return array_slice($path, $cycleStart);
                }
            }
        }
        
        $recursionStack[$file] = false;
        array_pop($path);
        
        return [];
    }
}
