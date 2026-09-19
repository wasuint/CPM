<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Progress;

/**
 * Calculates various progress and productivity metrics
 */
class MetricsCalculator
{
    /**
     * Calculates completion percentage
     *
     * @param int $completed Number of completed items
     * @param int $total Total number of items
     * @return float Completion percentage (0-100)
     */
    public function calculateCompletionPercentage(int $completed, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($completed / $total) * 100, 2);
    }

    /**
     * Calculates velocity (items per time unit)
     *
     * @param int $itemsCompleted Number of items completed
     * @param int $timeSpent Time spent in minutes
     * @return float Items per hour
     */
    public function calculateVelocity(int $itemsCompleted, int $timeSpent): float
    {
        if ($timeSpent === 0) {
            return 0.0;
        }
        
        $hoursSpent = $timeSpent / 60;
        return round($itemsCompleted / $hoursSpent, 2);
    }

    /**
     * Estimates remaining time based on current velocity
     *
     * @param int $remainingItems Number of items remaining
     * @param float $currentVelocity Current velocity (items per hour)
     * @return float Estimated hours remaining
     */
    public function estimateRemainingTime(int $remainingItems, float $currentVelocity): float
    {
        if ($currentVelocity <= 0) {
            return 0.0;
        }
        
        return round($remainingItems / $currentVelocity, 1);
    }

    /**
     * Calculates productivity trend
     *
     * @param array $historicalData Array of historical productivity data
     * @return array Trend analysis
     */
    public function calculateTrend(array $historicalData): array
    {
        if (count($historicalData) < 2) {
            return [
                'trend' => 'insufficient_data',
                'direction' => 'unknown',
                'change_percentage' => 0.0
            ];
        }

        $recent = array_slice($historicalData, -2);
        $previous = $recent[0];
        $current = $recent[1];
        
        $change = $current - $previous;
        $changePercentage = $previous != 0 ? ($change / $previous) * 100 : 0;
        
        $direction = 'stable';
        if ($change > 0) {
            $direction = 'improving';
        } elseif ($change < 0) {
            $direction = 'declining';
        }
        
        return [
            'trend' => $direction,
            'direction' => $direction,
            'change_percentage' => round($changePercentage, 2)
        ];
    }

    /**
     * Calculates effort distribution across different categories
     *
     * @param array $effortData Array of effort data by category
     * @return array Effort distribution percentages
     */
    public function calculateEffortDistribution(array $effortData): array
    {
        $total = array_sum($effortData);
        if ($total === 0) {
            return [];
        }
        
        $distribution = [];
        foreach ($effortData as $category => $effort) {
            $distribution[$category] = round(($effort / $total) * 100, 2);
        }
        
        return $distribution;
    }
}