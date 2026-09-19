<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

/**
 * Adaptive Tracking Manager
 *
 * Automatically adjusts tracking granularity based on project size
 * to optimize performance for AI assistants working with large codebases.
 *
 * Tracking Modes:
 * - minimal: Track only modified + critical functions (>2000 functions)
 * - balanced: Track changed + complex functions (500-2000 functions)
 * - comprehensive: Track all functions (<500 functions)
 */
class AdaptiveTrackingManager
{
    private const SMALL_PROJECT_THRESHOLD = 500;
    private const MEDIUM_PROJECT_THRESHOLD = 2000;

    private const MODE_MINIMAL = 'minimal';
    private const MODE_BALANCED = 'balanced';
    private const MODE_COMPREHENSIVE = 'comprehensive';

    /**
     * Determine recommended tracking mode based on project size
     *
     * @param int $totalFunctions Total number of functions in project
     * @return string Recommended tracking mode
     */
    public function recommendTrackingMode(int $totalFunctions): string
    {
        if ($totalFunctions < self::SMALL_PROJECT_THRESHOLD) {
            return self::MODE_COMPREHENSIVE;
        } elseif ($totalFunctions < self::MEDIUM_PROJECT_THRESHOLD) {
            return self::MODE_BALANCED;
        } else {
            return self::MODE_MINIMAL;
        }
    }

    /**
     * Get tracking mode description
     *
     * @param string $mode Tracking mode
     * @return string Human-readable description
     */
    public function getModeDescription(string $mode): string
    {
        return match($mode) {
            self::MODE_MINIMAL => 'Track modified + critical functions only (optimized for large codebases)',
            self::MODE_BALANCED => 'Track changed + complex functions (balanced approach)',
            self::MODE_COMPREHENSIVE => 'Track all functions (full coverage)',
            default => 'Unknown mode'
        };
    }

    /**
     * Filter functions based on tracking mode
     *
     * @param array $functions All functions
     * @param string $mode Tracking mode
     * @param array $recentlyModified List of recently modified function IDs
     * @return array Filtered functions to track
     */
    public function filterFunctionsForTracking(
        array $functions,
        string $mode,
        array $recentlyModified = []
    ): array {
        switch ($mode) {
            case self::MODE_MINIMAL:
                return $this->filterMinimal($functions, $recentlyModified);

            case self::MODE_BALANCED:
                return $this->filterBalanced($functions, $recentlyModified);

            case self::MODE_COMPREHENSIVE:
            default:
                return $functions;
        }
    }

    /**
     * Minimal tracking: Only modified + critical functions
     */
    private function filterMinimal(array $functions, array $recentlyModified): array
    {
        $filtered = [];

        foreach ($functions as $function) {
            $functionId = (string)($function['id'] ?? '');

            // Include if recently modified
            if (in_array($functionId, $recentlyModified, true)) {
                $filtered[] = $function;
                continue;
            }

            // Include if critical (entry points, core utilities)
            if ($this->isCriticalFunction($function)) {
                $filtered[] = $function;
                continue;
            }

            // Include if has issues
            if (($function['status'] ?? 'pending') === 'has_issues') {
                $filtered[] = $function;
                continue;
            }
        }

        return $filtered;
    }

    /**
     * Balanced tracking: Changed + complex functions
     */
    private function filterBalanced(array $functions, array $recentlyModified): array
    {
        $filtered = [];

        foreach ($functions as $function) {
            $functionId = (string)($function['id'] ?? '');

            // Include if recently modified
            if (in_array($functionId, $recentlyModified, true)) {
                $filtered[] = $function;
                continue;
            }

            // Include if critical
            if ($this->isCriticalFunction($function)) {
                $filtered[] = $function;
                continue;
            }

            // Include if complex (complexity > 10)
            if (($function['complexity'] ?? 0) > 10) {
                $filtered[] = $function;
                continue;
            }

            // Include if has issues or in progress
            $status = $function['status'] ?? 'pending';
            if (in_array($status, ['has_issues', 'in_progress'], true)) {
                $filtered[] = $function;
                continue;
            }

            // Include public methods without documentation
            if (($function['visibility'] ?? '') === 'public' && empty($function['docblock'])) {
                $filtered[] = $function;
                continue;
            }
        }

        return $filtered;
    }

