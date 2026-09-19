<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use ClaudeProjectManager\DatabaseManager;

/**
 * Tracks function movements across files during refactoring
 * Detects when functions are moved, split, or merged between files
 */
class RefactoringTracker
{
    private DatabaseManager $database;
    
    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Generate unique fingerprint for a function
     * Uses multiple characteristics to create a stable signature
     * @param array $function Function data from inventory
     * @return string SHA256 hash of function signature
     */
    public function generateFunctionSignature(array $function): string
    {
        $components = [
            'name' => $function['name'] ?? '',
            'parameter_count' => count($function['parameters'] ?? []),
            'return_type' => $function['return_type'] ?? '',
            'body_snippet' => $this->extractBodySnippet($function),
            'complexity' => $function['complexity'] ?? 0,
            'parameter_types' => $this->extractParameterTypes($function['parameters'] ?? [])
        ];
        
        // Create a normalized string representation
        $signatureString = serialize($components);
        
        return hash('sha256', $signatureString);
    }

    /**
     * Detect moved functions between two inventory snapshots
     * @param array $beforeInventory Previous inventory state
     * @param array $afterInventory Current inventory state
     * @return array List of detected function movements
     */
    public function detectMovedFunctions(array $beforeInventory, array $afterInventory): array
    {
        $movements = [];
        $beforeSignatures = $this->buildSignatureMap($beforeInventory);
        $afterSignatures = $this->buildSignatureMap($afterInventory);
        
        foreach ($beforeSignatures as $signature => $beforeLocation) {
            if (isset($afterSignatures[$signature])) {
                $afterLocation = $afterSignatures[$signature];
                
                // Function exists in both inventories - check if it moved
                if ($beforeLocation['file'] !== $afterLocation['file']) {
                    $movements[] = [
                        'signature' => $signature,
                        'function_name' => $beforeLocation['function'],
                        'moved_from' => $beforeLocation['file'],
                        'moved_to' => $afterLocation['file'],
                        'detected_at' => date('c'),
                        'confidence' => $this->calculateMoveConfidence($beforeLocation, $afterLocation)
                    ];
                }
            }
        }
        
        return $movements;
    }

    /**
     * Detect file split operations
     * @param array $beforeInventory Previous inventory state  
     * @param array $afterInventory Current inventory state
     * @return array List of detected file splits
     */
    public function detectFileSplits(array $beforeInventory, array $afterInventory): array
    {
        $splits = [];
        $beforeFiles = $beforeInventory['files'] ?? [];
        $afterFiles = $afterInventory['files'] ?? [];
        
        foreach ($beforeFiles as $beforeFile => $beforeData) {
            // Check if file no longer exists in after inventory
            if (!isset($afterFiles[$beforeFile])) {
                $beforeFunctions = $beforeData['functions'] ?? [];
                if (count($beforeFunctions) > 3) { // Only consider meaningful splits
                    
                    // Find where these functions went
                    $splitTargets = $this->findSplitTargets($beforeFunctions, $afterFiles);
                    
                    if (count($splitTargets) > 1) {
                        $splits[] = [
                            'original_file' => $beforeFile,
                            'split_into' => array_keys($splitTargets),
                            'functions_moved' => $this->mapFunctionsToTargets($beforeFunctions, $splitTargets),
                            'detected_at' => date('c'),
                            'confidence' => $this->calculateSplitConfidence($beforeFunctions, $splitTargets)
                        ];
                    }
                }
            }
        }
        
        return $splits;
    }

    /**
     * Detect file merge operations
     * @param array $beforeInventory Previous inventory state
     * @param array $afterInventory Current inventory state  
     * @return array List of detected file merges
     */
    public function detectFileMerges(array $beforeInventory, array $afterInventory): array
    {
        $merges = [];
        $beforeFiles = $beforeInventory['files'] ?? [];
        $afterFiles = $afterInventory['files'] ?? [];
        
        // Look for new files that contain functions from multiple old files
        foreach ($afterFiles as $afterFile => $afterData) {
            if (!isset($beforeFiles[$afterFile])) {
                // This is a new file, check if it contains functions from multiple sources
                $functionSources = $this->traceFunctionSources($afterData['functions'] ?? [], $beforeFiles);
                
                if (count($functionSources) > 1) {
                    $merges[] = [
                        'merged_files' => array_keys($functionSources),
                        'merged_into' => $afterFile,
                        'functions_consolidated' => array_sum(array_map('count', $functionSources)),
                        'detected_at' => date('c'),
                        'confidence' => $this->calculateMergeConfidence($functionSources)
                    ];
                }
            }
        }
        
        return $merges;
    }

