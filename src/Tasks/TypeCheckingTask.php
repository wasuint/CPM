<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tasks;

use ClaudeProjectManager\DatabaseManager;

/**
 * Specialized task for adding type annotations and type checking
 */
class TypeCheckingTask
{
    private DatabaseManager $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Processes functions for type safety compliance
     */
    public function processFunctions(array $functions): array
    {
        $results = [];
        
        foreach ($functions as $functionId => $function) {
            $results[$functionId] = $this->analyzeFunction($function);
        }
        
        return [
            'processed_count' => count($functions),
            'results' => $results,
            'completed_at' => date('c')
        ];
    }

    /**
     * Analyzes a single function for type safety
     */
    private function analyzeFunction(array $functionData): array
    {
        return [
            'function_id' => $functionData['id'] ?? 'unknown',
            'has_return_type' => !empty($functionData['return_type']),
            'has_parameter_types' => $this->hasParameterTypes($functionData),
            'type_score' => $this->calculateTypeScore($functionData),
            'suggestions' => $this->generateTypeSuggestions($functionData)
        ];
    }

    private function hasParameterTypes(array $functionData): bool
    {
        $params = $functionData['parameters'] ?? [];
        foreach ($params as $param) {
            if (empty($param['type'])) {
                return false;
            }
        }
        return true;
    }

    private function calculateTypeScore(array $functionData): float
    {
        $score = 0;
        if (!empty($functionData['return_type'])) $score += 50;
        if ($this->hasParameterTypes($functionData)) $score += 50;
        return $score;
    }

    private function generateTypeSuggestions(array $functionData): array
    {
        $suggestions = [];
        
        if (empty($functionData['return_type'])) {
            $suggestions[] = 'Add return type annotation';
        }
        
        if (!$this->hasParameterTypes($functionData)) {
            $suggestions[] = 'Add parameter type annotations';
        }
        
        return $suggestions;
    }
}