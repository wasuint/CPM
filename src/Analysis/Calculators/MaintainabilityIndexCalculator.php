<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\Calculators;

/**
 * Calculates Microsoft Maintainability Index
 * A composite metric indicating how maintainable code is
 */
class MaintainabilityIndexCalculator
{
    private CyclomaticComplexityCalculator $complexityCalculator;
    
    public function __construct()
    {
        $this->complexityCalculator = new CyclomaticComplexityCalculator();
    }
    
    /**
     * Calculate Microsoft Maintainability Index for a function
     * MI = 171 - 5.2 * ln(Halstead Volume) - 0.23 * (Cyclomatic Complexity) - 16.2 * ln(Lines of Code)
     * @param array $function Function data
     * @return float Maintainability index (0-100)
     */
    public function calculateForFunction(array $function): float
    {
        $complexity = $this->complexityCalculator->calculateForFunction($function);
        $loc = $this->calculateLinesOfCode($function);
        $halsteadVolume = $this->calculateHalsteadVolume($function);
        
        // Microsoft Maintainability Index formula
        $mi = 171 - 5.2 * log($halsteadVolume) - 0.23 * $complexity - 16.2 * log($loc);
        
        // Normalize to 0-100 scale and ensure it doesn't go negative
        return max(0, min(100, $mi));
    }
    
    /**
     * Calculate Halstead Volume for a function
     * Volume = (N1 + N2) * log2(n1 + n2)
     * Where N1, N2 are total operators/operands, n1, n2 are unique operators/operands
     * @param array $function Function data
     * @return float Halstead volume
     */
    private function calculateHalsteadVolume(array $function): float
    {
        $body = $function['body'] ?? '';
        
        if (empty($body)) {
            return 1.0; // Minimum volume
        }
        
        $operators = $this->extractOperators($body);
        $operands = $this->extractOperands($body);
        
        $n1 = count(array_unique($operators)); // Unique operators
        $n2 = count(array_unique($operands));  // Unique operands
        $N1 = count($operators);               // Total operators
        $N2 = count($operands);                // Total operands
        
        $vocabulary = $n1 + $n2;
        $length = $N1 + $N2;
        
        if ($vocabulary <= 1) {
            return 1.0; // Avoid log(0) or log(1)
        }
        
        return $length * log($vocabulary, 2);
    }
    
    /**
     * Extract operators from code
     * @param string $code Function body
     * @return array List of operators found
     */
    private function extractOperators(string $code): array
    {
        $operators = [];
        
        // Common operators across languages
        $operatorPatterns = [
            // Arithmetic
            '/\+/',
            '/\-/',
            '/\*/',
            '/\//',
            '/\%/',
            '/\*\*/',
            
            // Comparison
            '/==/',
            '/!=/',
            '/===/',
            '/!==/',
            '/<=/',
            '/>=/',
            '/</',
            '/>/',
            
            // Logical
            '/&&/',
            '/\|\|/',
            '/!/',
            
            // Assignment
            '/=/',
            '/\+=/',
            '/\-=/',
            '/\*=/',
            '/\/=/',
            
            // Other
            '/\?/',
            '/:/',
            '/\./',
            '/->/',
            '/::/',
        ];
        
        foreach ($operatorPatterns as $pattern) {
            preg_match_all($pattern, $code, $matches);
            $operators = array_merge($operators, $matches[0]);
        }
        
        return $operators;
    }
    
    /**
     * Extract operands from code (simplified)
     * @param string $code Function body
     * @return array List of operands found
     */
    private function extractOperands(string $code): array
    {
        $operands = [];
        
        // Extract variables (simplified pattern)
        preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $code, $variables);
        $operands = array_merge($operands, $variables[0]);
        
        // Extract numbers
        preg_match_all('/\b\d+(?:\.\d+)?\b/', $code, $numbers);
        $operands = array_merge($operands, $numbers[0]);
        
        // Extract string literals
        preg_match_all('/([\'"])(?:(?!\1)[^\\\\]|\\\\.)*\1/', $code, $strings);
        $operands = array_merge($operands, $strings[0]);
        
        // Extract function calls
        preg_match_all('/\b[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $code, $functions);
        $operands = array_merge($operands, array_map(fn($f) => rtrim($f, ' ('), $functions[0]));
        
        return $operands;
    }
    
    /**
     * Calculate lines of code for a function
     * @param array $function Function data
     * @return int Lines of code
     */
    private function calculateLinesOfCode(array $function): int
    {
        if (isset($function['end_line']) && isset($function['start_line'])) {
            return max(1, $function['end_line'] - $function['start_line'] + 1);
        }
        
        $body = $function['body'] ?? '';
        $lines = explode("\n", $body);
        
        // Count non-empty, non-comment lines
        $loc = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (!empty($trimmed) && !$this->isCommentLine($trimmed)) {
                $loc++;
            }
        }
        
        return max(1, $loc);
    }
    
    /**
     * Check if a line is a comment
     * @param string $line Trimmed line of code
     * @return bool True if comment line
     */
    private function isCommentLine(string $line): bool
    {
        return str_starts_with($line, '//') || 
               str_starts_with($line, '#') ||
               str_starts_with($line, '/*') ||
               str_starts_with($line, '*');
    }
    
    /**
     * Get maintainability rating as text
     * @param float $index Maintainability index
     * @return string Rating
     */
    public function getMaintainabilityRating(float $index): string
    {
        if ($index >= 85) {
            return 'Excellent';
        } elseif ($index >= 70) {
            return 'Good';
        } elseif ($index >= 50) {
            return 'Fair';
        } elseif ($index >= 25) {
            return 'Poor';
        } else {
            return 'Very Poor';
        }
    }
    
    /**
     * Get recommendation based on maintainability index
     * @param float $index Maintainability index
     * @return string Recommendation
     */
    public function getRecommendation(float $index): string
    {
        if ($index >= 85) {
            return 'Well-maintained code, easy to modify and extend';
        } elseif ($index >= 70) {
            return 'Good maintainability, minor improvements possible';
        } elseif ($index >= 50) {
            return 'Moderate maintainability, consider refactoring complex areas';
        } elseif ($index >= 25) {
            return 'Poor maintainability, refactoring recommended';
        } else {
            return 'Very poor maintainability, urgent refactoring needed';
        }
    }
}