    /**
     * Store refactoring operations in database
     * @param array $movements Function movements
     * @param array $splits File splits  
     * @param array $merges File merges
     */
    public function recordRefactoringOperations(array $movements, array $splits, array $merges): void
    {
        try {
            $refactoringHistory = $this->database->read('refactoring_history');
        } catch (\Exception $e) {
            // Database file doesn't exist yet
            $refactoringHistory = [
                'generated_at' => null,
                'function_movements' => [],
                'file_splits' => [],
                'merge_operations' => []
            ];
        }
        
        // Add new operations
        foreach ($movements as $movement) {
            $key = $movement['function_name'] . '_' . substr($movement['signature'], 0, 8);
            $refactoringHistory['function_movements'][$key] = $movement;
        }
        
        $refactoringHistory['file_splits'] = array_merge(
            $refactoringHistory['file_splits'], 
            $splits
        );
        
        $refactoringHistory['merge_operations'] = array_merge(
            $refactoringHistory['merge_operations'], 
            $merges
        );
        
        $refactoringHistory['generated_at'] = date('c');
        
        // Keep only recent operations (last 100 of each type)
        $refactoringHistory['function_movements'] = array_slice(
            $refactoringHistory['function_movements'], -100, null, true
        );
        $refactoringHistory['file_splits'] = array_slice($refactoringHistory['file_splits'], -50);
        $refactoringHistory['merge_operations'] = array_slice($refactoringHistory['merge_operations'], -50);
        
        $this->database->write('refactoring_history', $refactoringHistory);
    }

    /**
     * Get refactoring history for a specific time period
     * @param int $days Number of days to look back
     * @return array Filtered refactoring history
     */
    public function getRefactoringHistory(int $days = 30): array
    {
        try {
            $history = $this->database->read('refactoring_history');
            $cutoffDate = date('c', strtotime("-{$days} days"));
            
            // Filter by date
            $filtered = [
                'function_movements' => array_filter(
                    $history['function_movements'] ?? [],
                    fn($m) => ($m['detected_at'] ?? '') >= $cutoffDate
                ),
                'file_splits' => array_filter(
                    $history['file_splits'] ?? [],
                    fn($s) => ($s['detected_at'] ?? '') >= $cutoffDate
                ),
                'merge_operations' => array_filter(
                    $history['merge_operations'] ?? [], 
                    fn($m) => ($m['detected_at'] ?? '') >= $cutoffDate
                )
            ];
            
            return $filtered;
            
        } catch (\Exception $e) {
            return [
                'function_movements' => [],
                'file_splits' => [],
                'merge_operations' => []
            ];
        }
    }

    /**
     * Extract a representative snippet from function body
     * @param array $function Function data
     * @return string Body snippet for signature generation
     */
    private function extractBodySnippet(array $function): string
    {
        $body = $function['body'] ?? '';
        
        // Remove comments and normalize whitespace
        $body = preg_replace('/\/\*.*?\*\//s', '', $body);
        $body = preg_replace('/\/\/.*$/m', '', $body);
        $body = preg_replace('/\s+/', ' ', $body);
        
        // Take first 100 characters of normalized body
        return substr(trim($body), 0, 100);
    }

    /**
     * Extract parameter types for signature generation
     * @param array $parameters Function parameters
     * @return array List of parameter types
     */
    private function extractParameterTypes(array $parameters): array
    {
        return array_map(fn($param) => $param['type'] ?? 'mixed', $parameters);
    }

    /**
     * Build signature map from inventory
     * @param array $inventory Inventory data
     * @return array Map of signatures to file locations
     */
    private function buildSignatureMap(array $inventory): array
    {
        $signatures = [];
        
        foreach ($inventory['files'] ?? [] as $filePath => $fileData) {
            foreach ($fileData['functions'] ?? [] as $function) {
                $signature = $this->generateFunctionSignature($function);
                $signatures[$signature] = [
                    'file' => $filePath,
                    'function' => $function['name'] ?? 'unknown',
                    'line' => $function['line'] ?? 0
                ];
            }
        }
        
        return $signatures;
    }

    /**
     * Calculate confidence score for function movement
     * @param array $beforeLocation Previous function location
     * @param array $afterLocation New function location  
     * @return float Confidence score (0.0 to 1.0)
     */
    private function calculateMoveConfidence(array $beforeLocation, array $afterLocation): float
    {
        $confidence = 0.5; // Base confidence
        
        // Higher confidence if function names match exactly
        if ($beforeLocation['function'] === $afterLocation['function']) {
            $confidence += 0.3;
        }
        
        // Higher confidence if files are related (similar names)
        if ($this->areFilesRelated($beforeLocation['file'], $afterLocation['file'])) {
            $confidence += 0.2;
        }
        
        return min(1.0, $confidence);
    }

