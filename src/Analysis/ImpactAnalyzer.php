<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Analysis\Results\ImpactAnalysisResult;
use ClaudeProjectManager\Analysis\Results\FunctionImpactResult;

/**
 * Analyzes potential impact of file/function changes
 * Uses dependency graph to identify affected components
 */
class ImpactAnalyzer
{
    private DatabaseManager $database;
    private DependencyAnalyzer $dependencyAnalyzer;
    
    public function __construct(DatabaseManager $database, DependencyAnalyzer $dependencyAnalyzer)
    {
        $this->database = $database;
        $this->dependencyAnalyzer = $dependencyAnalyzer;
    }

    /**
     * Analyze impact of changing a specific file
     * @param string $filePath File to analyze for impact
     * @return ImpactAnalysisResult Detailed impact analysis
     */
    public function analyzeFileImpact(string $filePath): ImpactAnalysisResult
    {
        // Ensure dependencies are up to date
        $this->refreshDependenciesIfNeeded();
        
        $dependencies = $this->database->read('dependencies');
        $inventory = $this->database->read('inventory');
        
        $directlyAffected = $this->findDirectlyAffectedFiles($filePath, $dependencies);
        $indirectlyAffected = $this->findIndirectlyAffectedFiles($filePath, $dependencies);
        $exportedFunctions = $this->getExportedFunctions($filePath, $dependencies, $inventory);
        $externalUsage = $this->findExternalUsage($filePath, $dependencies);
        $impactScore = $this->calculateImpactScore($filePath, $dependencies);
        $recommendations = $this->generateRecommendations($filePath, $dependencies, $impactScore);
        
        $result = new ImpactAnalysisResult([
            'target_file' => $filePath,
            'directly_affected' => $directlyAffected,
            'indirectly_affected' => $indirectlyAffected,
            'exported_functions' => $exportedFunctions,
            'external_usage' => $externalUsage,
            'impact_score' => $impactScore,
            'recommendations' => $recommendations
        ]);
        
        // Cache the result
        $this->cacheAnalysisResult($filePath, $result);
        
        return $result;
    }

    /**
     * Analyze impact of changing a specific function
     * @param string $functionName Function name to analyze
     * @param string $filePath File containing the function
     * @return FunctionImpactResult Function-specific impact analysis
     */
    public function analyzeFunctionImpact(string $functionName, string $filePath): FunctionImpactResult
    {
        $inventory = $this->database->read('inventory');
        $dependencies = $this->database->read('dependencies');
        
        // Find all files that use this function
        $usageFiles = $this->findFunctionUsage($functionName, $inventory);
        $isPublicApi = $this->isPublicFunction($functionName, $filePath, $dependencies);
        $breakingChangeRisk = $this->assessBreakingChangeRisk($functionName, $usageFiles);
        $suggestions = $this->generateFunctionSuggestions($functionName, $usageFiles, $isPublicApi);
        
        return new FunctionImpactResult([
            'function_name' => $functionName,
            'declaring_file' => $filePath,
            'used_in_files' => $usageFiles,
            'usage_count' => count($usageFiles),
            'is_public_api' => $isPublicApi,
            'breaking_change_risk' => $breakingChangeRisk,
            'suggestions' => $suggestions
        ]);
    }

    /**
     * Get cached impact analysis if available
     * @param string $filePath File path
     * @return ImpactAnalysisResult|null Cached result or null
     */
    public function getCachedAnalysis(string $filePath): ?ImpactAnalysisResult
    {
        try {
            $impacts = $this->database->read('impacts');
            $cached = $impacts['cached_analyses'][$filePath] ?? null;
            
            if ($cached && $this->isCacheValid($cached)) {
                return new ImpactAnalysisResult($cached);
            }
        } catch (\Exception $e) {
            // Cache doesn't exist or is invalid
        }
        
        return null;
    }

    /**
     * Find files directly affected by changes to target file
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @return array List of directly affected files
     */
    private function findDirectlyAffectedFiles(string $filePath, array $dependencies): array
    {
        return $dependencies['files'][$filePath]['imported_by'] ?? [];
    }

