<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

/**
 * Represents the analysis results for a single file
 */
class FileAnalysis
{
    private string $filePath;
    private string $fileType;
    private array $functions;
    private array $classes;
    private array $metrics;
    private array $issues;
    private array $dependencies;

    /**
     * Initializes file analysis with data
     *
     * @param string $filePath Path to the analyzed file
     * @param string $fileType Type of file (php, python, etc.)
     * @param array $functions Functions found in the file
     * @param array $classes Classes found in the file
     * @param array $metrics File metrics (lines, complexity, etc.)
     * @param array $issues Issues found during analysis
     * @param array $dependencies File dependencies
     */
    public function __construct(
        string $filePath,
        string $fileType,
        array $functions = [],
        array $classes = [],
        array $metrics = [],
        array $issues = [],
        array $dependencies = []
    ) {
        $this->filePath = $filePath;
        $this->fileType = $fileType;
        $this->functions = $functions;
        $this->classes = $classes;
        $this->metrics = $metrics;
        $this->issues = $issues;
        $this->dependencies = $dependencies;
    }

    /**
     * Gets the file path
     *
     * @return string File path
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Gets the file type
     *
     * @return string File type
     */
    public function getFileType(): string
    {
        return $this->fileType;
    }

    /**
     * Gets functions found in the file
     *
     * @return array Functions
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * Gets classes found in the file
     *
     * @return array Classes
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * Gets file metrics
     *
     * @return array Metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Gets issues found
     *
     * @return array Issues
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Gets file dependencies
     *
     * @return array Dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Converts to array for database storage
     *
     * @return array Array representation
     */
    public function toArray(): array
    {
        return [
            'file_path' => $this->filePath,
            'file_type' => $this->fileType,
            'functions' => $this->functions,
            'classes' => $this->classes,
            'metrics' => $this->metrics,
            'issues' => $this->issues,
            'dependencies' => $this->dependencies
        ];
    }
}