<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

/**
 * Represents the complete analysis results for a project
 */
class AnalysisResult
{
    private array $fileAnalyses;
    private array $functionInventory;
    private array $statistics;
    private array $architectureInfo;
    private array $qualityMetrics;
    private float $completionTime;

    /**
     * Initializes analysis result with data
     *
     * @param array $fileAnalyses Individual file analysis results
     * @param array $functionInventory Complete function inventory
     * @param array $statistics Project statistics
     * @param array $architectureInfo Architecture analysis results
     * @param array $qualityMetrics Quality metrics and scores
     * @param float $completionTime Analysis completion time in seconds
     */
    public function __construct(
        array $fileAnalyses,
        array $functionInventory,
        array $statistics,
        array $architectureInfo = [],
        array $qualityMetrics = [],
        float $completionTime = 0.0
    ) {
        $this->fileAnalyses = $fileAnalyses;
        $this->functionInventory = $functionInventory;
        $this->statistics = $statistics;
        $this->architectureInfo = $architectureInfo;
        $this->qualityMetrics = $qualityMetrics;
        $this->completionTime = $completionTime;
    }

    /**
     * Gets all file analysis results
     *
     * @return array File analysis results
     */
    public function getFileAnalyses(): array
    {
        return $this->fileAnalyses;
    }

    /**
     * Gets the complete function inventory
     *
     * @return array Function inventory
     */
    public function getFunctionInventory(): array
    {
        return $this->functionInventory;
    }

    /**
     * Gets project statistics
     *
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Gets architecture information
     *
     * @return array Architecture info
     */
    public function getArchitectureInfo(): array
    {
        return $this->architectureInfo;
    }

    /**
     * Gets quality metrics
     *
     * @return array Quality metrics
     */
    public function getQualityMetrics(): array
    {
        return $this->qualityMetrics;
    }

    /**
     * Gets analysis completion time
     *
     * @return float Completion time in seconds
     */
    public function getCompletionTime(): float
    {
        return $this->completionTime;
    }

    /**
     * Converts result to array for database storage
     *
     * @return array Array representation
     */
    public function toArray(): array
    {
        return [
            'file_analyses' => $this->fileAnalyses,
            'function_inventory' => $this->functionInventory,
            'statistics' => $this->statistics,
            'architecture_info' => $this->architectureInfo,
            'quality_metrics' => $this->qualityMetrics,
            'completion_time' => $this->completionTime,
            'analyzed_at' => date('c')
        ];
    }
}