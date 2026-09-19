<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\Calculators;

/**
 * Calculates technical debt in time estimates
 * Based on code complexity, maintainability, and known issues
 */
class TechnicalDebtCalculator
{
    private CyclomaticComplexityCalculator $complexityCalculator;
    
    public function __construct()
    {
        $this->complexityCalculator = new CyclomaticComplexityCalculator();
    }
    
    /**
     * Calculate technical debt for a function in minutes
     * @param array $function Function data
     * @return int Technical debt in minutes
     */
    public function calculateForFunction(array $function): int
    {
        $debt = 0;
        
        // Base debt from complexity
        $complexity = $this->complexityCalculator->calculateForFunction($function);
        $debt += $this->debtFromComplexity($complexity);
        
        // Debt from function length
        $length = $this->calculateFunctionLength($function);
        $debt += $this->debtFromLength($length);
        
        // Debt from parameter count
        $paramCount = count($function['parameters'] ?? []);
        $debt += $this->debtFromParameters($paramCount);
        
        // Debt from missing documentation
        if ($this->lacksDocumentation($function)) {
            $debt += 15; // 15 minutes to add proper documentation
        }
        
        // Debt from missing type hints
        $debt += $this->debtFromMissingTypes($function);
        
        // Debt from code smells
        $debt += $this->debtFromCodeSmells($function);
        
        return max(0, $debt);
    }
    
    /**
     * Calculate technical debt for entire file
     * @param array $functions All functions in file
     * @return int Total technical debt in minutes
     */
    public function calculateForFile(array $functions): int
    {
        $totalDebt = 0;
        
        foreach ($functions as $function) {
            $totalDebt += $this->calculateForFunction($function);
        }
        
        // Additional file-level debt factors
        $functionCount = count($functions);
        
        // Large files have additional coordination debt
        if ($functionCount > 20) {
            $totalDebt += ($functionCount - 20) * 2; // 2 minutes per extra function
        }
        
        return $totalDebt;
    }
    
    /**
     * Get debt categories breakdown
     * @param array $function Function data
     * @return array Debt breakdown by category
     */
    public function getDebtBreakdown(array $function): array
    {
        $complexity = $this->complexityCalculator->calculateForFunction($function);
        $length = $this->calculateFunctionLength($function);
        $paramCount = count($function['parameters'] ?? []);
        
        return [
            'complexity_debt' => $this->debtFromComplexity($complexity),
            'length_debt' => $this->debtFromLength($length),
            'parameter_debt' => $this->debtFromParameters($paramCount),
            'documentation_debt' => $this->lacksDocumentation($function) ? 15 : 0,
            'type_debt' => $this->debtFromMissingTypes($function),
            'code_smell_debt' => $this->debtFromCodeSmells($function)
        ];
    }
    
    /**
     * Calculate debt from cyclomatic complexity
     * @param int $complexity Complexity score
     * @return int Debt in minutes
     */
    private function debtFromComplexity(int $complexity): int
    {
        if ($complexity <= 5) {
            return 0; // No debt for simple functions
        } elseif ($complexity <= 10) {
            return ($complexity - 5) * 5; // 5 minutes per point over 5
        } elseif ($complexity <= 20) {
            return 25 + ($complexity - 10) * 10; // Exponential increase
        } else {
            return 125 + ($complexity - 20) * 15; // High penalty for very complex functions
        }
    }
    
    /**
     * Calculate debt from function length
     * @param int $length Lines of code
     * @return int Debt in minutes
     */
    private function debtFromLength(int $length): int
    {
        if ($length <= 20) {
            return 0; // No debt for reasonable length
        } elseif ($length <= 50) {
            return ($length - 20) * 1; // 1 minute per extra line
        } elseif ($length <= 100) {
            return 30 + ($length - 50) * 2; // 2 minutes per line over 50
        } else {
            return 130 + ($length - 100) * 3; // 3 minutes per line over 100
        }
    }
    
    /**
     * Calculate debt from parameter count
     * @param int $paramCount Number of parameters
     * @return int Debt in minutes
     */
    private function debtFromParameters(int $paramCount): int
    {
        if ($paramCount <= 3) {
            return 0; // Reasonable parameter count
        } elseif ($paramCount <= 6) {
            return ($paramCount - 3) * 5; // 5 minutes per extra parameter
        } else {
            return 15 + ($paramCount - 6) * 10; // 10 minutes per parameter over 6
        }
    }
    
    /**
     * Check if function lacks documentation
     * @param array $function Function data
     * @return bool True if lacks documentation
     */
    private function lacksDocumentation(array $function): bool
    {
        $docblock = $function['docblock'] ?? '';
        
        // Consider documented if it has meaningful docblock
        return empty($docblock) || strlen(trim($docblock)) < 20;
    }
    
