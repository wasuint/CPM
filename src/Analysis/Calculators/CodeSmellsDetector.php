<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis\Calculators;

/**
 * Detects code smells in functions and files
 * Identifies common anti-patterns and quality issues
 */
class CodeSmellsDetector
{
    /**
     * Detect code smells in a function
     * @param array $function Function data
     * @return int Number of code smells detected
     */
    public function detectInFunction(array $function): int
    {
        $smells = 0;
        $body = $function['body'] ?? '';
        $name = $function['name'] ?? '';
        $parameters = $function['parameters'] ?? [];
        
        // Long method
        if ($this->isLongMethod($function)) {
            $smells++;
        }
        
        // Long parameter list
        if ($this->hasLongParameterList($parameters)) {
            $smells++;
        }
        
        // Duplicate code
        if ($this->hasDuplicateCode($body)) {
            $smells++;
        }
        
        // Large class (if this is a method)
        // This would require file-level analysis
        
        // Feature envy (accessing other objects frequently)
        if ($this->hasFeatureEnvy($body)) {
            $smells++;
        }
        
        // Data clumps (groups of parameters that appear together)
        // This would require cross-function analysis
        
        // Primitive obsession (using primitives instead of objects)
        if ($this->hasPrimitiveObsession($parameters)) {
            $smells++;
        }
        
        // Switch statements (could be polymorphism)
        if ($this->hasComplexSwitch($body)) {
            $smells++;
        }
        
        // Temporary field (variables used only in some methods)
        // This requires class-level analysis
        
        // Refused bequest (subclass doesn't use inherited methods)
        // This requires inheritance analysis
        
        // Comments (excessive commenting might indicate complex code)
        if ($this->hasExcessiveComments($body)) {
            $smells++;
        }
        
        // Dead code
        if ($this->hasDeadCode($body)) {
            $smells++;
        }
        
        // Magic numbers
        if ($this->hasMagicNumbers($body)) {
            $smells++;
        }
        
        // Inconsistent naming
        if ($this->hasInconsistentNaming($name, $parameters)) {
            $smells++;
        }
        
        return $smells;
    }
    
    /**
     * Get detailed smell analysis for a function
     * @param array $function Function data
     * @return array Detailed analysis with specific smells
     */
    public function getDetailedAnalysis(array $function): array
    {
        $smells = [];
        $body = $function['body'] ?? '';
        $name = $function['name'] ?? '';
        $parameters = $function['parameters'] ?? [];
        
        if ($this->isLongMethod($function)) {
            $smells[] = [
                'type' => 'Long Method',
                'severity' => 'medium',
                'description' => 'Function is too long and should be broken down into smaller functions',
                'recommendation' => 'Extract related functionality into separate methods'
            ];
        }
        
        if ($this->hasLongParameterList($parameters)) {
            $smells[] = [
                'type' => 'Long Parameter List',
                'severity' => 'medium', 
                'description' => 'Too many parameters make the function hard to use and understand',
                'recommendation' => 'Group parameters into objects or reduce parameter count'
            ];
        }
        
        if ($this->hasDuplicateCode($body)) {
            $smells[] = [
                'type' => 'Duplicate Code',
                'severity' => 'high',
                'description' => 'Similar code patterns found that should be extracted',
                'recommendation' => 'Extract common code into shared methods'
            ];
        }
        
        if ($this->hasFeatureEnvy($body)) {
            $smells[] = [
                'type' => 'Feature Envy',
                'severity' => 'medium',
                'description' => 'Function seems more interested in other classes than its own',
                'recommendation' => 'Move method to the class it uses most'
            ];
        }
        
        if ($this->hasPrimitiveObsession($parameters)) {
            $smells[] = [
                'type' => 'Primitive Obsession',
                'severity' => 'low',
                'description' => 'Using primitive types instead of small objects for simple tasks',
                'recommendation' => 'Create value objects for related primitive parameters'
            ];
        }
        
        if ($this->hasComplexSwitch($body)) {
            $smells[] = [
                'type' => 'Complex Switch Statement',
                'severity' => 'medium',
                'description' => 'Complex switch statement that could be replaced with polymorphism',
                'recommendation' => 'Consider using polymorphism or strategy pattern'
            ];
        }
        
        if ($this->hasExcessiveComments($body)) {
            $smells[] = [
                'type' => 'Excessive Comments',
                'severity' => 'low',
                'description' => 'Too many comments might indicate complex code that needs simplification',
                'recommendation' => 'Simplify code to reduce need for explanatory comments'
            ];
        }
        
        if ($this->hasDeadCode($body)) {
            $smells[] = [
                'type' => 'Dead Code',
                'severity' => 'low',
                'description' => 'Unused code that should be removed',
                'recommendation' => 'Remove commented out or unreachable code'
            ];
        }
        
        if ($this->hasMagicNumbers($body)) {
            $smells[] = [
                'type' => 'Magic Numbers',
                'severity' => 'low',
                'description' => 'Numeric literals without explanation',
                'recommendation' => 'Replace magic numbers with named constants'
            ];
        }
        
        if ($this->hasInconsistentNaming($name, $parameters)) {
            $smells[] = [
                'type' => 'Inconsistent Naming',
                'severity' => 'low',
                'description' => 'Naming conventions are not consistent',
                'recommendation' => 'Use consistent naming conventions throughout'
            ];
        }
        
        return $smells;
    }
    