    /**
     * Find target files for split operations
     * @param array $functions Original functions
     * @param array $afterFiles Files in after inventory
     * @return array Map of target files to function counts
     */
    private function findSplitTargets(array $functions, array $afterFiles): array
    {
        $targets = [];
        
        foreach ($functions as $function) {
            $signature = $this->generateFunctionSignature($function);
            
            foreach ($afterFiles as $filePath => $fileData) {
                foreach ($fileData['functions'] ?? [] as $afterFunction) {
                    $afterSignature = $this->generateFunctionSignature($afterFunction);
                    
                    if ($signature === $afterSignature) {
                        if (!isset($targets[$filePath])) {
                            $targets[$filePath] = 0;
                        }
                        $targets[$filePath]++;
                        break 2; // Move to next original function
                    }
                }
            }
        }
        
        return $targets;
    }

    /**
     * Map functions to their target files after split
     * @param array $functions Original functions
     * @param array $splitTargets Target files
     * @return array Function to target mapping
     */
    private function mapFunctionsToTargets(array $functions, array $splitTargets): array
    {
        $mapping = [];
        
        foreach ($functions as $function) {
            foreach (array_keys($splitTargets) as $targetFile) {
                // Simplified mapping - could be enhanced with signature matching
                $mapping[] = [
                    'name' => $function['name'] ?? 'unknown',
                    'moved_to' => $targetFile
                ];
            }
        }
        
        return $mapping;
    }

    /**
     * Trace function sources for merge detection
     * @param array $functions Functions in merged file
     * @param array $beforeFiles Files from before inventory
     * @return array Source files mapping
     */
    private function traceFunctionSources(array $functions, array $beforeFiles): array
    {
        $sources = [];
        
        foreach ($functions as $function) {
            $signature = $this->generateFunctionSignature($function);
            
            foreach ($beforeFiles as $filePath => $fileData) {
                foreach ($fileData['functions'] ?? [] as $beforeFunction) {
                    $beforeSignature = $this->generateFunctionSignature($beforeFunction);
                    
                    if ($signature === $beforeSignature) {
                        if (!isset($sources[$filePath])) {
                            $sources[$filePath] = [];
                        }
                        $sources[$filePath][] = $function['name'] ?? 'unknown';
                        break 2;
                    }
                }
            }
        }
        
        return $sources;
    }

    /**
     * Calculate confidence for split operation
     * @param array $beforeFunctions Original functions
     * @param array $splitTargets Target files
     * @return float Confidence score
     */
    private function calculateSplitConfidence(array $beforeFunctions, array $splitTargets): float
    {
        $totalFunctions = count($beforeFunctions);
        $foundFunctions = array_sum($splitTargets);
        
        return $totalFunctions > 0 ? $foundFunctions / $totalFunctions : 0.0;
    }

    /**
     * Calculate confidence for merge operation
     * @param array $functionSources Source file mapping
     * @return float Confidence score
     */
    private function calculateMergeConfidence(array $functionSources): float
    {
        // Higher confidence with more source files and balanced distribution
        $sourceCount = count($functionSources);
        $functionCounts = array_map('count', $functionSources);
        $avgFunctions = array_sum($functionCounts) / $sourceCount;
        $variance = $this->calculateVariance($functionCounts, $avgFunctions);
        
        // Lower variance (more balanced) = higher confidence
        return max(0.3, 1.0 - ($variance / 10));
    }

    /**
     * Check if two files are related (similar names or paths)
     * @param string $file1 First file path
     * @param string $file2 Second file path
     * @return bool True if files appear related
     */
    private function areFilesRelated(string $file1, string $file2): bool
    {
        $base1 = basename($file1, '.php');
        $base2 = basename($file2, '.php');
        
        // Check if one is a substring of the other
        return str_contains($base1, $base2) || str_contains($base2, $base1);
    }

    /**
     * Calculate variance of an array
     * @param array $values Numeric values
     * @param float $mean Mean of the values
     * @return float Variance
     */
    private function calculateVariance(array $values, float $mean): float
    {
        $sum = 0;
        foreach ($values as $value) {
            $sum += pow($value - $mean, 2);
        }
        
        return count($values) > 0 ? $sum / count($values) : 0;
    }
}