    /**
     * Find files indirectly affected (dependencies of direct dependents)
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @return array List of indirectly affected files
     */
    private function findIndirectlyAffectedFiles(string $filePath, array $dependencies): array
    {
        $directlyAffected = $this->findDirectlyAffectedFiles($filePath, $dependencies);
        $indirectlyAffected = [];
        
        foreach ($directlyAffected as $directFile) {
            $secondLevel = $dependencies['files'][$directFile]['imported_by'] ?? [];
            foreach ($secondLevel as $indirectFile) {
                if ($indirectFile !== $filePath && !in_array($indirectFile, $directlyAffected)) {
                    $indirectlyAffected[] = $indirectFile;
                }
            }
        }
        
        return array_unique($indirectlyAffected);
    }

    /**
     * Get functions exported by the target file
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @param array $inventory Inventory data
     * @return array List of exported functions with usage info
     */
    private function getExportedFunctions(string $filePath, array $dependencies, array $inventory): array
    {
        $exports = $dependencies['files'][$filePath]['exports'] ?? [];
        $functions = [];
        
        foreach ($exports as $export) {
            if (is_array($export) && ($export['type'] === 'function' || $export['type'] === 'method')) {
                $usageCount = $this->countFunctionUsage($export['name'], $inventory);
                $functions[] = [
                    'name' => $export['name'],
                    'visibility' => $export['visibility'] ?? 'public',
                    'type' => $export['type'],
                    'usage_count' => $usageCount
                ];
            }
        }
        
        return $functions;
    }

    /**
     * Find external usage patterns for the file
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @return array External usage information
     */
    private function findExternalUsage(string $filePath, array $dependencies): array
    {
        $usage = [];
        $importedBy = $dependencies['files'][$filePath]['imported_by'] ?? [];
        
        foreach ($importedBy as $importingFile) {
            $usage[] = [
                'file' => $importingFile,
                'type' => $this->determineUsageType($importingFile),
                'relationship' => 'imports'
            ];
        }
        
        return $usage;
    }

    /**
     * Calculate impact score for the file
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @return string Impact score (LOW, MEDIUM, HIGH)
     */
    private function calculateImpactScore(string $filePath, array $dependencies): string
    {
        $directDependents = count($dependencies['files'][$filePath]['imported_by'] ?? []);
        $totalExports = count($dependencies['files'][$filePath]['exports'] ?? []);
        $dependencyDepth = $dependencies['files'][$filePath]['dependency_depth'] ?? 0;
        
        // Calculate score based on multiple factors
        $score = 0;
        
        // Weight by number of dependents
        if ($directDependents === 0) {
            $score += 0;
        } elseif ($directDependents <= 3) {
            $score += 1;
        } elseif ($directDependents <= 10) {
            $score += 2;
        } else {
            $score += 3;
        }
        
        // Weight by number of exports
        if ($totalExports <= 5) {
            $score += 0;
        } elseif ($totalExports <= 15) {
            $score += 1;
        } else {
            $score += 2;
        }
        
        // Weight by dependency depth (how deep in the dependency chain)
        if ($dependencyDepth <= 2) {
            $score += 0;
        } elseif ($dependencyDepth <= 5) {
            $score += 1;
        } else {
            $score += 2;
        }
        
        // Convert score to category
        if ($score <= 1) return 'LOW';
        if ($score <= 4) return 'MEDIUM';
        return 'HIGH';
    }