    /**
     * Check if function is critical (entry point or core utility)
     */
    private function isCriticalFunction(array $function): bool
    {
        $name = strtolower($function['name'] ?? '');
        $filePath = $function['file_path'] ?? '';

        // Entry points
        $entryPoints = ['execute', 'handle', 'run', 'main', 'process', '__invoke'];
        foreach ($entryPoints as $ep) {
            if (str_contains($name, $ep)) {
                return true;
            }
        }

        // Command execute methods
        if (str_contains($filePath, 'Command.php') && $name === 'execute') {
            return true;
        }

        // Core utilities
        $corePatterns = ['read', 'write', 'save', 'load', 'parse', 'analyze', 'calculate', 'validate'];
        foreach ($corePatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get tracking statistics for a mode
     *
     * @param array $allFunctions All functions
     * @param string $mode Tracking mode
     * @param array $recentlyModified Recently modified function IDs
     * @return array Statistics
     */
    public function getTrackingStatistics(
        array $allFunctions,
        string $mode,
        array $recentlyModified = []
    ): array {
        $filtered = $this->filterFunctionsForTracking($allFunctions, $mode, $recentlyModified);

        $totalFunctions = count($allFunctions);
        $trackedFunctions = count($filtered);
        $reductionPercentage = $totalFunctions > 0
            ? round((1 - ($trackedFunctions / $totalFunctions)) * 100, 1)
            : 0;

        // Estimate token savings
        $estimatedTokensPerFunction = 200; // Average
        $tokenSavings = ($totalFunctions - $trackedFunctions) * $estimatedTokensPerFunction;

        return [
            'mode' => $mode,
            'description' => $this->getModeDescription($mode),
            'total_functions' => $totalFunctions,
            'tracked_functions' => $trackedFunctions,
            'skipped_functions' => $totalFunctions - $trackedFunctions,
            'reduction_percentage' => $reductionPercentage,
            'estimated_token_savings' => $tokenSavings
        ];
    }

    /**
     * Get configuration recommendations for AI assistants
     *
     * @param int $totalFunctions Total functions in project
     * @return array Recommendations with explanations
     */
    public function getAiRecommendations(int $totalFunctions): array
    {
        $recommendedMode = $this->recommendTrackingMode($totalFunctions);

        $recommendations = [
            'recommended_mode' => $recommendedMode,
            'reason' => $this->getRecommendationReason($totalFunctions, $recommendedMode),
            'benefits' => $this->getModeBenefits($recommendedMode),
            'ai_workflow' => $this->getAiWorkflowGuidance($recommendedMode),
            'alternative_modes' => $this->getAlternativeModes($recommendedMode)
        ];

        return $recommendations;
    }

    /**
     * Get reason for recommendation
     */
    private function getRecommendationReason(int $totalFunctions, string $mode): string
    {
        return match($mode) {
            self::MODE_MINIMAL => sprintf(
                'Your project has %d functions. Minimal mode reduces AI context size by tracking only critical functions.',
                $totalFunctions
            ),
            self::MODE_BALANCED => sprintf(
                'Your project has %d functions. Balanced mode provides good coverage while optimizing AI context.',
                $totalFunctions
            ),
            self::MODE_COMPREHENSIVE => sprintf(
                'Your project has %d functions. Comprehensive mode provides full coverage for smaller projects.',
                $totalFunctions
            ),
            default => 'Unknown mode'
        };
    }

    /**
     * Get benefits of a tracking mode
     */
    private function getModeBenefits(string $mode): array
    {
        return match($mode) {
            self::MODE_MINIMAL => [
                'Significantly reduced token usage for AI context',
                'Faster CPM operations',
                'Focuses AI attention on high-impact functions',
                'Ideal for large codebases (>2000 functions)'
            ],
            self::MODE_BALANCED => [
                'Good balance between coverage and performance',
                'Tracks complex and recently modified functions',
                'Moderate token usage',
                'Suitable for medium projects (500-2000 functions)'
            ],
            self::MODE_COMPREHENSIVE => [
                'Complete coverage of all functions',
                'No functions skipped',
                'Full progress tracking',
                'Best for small projects (<500 functions)'
            ],
            default => []
        };
    }

    /**
     * Get AI workflow guidance for a mode
     */
    private function getAiWorkflowGuidance(string $mode): array
    {
        return match($mode) {
            self::MODE_MINIMAL => [
                'Use `cpm status --focus` to see priority functions',
                'Work on critical/modified functions first',
                'Run `cpm monitor` frequently to detect new changes',
                'Consider using `--ai-priority` flag for smart suggestions'
            ],
            self::MODE_BALANCED => [
                'Use `cpm status --ai-priority` to see complex functions',
                'Balance between new development and refactoring',
                'Run `cpm monitor` after significant changes',
                'Use file filtering for targeted work: `--file path/to/file`'
            ],
            self::MODE_COMPREHENSIVE => [
                'All functions tracked - work systematically',
                'Use `cpm status --detailed` for complete overview',
                'Can work through functions file-by-file',
                'Regular `cpm monitor` keeps progress synced'
            ],
            default => []
        };
    }

    /**
     * Get alternative tracking modes
     */
    private function getAlternativeModes(string $currentMode): array
    {
        $allModes = [self::MODE_MINIMAL, self::MODE_BALANCED, self::MODE_COMPREHENSIVE];
        $alternatives = [];

        foreach ($allModes as $mode) {
            if ($mode !== $currentMode) {
                $alternatives[] = [
                    'mode' => $mode,
                    'description' => $this->getModeDescription($mode),
                    'use_case' => $this->getAlternativeUseCase($mode)
                ];
            }
        }

        return $alternatives;
    }

    /**
     * Get use case for alternative mode
     */
    private function getAlternativeUseCase(string $mode): string
    {
        return match($mode) {
            self::MODE_MINIMAL => 'Switch to minimal if AI context window is being exceeded',
            self::MODE_BALANCED => 'Switch to balanced for more coverage without full tracking overhead',
            self::MODE_COMPREHENSIVE => 'Switch to comprehensive for complete visibility and tracking',
            default => ''
        };
    }

    /**
     * Get all available modes
     *
     * @return array List of all tracking modes
     */
    public function getAllModes(): array
    {
        return [
            self::MODE_MINIMAL,
            self::MODE_BALANCED,
            self::MODE_COMPREHENSIVE
        ];
    }

    /**
     * Validate tracking mode
     *
     * @param string $mode Mode to validate
     * @return bool True if valid
     */
    public function isValidMode(string $mode): bool
    {
        return in_array($mode, $this->getAllModes(), true);
    }
}