    /**
     * Check if method is too long
     */
    private function isLongMethod(array $function): bool
    {
        $body = $function['body'] ?? '';
        $lines = explode("\n", array_filter(explode("\n", $body), 'trim'));
        
        return count($lines) > 30; // More than 30 lines is considered long
    }
    
    /**
     * Check for long parameter list
     */
    private function hasLongParameterList(array $parameters): bool
    {
        return count($parameters) > 4; // More than 4 parameters
    }
    
    /**
     * Check for duplicate code patterns
     */
    private function hasDuplicateCode(string $body): bool
    {
        $lines = explode("\n", $body);
        $trimmedLines = array_map('trim', $lines);
        $nonEmptyLines = array_filter($trimmedLines);
        
        // Simple check: if we have fewer unique lines than total lines
        return count($nonEmptyLines) > count(array_unique($nonEmptyLines)) * 1.2;
    }
    
    /**
     * Check for feature envy (excessive use of other objects)
     */
    private function hasFeatureEnvy(string $body): bool
    {
        // Count method calls on other objects vs own methods
        $externalCalls = preg_match_all('/\$\w+->\w+/', $body);
        $totalLines = count(explode("\n", $body));
        
        // If more than 30% of lines are external method calls
        return $totalLines > 0 && ($externalCalls / $totalLines) > 0.3;
    }
    
    /**
     * Check for primitive obsession
     */
    private function hasPrimitiveObsession(array $parameters): bool
    {
        $primitiveCount = 0;
        
        foreach ($parameters as $param) {
            $type = $param['type'] ?? 'mixed';
            if (in_array($type, ['string', 'int', 'float', 'bool', 'array', 'mixed'])) {
                $primitiveCount++;
            }
        }
        
        return $primitiveCount > 3; // More than 3 primitive parameters
    }
    
    /**
     * Check for complex switch statements
     */
    private function hasComplexSwitch(string $body): bool
    {
        // Count switch statements and their cases
        $switchCount = preg_match_all('/switch\s*\(/i', $body);
        $caseCount = preg_match_all('/case\s+/i', $body);
        
        return $switchCount > 0 && $caseCount > 5; // Switch with more than 5 cases
    }
    
    /**
     * Check for excessive comments
     */
    private function hasExcessiveComments(string $body): bool
    {
        $totalLines = count(explode("\n", $body));
        $commentLines = preg_match_all('/^\s*\/\/|^\s*\/\*|^\s*\*/m', $body);
        
        return $totalLines > 0 && ($commentLines / $totalLines) > 0.3; // More than 30% comments
    }
    
    /**
     * Check for dead code
     */
    private function hasDeadCode(string $body): bool
    {
        // Look for commented out code or unreachable code
        $commentedCode = preg_match_all('/\/\/\s*[a-zA-Z_$][\w]*\s*[=\(]/', $body);
        $unreachableAfterReturn = preg_match_all('/return\s+[^;]+;\s*\n\s*[a-zA-Z_$]/', $body);
        
        return $commentedCode > 2 || $unreachableAfterReturn > 0;
    }
    
    /**
     * Check for magic numbers
     */
    private function hasMagicNumbers(string $body): bool
    {
        // Find numeric literals excluding common acceptable values
        preg_match_all('/\b(\d+(?:\.\d+)?)\b/', $body, $matches);
        $numbers = $matches[1];
        
        // Filter out acceptable numbers
        $acceptable = ['0', '1', '2', '10', '100', '1000'];
        $magicNumbers = array_filter($numbers, fn($num) => !in_array($num, $acceptable));
        
        return count($magicNumbers) > 2;
    }
    
    /**
     * Check for inconsistent naming
     */
    private function hasInconsistentNaming(string $functionName, array $parameters): bool
    {
        // Check if function name follows convention
        $isConsistent = true;
        
        // Function name should be camelCase or snake_case consistently
        $hasCamelCase = preg_match('/^[a-z]+([A-Z][a-z]*)*$/', $functionName);
        $hasSnakeCase = preg_match('/^[a-z]+(_[a-z]+)*$/', $functionName);
        
        if (!$hasCamelCase && !$hasSnakeCase) {
            return true; // Inconsistent function naming
        }
        
        // Check parameter naming consistency
        foreach ($parameters as $param) {
            $paramName = $param['name'] ?? '';
            $paramHasCamelCase = preg_match('/^[a-z]+([A-Z][a-z]*)*$/', $paramName);
            $paramHasSnakeCase = preg_match('/^[a-z]+(_[a-z]+)*$/', $paramName);
            
            // If function uses camelCase, parameters should too
            if ($hasCamelCase && !$paramHasCamelCase) {
                return true;
            }
            
            // If function uses snake_case, parameters should too
            if ($hasSnakeCase && !$paramHasSnakeCase) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get smell severity level
     * @param array $smells List of detected smells
     * @return string Overall severity
     */
    public function getOverallSeverity(array $smells): string
    {
        if (empty($smells)) {
            return 'Clean';
        }
        
        $highCount = count(array_filter($smells, fn($s) => $s['severity'] === 'high'));
        $mediumCount = count(array_filter($smells, fn($s) => $s['severity'] === 'medium'));
        
        if ($highCount > 0) {
            return 'High';
        } elseif ($mediumCount > 2) {
            return 'High';
        } elseif ($mediumCount > 0) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }
}