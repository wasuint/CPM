<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analysis;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Analysis\Calculators\CyclomaticComplexityCalculator;
use ClaudeProjectManager\Analysis\Calculators\MaintainabilityIndexCalculator;
use ClaudeProjectManager\Analysis\Calculators\TechnicalDebtCalculator;
use ClaudeProjectManager\Analysis\Calculators\CodeSmellsDetector;

/**
 * Collects code quality metrics for files and functions
 * Tracks metrics over time to show improvement trends
 */
class MetricsCollector
{
    private DatabaseManager $database;
    private array $calculators;
    
    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
        $this->calculators = [
            'complexity' => new CyclomaticComplexityCalculator(),
            'maintainability' => new MaintainabilityIndexCalculator(), 
            'technical_debt' => new TechnicalDebtCalculator(),
            'code_smells' => new CodeSmellsDetector()
        ];
    }
    
    /**
     * Collect all metrics for a file
     * @param string $filePath Path to the file
     * @param array $functions Function data from inventory
     * @return array Complete metrics snapshot
     */
    public function collectFileMetrics(string $filePath, array $functions): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: $filePath");
        }
        
        $fileHash = md5_file($filePath);
        $functionMetrics = [];
        $totalComplexity = 0;
        $totalLength = 0;
        $totalTechnicalDebt = 0;
        $totalSmells = 0;
        
        // Collect function-level metrics
        foreach ($functions as $function) {
            $funcMetrics = $this->collectFunctionMetrics($function);
            $functionMetrics[$function['name'] ?? 'unknown'] = $funcMetrics;
            
            $totalComplexity += $funcMetrics['complexity'];
            $totalLength += $funcMetrics['length'];
            $totalTechnicalDebt += $funcMetrics['technical_debt'];
            $totalSmells += $funcMetrics['code_smells'] ?? 0;
        }
        
        $functionCount = count($functions);
        $avgFunctionLength = $functionCount > 0 ? $totalLength / $functionCount : 0;
        $maintainabilityIndex = $this->calculateFileMaintainabilityIndex($functions);
        
        return [
            'timestamp' => date('c'),
            'file_hash' => $fileHash,
            'metrics' => [
                'cyclomatic_complexity' => $totalComplexity,
                'function_count' => $functionCount,
                'average_function_length' => round($avgFunctionLength, 1),
                'maintainability_index' => $maintainabilityIndex,
                'technical_debt_minutes' => $totalTechnicalDebt,
                'code_smells' => $totalSmells
            ],
            'function_metrics' => $functionMetrics
        ];
    }
    
    /**
     * Collect metrics for all files in inventory
     * @param array $inventory Project inventory data
     * @return array All file metrics
     */
    public function collectProjectMetrics(array $inventory): array
    {
        $projectMetrics = [
            'generated_at' => date('c'),
            'files' => [],
            'project_trends' => $this->updateProjectTrends()
        ];
        
        foreach ($inventory['files'] ?? [] as $filePath => $fileData) {
            try {
                $functions = $fileData['functions'] ?? [];
                $metrics = $this->collectFileMetrics($filePath, $functions);
                
                $projectMetrics['files'][$filePath] = [
                    'current' => $metrics,
                    'history' => $this->getFileMetricsHistory($filePath)
                ];
                
            } catch (\Exception $e) {
                // Log error but continue with other files
                error_log("Failed to collect metrics for $filePath: " . $e->getMessage());
                continue;
            }
        }
        
        // Store metrics
        $this->database->write('metrics', $projectMetrics);
        
        return $projectMetrics;
    }
    
    /**
     * Compare metrics between two snapshots
     * @param array $before Previous metrics snapshot
     * @param array $after Current metrics snapshot
     * @return array Comparison analysis
     */
    public function compareMetrics(array $before, array $after): array
    {
        $improvements = [];
        $regressions = [];
        $unchanged = [];
        
        $beforeMetrics = $before['metrics'] ?? [];
        $afterMetrics = $after['metrics'] ?? [];
        
        foreach ($beforeMetrics as $metric => $beforeValue) {
            $afterValue = $afterMetrics[$metric] ?? $beforeValue;
            
            if ($beforeValue == 0) {
                continue; // Skip division by zero
            }
            
            $change = $afterValue - $beforeValue;
            $changePercent = ($change / $beforeValue) * 100;
            
            $comparison = [
                'metric' => $metric,
                'before' => $beforeValue,
                'after' => $afterValue,
                'change' => $change,
                'change_percent' => round($changePercent, 1)
            ];
            
            if ($this->isImprovement($metric, $change, abs($changePercent))) {
                $improvements[] = $comparison;
            } elseif ($this->isRegression($metric, $change, abs($changePercent))) {
                $regressions[] = $comparison;
            } else {
                $unchanged[] = $comparison;
            }
        }
        
        return [
            'improvements' => $improvements,
            'regressions' => $regressions,
            'unchanged' => $unchanged,
            'overall_score' => $this->calculateOverallScore($improvements, $regressions),
            'summary' => $this->generateComparisonSummary($improvements, $regressions)
        ];
    }
    
    /**
     * Get metrics history for a file
     * @param string $filePath File path
     * @param int $limit Number of historical snapshots to return
     * @return array Historical metrics
     */
    public function getFileMetricsHistory(string $filePath, int $limit = 10): array
    {
        try {
            $metrics = $this->database->read('metrics');
            $fileData = $metrics['files'][$filePath] ?? [];
            $history = $fileData['history'] ?? [];
            
            // Sort by timestamp descending and limit results
            usort($history, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
            
            return array_slice($history, 0, $limit);
            
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Store metrics snapshot for a file
     * @param string $filePath File path
     * @param array $metrics Metrics to store
     */
    public function storeFileMetrics(string $filePath, array $metrics): void
    {
        try {
            $metricsDb = $this->database->read('metrics');
        } catch (\Exception $e) {
            $metricsDb = [
                'generated_at' => null,
                'files' => [],
                'project_trends' => []
            ];
        }
        
        // Store current metrics
        if (!isset($metricsDb['files'][$filePath])) {
            $metricsDb['files'][$filePath] = [
                'current' => $metrics,
                'history' => []
            ];
        } else {
            // Move current to history
            $current = $metricsDb['files'][$filePath]['current'] ?? null;
            if ($current && $current['file_hash'] !== $metrics['file_hash']) {
                $history = $metricsDb['files'][$filePath]['history'] ?? [];
                $history[] = $current;
                
                // Keep only last 20 snapshots
                $metricsDb['files'][$filePath]['history'] = array_slice($history, -20);
            }
            
            $metricsDb['files'][$filePath]['current'] = $metrics;
        }
        
        $metricsDb['generated_at'] = date('c');
        $this->database->write('metrics', $metricsDb);
    }
    
    /**
     * Collect metrics for individual function
     * @param array $function Function data
     * @return array Function metrics
     */
    private function collectFunctionMetrics(array $function): array
    {
        return [
            'complexity' => $this->calculators['complexity']->calculateForFunction($function),
            'length' => $this->calculateFunctionLength($function),
            'maintainability' => $this->calculators['maintainability']->calculateForFunction($function),
            'parameters' => count($function['parameters'] ?? []),
            'technical_debt' => $this->calculators['technical_debt']->calculateForFunction($function),
            'code_smells' => $this->calculators['code_smells']->detectInFunction($function)
        ];
    }
    
    /**
     * Calculate file-level maintainability index
     * @param array $functions All functions in file
     * @return float Maintainability index (0-100)
     */
    private function calculateFileMaintainabilityIndex(array $functions): float
    {
        if (empty($functions)) {
            return 100.0;
        }
        
        $totalIndex = 0;
        $validFunctions = 0;
        
        foreach ($functions as $function) {
            $index = $this->calculators['maintainability']->calculateForFunction($function);
            if ($index > 0) {
                $totalIndex += $index;
                $validFunctions++;
            }
        }
        
        return $validFunctions > 0 ? round($totalIndex / $validFunctions, 1) : 100.0;
    }
    
    /**
     * Calculate function length in lines
     * @param array $function Function data
     * @return int Lines of code
     */
    private function calculateFunctionLength(array $function): int
    {
        if (isset($function['end_line']) && isset($function['start_line'])) {
            return max(1, $function['end_line'] - $function['start_line'] + 1);
        }
        
        // Fallback: estimate from body
        $body = $function['body'] ?? '';
        return max(1, substr_count($body, "\n") + 1);
    }
    
    /**
     * Determine if a metric change is an improvement
     * @param string $metric Metric name
     * @param float $change Absolute change
     * @param float $changePercent Percentage change
     * @return bool True if improvement
     */
    private function isImprovement(string $metric, float $change, float $changePercent): bool
    {
        // For most metrics, lower is better
        $lowerIsBetter = [
            'cyclomatic_complexity',
            'technical_debt_minutes', 
            'code_smells',
            'average_function_length'
        ];
        
        // For maintainability, higher is better
        $higherIsBetter = [
            'maintainability_index'
        ];
        
        if (in_array($metric, $lowerIsBetter)) {
            return $change < 0 && $changePercent >= 5; // Decreased by at least 5%
        }
        
        if (in_array($metric, $higherIsBetter)) {
            return $change > 0 && $changePercent >= 5; // Increased by at least 5%
        }
        
        return false;
    }
    
    /**
     * Determine if a metric change is a regression
     * @param string $metric Metric name
     * @param float $change Absolute change
     * @param float $changePercent Percentage change
     * @return bool True if regression
     */
    private function isRegression(string $metric, float $change, float $changePercent): bool
    {
        // Opposite of improvement logic
        $lowerIsBetter = [
            'cyclomatic_complexity',
            'technical_debt_minutes', 
            'code_smells',
            'average_function_length'
        ];
        
        $higherIsBetter = [
            'maintainability_index'
        ];
        
        if (in_array($metric, $lowerIsBetter)) {
            return $change > 0 && $changePercent >= 10; // Increased by at least 10%
        }
        
        if (in_array($metric, $higherIsBetter)) {
            return $change < 0 && $changePercent >= 10; // Decreased by at least 10%
        }
        
        return false;
    }
    
    /**
     * Calculate overall quality score from comparison
     * @param array $improvements List of improvements
     * @param array $regressions List of regressions
     * @return float Score from -100 to +100
     */
    private function calculateOverallScore(array $improvements, array $regressions): float
    {
        $improvementScore = count($improvements) * 10;
        $regressionScore = count($regressions) * -15; // Regressions weighted higher
        
        return max(-100, min(100, $improvementScore + $regressionScore));
    }
    
    /**
     * Generate comparison summary text
     * @param array $improvements List of improvements
     * @param array $regressions List of regressions
     * @return string Summary text
     */
    private function generateComparisonSummary(array $improvements, array $regressions): string
    {
        $improvementCount = count($improvements);
        $regressionCount = count($regressions);
        
        if ($improvementCount > 0 && $regressionCount === 0) {
            return "Code quality improved with $improvementCount positive changes.";
        }
        
        if ($regressionCount > 0 && $improvementCount === 0) {
            return "Code quality declined with $regressionCount negative changes.";
        }
        
        if ($improvementCount > 0 && $regressionCount > 0) {
            return "Mixed results: $improvementCount improvements, $regressionCount regressions.";
        }
        
        return "No significant changes in code quality metrics.";
    }
    
    /**
     * Update project trends with current data
     * @return array Updated trends data
     */
    private function updateProjectTrends(): array
    {
        try {
            $metrics = $this->database->read('metrics');
            $trends = $metrics['project_trends'] ?? [];
        } catch (\Exception $e) {
            $trends = [
                'overall_complexity' => [],
                'maintainability_index' => [],
                'technical_debt_hours' => []
            ];
        }
        
        $today = date('Y-m-d');
        
        // Calculate today's totals (would be populated by collectProjectMetrics)
        $trends['overall_complexity'][$today] = 0; // Placeholder
        $trends['maintainability_index'][$today] = 0; // Placeholder  
        $trends['technical_debt_hours'][$today] = 0; // Placeholder
        
        // Keep only last 90 days
        foreach ($trends as $metric => &$data) {
            $cutoff = date('Y-m-d', strtotime('-90 days'));
            $data = array_filter($data, fn($date) => $date >= $cutoff, ARRAY_FILTER_USE_KEY);
        }
        
        return $trends;
    }
}