    /**
     * Generate recommendations based on impact analysis
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @param string $impactScore Impact score
     * @return array List of recommendations
     */
    private function generateRecommendations(string $filePath, array $dependencies, string $impactScore): array
    {
        $recommendations = [];
        $directDependents = count($dependencies['files'][$filePath]['imported_by'] ?? []);
        $exports = $dependencies['files'][$filePath]['exports'] ?? [];
        
        // Impact-specific recommendations
        switch ($impactScore) {
            case 'HIGH':
                $recommendations[] = "⚠️ High impact change - thorough testing required";
                $recommendations[] = "Consider gradual migration or deprecation notices";
                break;
            case 'MEDIUM':
                $recommendations[] = "⚠️ Medium impact - verify dependent functionality";
                break;
            case 'LOW':
                $recommendations[] = "✅ Low impact change - minimal risk";
                break;
        }
        
        // Dependent-specific recommendations
        if ($directDependents > 5) {
            $recommendations[] = "📄 $directDependents files directly import this - update documentation";
        }
        
        if ($directDependents > 0) {
            $recommendations[] = "🔍 Review dependent files before making breaking changes";
        }
        
        // Export-specific recommendations
        $publicExports = array_filter($exports, fn($exp) => ($exp['visibility'] ?? 'public') === 'public');
        if (count($publicExports) > 0) {
            $recommendations[] = "🔒 " . count($publicExports) . " public exports - maintain backward compatibility";
        }
        
        // Test coverage recommendation
        $testFiles = $this->findTestFiles($filePath, $dependencies);
        if (empty($testFiles)) {
            $recommendations[] = "🧪 No test coverage detected - add tests before changes";
        } else {
            $recommendations[] = "✅ Test coverage detected - update tests after changes";
        }
        
        return $recommendations;
    }

    /**
     * Find test files related to the target file
     * @param string $filePath Target file
     * @param array $dependencies Dependency data
     * @return array List of related test files
     */
    private function findTestFiles(string $filePath, array $dependencies): array
    {
        $testFiles = [];
        $importedBy = $dependencies['files'][$filePath]['imported_by'] ?? [];
        
        foreach ($importedBy as $file) {
            if ($this->isTestFile($file)) {
                $testFiles[] = $file;
            }
        }
        
        return $testFiles;
    }