    /**
     * Calculate debt from missing type hints
     * @param array $function Function data
     * @return int Debt in minutes
     */
    private function debtFromMissingTypes(array $function): int
    {
        $debt = 0;
        
        // Missing return type
        if (empty($function['return_type'])) {
            $debt += 5;
        }
        
        // Missing parameter types
        $parameters = $function['parameters'] ?? [];
        foreach ($parameters as $param) {
            if (empty($param['type']) || $param['type'] === 'mixed') {
                $debt += 3; // 3 minutes per untyped parameter
            }
        }
        
        return $debt;
    }
    
    /**
     * Calculate debt from detected code smells
     * @param array $function Function data
     * @return int Debt in minutes
     */
    private function debtFromCodeSmells(array $function): int
    {
        $debt = 0;
        $body = $function['body'] ?? '';
        
        // Long parameter list smell (already covered in parameter debt)
        
        // Duplicate code patterns
        if ($this->hasDuplicatedCode($body)) {
            $debt += 20;
        }
        
        // Magic numbers
        $magicNumbers = $this->countMagicNumbers($body);
        $debt += $magicNumbers * 3; // 3 minutes per magic number
        
        // Nested conditions
        $nestingLevel = $this->calculateMaxNestingLevel($body);
        if ($nestingLevel > 3) {
            $debt += ($nestingLevel - 3) * 10;
        }
        
        // God method indicators
        if (str_contains($body, 'TODO') || str_contains($body, 'FIXME')) {
            $debt += 10; // Known issues
        }
        
        return $debt;
    }
    
    /**
     * Calculate function length
     * @param array $function Function data
     * @return int Lines of code
     */
    private function calculateFunctionLength(array $function): int
    {
        if (isset($function['end_line']) && isset($function['start_line'])) {
            return max(1, $function['end_line'] - $function['start_line'] + 1);
        }
        
        $body = $function['body'] ?? '';
        return max(1, substr_count($body, "\n") + 1);
    }
    
    /**
     * Check for duplicated code patterns
     * @param string $body Function body
     * @return bool True if duplication detected
     */
    private function hasDuplicatedCode(string $body): bool
    {
        // Simple heuristic: look for repeated lines
        $lines = explode("\n", $body);
        $trimmedLines = array_map('trim', $lines);
        $nonEmptyLines = array_filter($trimmedLines);
        
        return count($nonEmptyLines) !== count(array_unique($nonEmptyLines));
    }
    
    /**
     * Count magic numbers in code
     * @param string $body Function body
     * @return int Number of magic numbers
     */
    private function countMagicNumbers(string $body): int
    {
        // Find numeric literals, excluding common acceptable values
        preg_match_all('/\b(\d+(?:\.\d+)?)\b/', $body, $matches);
        $numbers = $matches[1];
        
        // Filter out acceptable numbers
        $acceptable = ['0', '1', '2', '10', '100', '1000'];
        $magicNumbers = array_filter($numbers, fn($num) => !in_array($num, $acceptable));
        
        return count($magicNumbers);
    }
    
    /**
     * Calculate maximum nesting level
     * @param string $body Function body
     * @return int Maximum nesting level
     */
    private function calculateMaxNestingLevel(string $body): int
    {
        $maxLevel = 0;
        $currentLevel = 0;
        
        for ($i = 0; $i < strlen($body); $i++) {
            $char = $body[$i];
            
            if ($char === '{') {
                $currentLevel++;
                $maxLevel = max($maxLevel, $currentLevel);
            } elseif ($char === '}') {
                $currentLevel--;
            }
        }
        
        return $maxLevel;
    }
    
    /**
     * Get time estimate description
     * @param int $minutes Technical debt in minutes
     * @return string Human-readable time estimate
     */
    public function formatTimeEstimate(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} minutes";
        } elseif ($minutes < 480) { // Less than 8 hours
            $hours = round($minutes / 60, 1);
            return "{$hours} hours";
        } else {
            $days = round($minutes / 480, 1); // 8-hour days
            return "{$days} days";
        }
    }
    
    /**
     * Get debt severity level
     * @param int $minutes Technical debt in minutes
     * @return string Severity level
     */
    public function getDebtSeverity(int $minutes): string
    {
        if ($minutes <= 15) {
            return 'Low';
        } elseif ($minutes <= 60) {
            return 'Medium';
        } elseif ($minutes <= 240) {
            return 'High';
        } else {
            return 'Critical';
        }
    }
}