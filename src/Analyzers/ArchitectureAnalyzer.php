<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Analyzers;

use ClaudeProjectManager\ConfigManager;

/**
 * Analyzes project architecture and dependencies
 * Provides high-level insights about project structure
 */
class ArchitectureAnalyzer
{
    private ConfigManager $config;

    /**
     * Initializes architecture analyzer
     *
     * @param ConfigManager $config Configuration manager
     */
    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
    }

    /**
     * Analyzes project architecture
     *
     * @param string $projectRoot Project root directory
     * @param array $fileAnalyses Individual file analysis results
     * @return array Architecture analysis results
     */
    public function analyze(string $projectRoot, array $fileAnalyses): array
    {
        $directoryStructure = $this->analyzeDirectoryStructure($projectRoot);
        $dependencies = $this->analyzeDependencies($projectRoot);
        $patterns = $this->identifyPatterns($fileAnalyses);

        return [
            'directory_structure' => $directoryStructure,
            'dependencies' => $dependencies,
            'patterns' => $patterns,
            'complexity_score' => $this->calculateComplexityScore($fileAnalyses),
            'maintainability_index' => $this->calculateMaintainabilityIndex($fileAnalyses)
        ];
    }

    /**
     * Analyzes directory structure
     *
     * @param string $projectRoot Project root directory
     * @return array Directory structure analysis
     */
    private function analyzeDirectoryStructure(string $projectRoot): array
    {
        $structure = [
            'depth' => 0,
            'directories' => 0,
            'patterns' => []
        ];

        if (is_dir($projectRoot . '/src')) {
            $structure['patterns'][] = 'src_directory';
        }
        if (is_dir($projectRoot . '/tests')) {
            $structure['patterns'][] = 'test_directory';
        }
        if (file_exists($projectRoot . '/composer.json')) {
            $structure['patterns'][] = 'composer_project';
        }

        return $structure;
    }

    /**
     * Analyzes project dependencies
     *
     * @param string $projectRoot Project root directory
     * @return array Dependencies analysis
     */
    private function analyzeDependencies(string $projectRoot): array
    {
        $dependencies = [
            'composer_dependencies' => [],
            'internal_dependencies' => []
        ];

        $composerFile = $projectRoot . '/composer.json';
        if (file_exists($composerFile)) {
            $composerData = json_decode(file_get_contents($composerFile), true);
            if (isset($composerData['require'])) {
                $dependencies['composer_dependencies'] = array_keys($composerData['require']);
            }
        }

        return $dependencies;
    }

    /**
     * Identifies architectural patterns
     *
     * @param array $fileAnalyses File analysis results
     * @return array Identified patterns
     */
    private function identifyPatterns(array $fileAnalyses): array
    {
        $patterns = [];

        $totalFiles = count($fileAnalyses);
        $totalClasses = 0;
        $totalFunctions = 0;

        foreach ($fileAnalyses as $analysis) {
            $totalClasses += count($analysis->getClasses());
            $totalFunctions += count($analysis->getFunctions());
        }

        if ($totalClasses > $totalFunctions) {
            $patterns[] = 'object_oriented';
        } else {
            $patterns[] = 'procedural';
        }

        return $patterns;
    }

    /**
     * Calculates overall complexity score
     *
     * @param array $fileAnalyses File analysis results
     * @return float Complexity score
     */
    private function calculateComplexityScore(array $fileAnalyses): float
    {
        if (empty($fileAnalyses)) {
            return 0.0;
        }

        $totalLines = 0;
        $totalFiles = count($fileAnalyses);

        foreach ($fileAnalyses as $analysis) {
            $metrics = $analysis->getMetrics();
            $totalLines += $metrics['lines'] ?? 0;
        }

        $averageLinesPerFile = $totalLines / $totalFiles;
        
        // Simple complexity calculation based on average file size
        return min(10.0, $averageLinesPerFile / 50);
    }

    /**
     * Calculates maintainability index
     *
     * @param array $fileAnalyses File analysis results
     * @return float Maintainability index (0-100)
     */
    private function calculateMaintainabilityIndex(array $fileAnalyses): float
    {
        // Simplified maintainability calculation
        $complexityScore = $this->calculateComplexityScore($fileAnalyses);
        
        // Higher complexity = lower maintainability
        return max(0.0, 100.0 - ($complexityScore * 10));
    }
}