    /**
     * Check if file is a test file based on naming patterns
     * @param string $filePath File path to check
     * @return bool True if test file
     */
    private function isTestFile(string $filePath): bool
    {
        $testPatterns = [
            '/test/i',
            '/spec/i',
            '/_test\./i',
            '/\.test\./i',
            '/\.spec\./i',
            '/tests\//i',
            '/__tests__\//i'
        ];
        
        foreach ($testPatterns as $pattern) {
            if (preg_match($pattern, $filePath)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Find usage of a specific function across the codebase
     * @param string $functionName Function name
     * @param array $inventory Inventory data
     * @return array List of files using the function
     */
    private function findFunctionUsage(string $functionName, array $inventory): array
    {
        $usageFiles = [];
        
        // This is a simplified search - in a real implementation,
        // you might want to do more sophisticated AST analysis
        foreach ($inventory['files'] ?? [] as $filePath => $fileData) {
            foreach ($fileData['functions'] ?? [] as $function) {
                // Check if function body mentions our target function
                if (isset($function['body']) && str_contains($function['body'], $functionName)) {
                    $usageFiles[] = $filePath;
                    break;
                }
            }
        }
        
        return array_unique($usageFiles);
    }

    /**
     * Count usage of a function across the codebase
     * @param string $functionName Function name
     * @param array $inventory Inventory data
     * @return int Usage count
     */
    private function countFunctionUsage(string $functionName, array $inventory): int
    {
        return count($this->findFunctionUsage($functionName, $inventory));
    }

    /**
     * Check if function is part of public API
     * @param string $functionName Function name
     * @param string $filePath Declaring file
     * @param array $dependencies Dependency data
     * @return bool True if public API
     */
    private function isPublicFunction(string $functionName, string $filePath, array $dependencies): bool
    {
        $exports = $dependencies['files'][$filePath]['exports'] ?? [];
        
        foreach ($exports as $export) {
            if (is_array($export) && 
                $export['name'] === $functionName && 
                ($export['visibility'] ?? 'public') === 'public') {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Assess breaking change risk for function modification
     * @param string $functionName Function name
     * @param array $usageFiles Files using the function
     * @return string Risk level (LOW, MEDIUM, HIGH)
     */
    private function assessBreakingChangeRisk(string $functionName, array $usageFiles): string
    {
        $usageCount = count($usageFiles);
        
        if ($usageCount === 0) return 'LOW';
        if ($usageCount <= 3) return 'LOW';
        if ($usageCount <= 10) return 'MEDIUM';
        return 'HIGH';
    }

    /**
     * Generate function-specific suggestions
     * @param string $functionName Function name
     * @param array $usageFiles Files using the function
     * @param bool $isPublicApi Whether function is public API
     * @return array List of suggestions
     */
    private function generateFunctionSuggestions(string $functionName, array $usageFiles, bool $isPublicApi): array
    {
        $suggestions = [];
        $usageCount = count($usageFiles);
        
        if ($isPublicApi && $usageCount > 0) {
            $suggestions[] = "🔒 Public API function - maintain backward compatibility";
        }
        
        if ($usageCount > 5) {
            $suggestions[] = "⚠️ Widely used function ($usageCount files) - consider deprecation process";
        }
        
        if ($usageCount === 0) {
            $suggestions[] = "🗑️ Unused function - safe to remove";
        }
        
        if ($usageCount > 0) {
            $suggestions[] = "🔍 Update all $usageCount usage locations after changes";
        }
        
        return $suggestions;
    }

    /**
     * Determine usage type based on file characteristics
     * @param string $filePath File path
     * @return string Usage type
     */
    private function determineUsageType(string $filePath): string
    {
        if ($this->isTestFile($filePath)) {
            return 'test';
        }
        
        if (str_contains($filePath, 'controller')) {
            return 'controller';
        }
        
        if (str_contains($filePath, 'service')) {
            return 'service';
        }
        
        return 'general';
    }

    /**
     * Cache analysis result for future use
     * @param string $filePath File path
     * @param ImpactAnalysisResult $result Analysis result
     */
    private function cacheAnalysisResult(string $filePath, ImpactAnalysisResult $result): void
    {
        try {
            $impacts = $this->database->read('impacts');
            $impacts['cached_analyses'][$filePath] = array_merge($result->toArray(), [
                'cached_at' => date('c'),
                'file_hash' => $this->getFileHash($filePath)
            ]);
            $this->database->write('impacts', $impacts);
        } catch (\Exception $e) {
            // Failed to cache - not critical
            error_log("Failed to cache impact analysis: " . $e->getMessage());
        }
    }

    /**
     * Check if cached analysis is still valid
     * @param array $cached Cached data
     * @return bool True if valid
     */
    private function isCacheValid(array $cached): bool
    {
        if (empty($cached['cached_at']) || empty($cached['file_hash'])) {
            return false;
        }
        
        // Check if file has changed
        if (file_exists($cached['target_file'])) {
            $currentHash = $this->getFileHash($cached['target_file']);
            if ($currentHash !== $cached['file_hash']) {
                return false;
            }
        }
        
        // Check cache age (valid for 1 hour)
        $cacheAge = time() - strtotime($cached['cached_at']);
        return $cacheAge < 3600;
    }

    /**
     * Get file hash for cache validation
     * @param string $filePath File path
     * @return string File hash
     */
    private function getFileHash(string $filePath): string
    {
        return file_exists($filePath) ? md5_file($filePath) : '';
    }

    /**
     * Refresh dependencies if they seem outdated
     */
    private function refreshDependenciesIfNeeded(): void
    {
        try {
            $dependencies = $this->database->read('dependencies');
            $generatedAt = $dependencies['generated_at'] ?? null;
            
            // Refresh if dependencies are older than 10 minutes
            if (!$generatedAt || (time() - strtotime($generatedAt)) > 600) {
                $inventory = $this->database->read('inventory');
                $this->dependencyAnalyzer->analyzeDependencies($inventory['files'] ?? []);
            }
        } catch (\Exception $e) {
            // Failed to refresh - continue with existing data
        }
    }
}