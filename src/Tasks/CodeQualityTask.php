<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tasks;

use ClaudeProjectManager\DatabaseManager;

/**
 * Specialized task for code quality analysis and improvements
 */
class CodeQualityTask
{
    private DatabaseManager $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Processes functions for code quality compliance
     */
    public function processFunctions(array $functions): array
    {
        $results = [];
        
        foreach ($functions as $functionId => $function) {
            $results[$functionId] = $this->analyzeQuality($function);
        }
        
        return [
            'processed_count' => count($functions),
            'results' => $results,
            'completed_at' => date('c')
        ];
    }

    /**
     * Analyzes function code quality
     */
    private function analyzeQuality(array $functionData): array
    {
        $complexity = $functionData['complexity'] ?? 1;
        $lineCount = $functionData['line_count'] ?? 0;
        
        return [
            'function_id' => $functionData['id'] ?? 'unknown',
            'complexity_score' => $complexity,
            'line_count' => $lineCount,
            'quality_score' => $this->calculateQualityScore($complexity, $lineCount),
            'issues' => $this->identifyQualityIssues($complexity, $lineCount),
            'suggestions' => $this->generateQualitySuggestions($complexity, $lineCount)
        ];
    }

    private function calculateQualityScore(int $complexity, int $lineCount): float
    {
        $score = 100;
        
        if ($complexity > 10) $score -= ($complexity - 10) * 5;
        if ($lineCount > 50) $score -= ($lineCount - 50) * 2;
        
        return max(0, $score);
    }

    private function identifyQualityIssues(int $complexity, int $lineCount): array
    {
        $issues = [];
        
        if ($complexity > 10) {
            $issues[] = 'High cyclomatic complexity';
        }
        
        if ($lineCount > 50) {
            $issues[] = 'Function too long';
        }
        
        return $issues;
    }

    private function generateQualitySuggestions(int $complexity, int $lineCount): array
    {
        $suggestions = [];
        
        if ($complexity > 10) {
            $suggestions[] = 'Reduce complexity by breaking into smaller functions';
        }
        
        if ($lineCount > 50) {
            $suggestions[] = 'Split function into smaller, focused functions';
        }
        
        return $suggestions;
    }
}