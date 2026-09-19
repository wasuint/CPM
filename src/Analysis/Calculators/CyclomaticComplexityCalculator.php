<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\Calculators;

/**
 * Calculates cyclomatic complexity for functions
 * Based on the number of decision points in the code
 */
class CyclomaticComplexityCalculator
{
    /**
     * Calculate cyclomatic complexity for a function
     * @param array $function Function data with body content
     * @return int Complexity score
     */
    public function calculateForFunction(array $function): int
    {
        $body = $function['body'] ?? '';
        
        if (empty($body)) {
            return 1; // Minimum complexity for any function
        }
        
        return $this->calculateFromCode($body);
    }
    
    /**
     * Calculate complexity from code content
     * @param string $code Code content
     * @return int Complexity score
     */
    public function calculateFromCode(string $code): int
    {
        $complexity = 1; // Base complexity
        
        // Decision points that increase complexity
        $patterns = [
            // PHP patterns
            '/\bif\s*\(/i',           // if statements
            '/\belseif\s*\(/i',       // elseif statements  
            '/\bwhile\s*\(/i',        // while loops
            '/\bfor\s*\(/i',          // for loops
            '/\bforeach\s*\(/i',      // foreach loops
            '/\bswitch\s*\(/i',       // switch statements
            '/\bcase\s+/i',           // case statements
            '/\bcatch\s*\(/i',        // catch blocks
            '/\b\?\s*:/i',            // ternary operators
            '/\&\&/i',                // logical AND
            '/\|\|/i',                // logical OR
            
            // Python patterns
            '/\bif\s+/i',             // Python if
            '/\belif\s+/i',           // Python elif
            '/\bwhile\s+/i',          // Python while
            '/\bfor\s+\w+\s+in\s+/i', // Python for loops
            '/\btry\s*:/i',           // Python try
            '/\bexcept\s*:/i',        // Python except
            '/\band\b/i',             // Python logical and
            '/\bor\b/i',              // Python logical or
            
            // JavaScript patterns
            '/\bif\s*\(/i',           // JS if
            '/\bwhile\s*\(/i',        // JS while
            '/\bfor\s*\(/i',          // JS for
            '/\bswitch\s*\(/i',       // JS switch
            '/\bcase\s+/i',           // JS case
            '/\bcatch\s*\(/i',        // JS catch
            '/\?\s*:/i',              // JS ternary
        ];
        
        foreach ($patterns as $pattern) {
            $matches = preg_match_all($pattern, $code);
            $complexity += $matches;
        }
        
        return $complexity;
    }
    
    /**
     * Get complexity rating as text
     * @param int $complexity Complexity score
     * @return string Rating (Low, Medium, High, Very High)
     */
    public function getComplexityRating(int $complexity): string
    {
        if ($complexity <= 5) {
            return 'Low';
        } elseif ($complexity <= 10) {
            return 'Medium';
        } elseif ($complexity <= 20) {
            return 'High';
        } else {
            return 'Very High';
        }
    }
    
    /**
     * Get recommended action for complexity level
     * @param int $complexity Complexity score
     * @return string Recommendation
     */
    public function getRecommendation(int $complexity): string
    {
        if ($complexity <= 5) {
            return 'Good - easy to test and maintain';
        } elseif ($complexity <= 10) {
            return 'Acceptable - consider simplifying if possible';
        } elseif ($complexity <= 20) {
            return 'High - should be simplified or split into smaller functions';
        } else {
            return 'Very High - urgent refactoring needed';
        }
    }
}