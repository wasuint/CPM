<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tasks;

use ClaudeProjectManager\DatabaseManager;

/**
 * Specialized task for generating and improving documentation
 */
class DocumentationTask
{
    private DatabaseManager $database;

    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Processes functions for documentation compliance
     */
    public function processFunctions(array $functions): array
    {
        $results = [];
        
        foreach ($functions as $functionId => $function) {
            $results[$functionId] = $this->analyzeDocumentation($function);
        }
        
        return [
            'processed_count' => count($functions),
            'results' => $results,
            'completed_at' => date('c')
        ];
    }

    /**
     * Analyzes function documentation quality
     */
    private function analyzeDocumentation(array $functionData): array
    {
        $hasDocComment = !empty($functionData['doc_comment']);
        $hasDescription = $hasDocComment && strpos($functionData['doc_comment'], '@param') !== false;
        $hasReturnDoc = $hasDocComment && strpos($functionData['doc_comment'], '@return') !== false;
        
        return [
            'function_id' => $functionData['id'] ?? 'unknown',
            'has_doc_comment' => $hasDocComment,
            'has_description' => $hasDescription,
            'has_return_documentation' => $hasReturnDoc,
            'documentation_score' => $this->calculateDocScore($hasDocComment, $hasDescription, $hasReturnDoc),
            'suggestions' => $this->generateDocSuggestions($hasDocComment, $hasDescription, $hasReturnDoc)
        ];
    }

    private function calculateDocScore(bool $hasDoc, bool $hasDesc, bool $hasReturn): float
    {
        $score = 0;
        if ($hasDoc) $score += 30;
        if ($hasDesc) $score += 40; 
        if ($hasReturn) $score += 30;
        return $score;
    }

    private function generateDocSuggestions(bool $hasDoc, bool $hasDesc, bool $hasReturn): array
    {
        $suggestions = [];
        
        if (!$hasDoc) {
            $suggestions[] = 'Add PHPDoc comment block';
        }
        
        if (!$hasDesc) {
            $suggestions[] = 'Add parameter documentation';
        }
        
        if (!$hasReturn) {
            $suggestions[] = 'Add return value documentation';
        }
        
        return $suggestions;
    }
}
