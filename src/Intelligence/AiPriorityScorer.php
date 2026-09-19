<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Intelligence;

/**
 * AI-Optimized Priority Scorer
 *
 * Calculates priority scores for functions to help AI assistants
 * determine which functions to work on next. Considers:
 * - Complexity (higher = more important to fix)
 * - Dependencies (called frequently = higher priority)
 * - Modification recency (recently changed = higher priority)
 * - Critical path analysis (core functionality = higher priority)
 * - Token cost estimation (for AI context optimization)
 */
class AiPriorityScorer
{
    private const COMPLEXITY_WEIGHT = 0.30;
    private const DEPENDENCY_WEIGHT = 0.25;
    private const RECENCY_WEIGHT = 0.20;
    private const CRITICAL_PATH_WEIGHT = 0.15;
    private const ISSUE_WEIGHT = 0.10;

    /**
     * Calculate priority scores for all functions
     *
     * @param array $inventory Inventory data with functions
     * @param array $progress Progress data with function status
     * @return array Functions sorted by priority with scores
     */
    public function scoreFunctions(array $inventory, array $progress): array
    {
        $functions = $inventory['functions'] ?? [];
        $progressByFunction = $this->normalizeProgressByFunction($progress['by_function'] ?? []);
        $dependencyMap = $this->buildDependencyMap($inventory);
        $criticalPaths = $this->identifyCriticalPaths($inventory);

        $scoredFunctions = [];

        foreach ($functions as $function) {
            $functionId = (string)($function['id'] ?? '');
            $status = $progressByFunction[$functionId]['status'] ?? 'pending';

            // Only score pending and has_issues functions
            if (!in_array($status, ['pending', 'has_issues'])) {
                continue;
            }

            $score = $this->calculateFunctionScore(
                $function,
                $dependencyMap[$functionId] ?? [],
                in_array($functionId, $criticalPaths, true),
                $progressByFunction[$functionId] ?? []
            );

            $scoredFunctions[] = [
                'function_id' => $functionId,
                'name' => $function['name'] ?? 'unknown',
                'file' => $function['file_path'] ?? 'unknown',
                'score' => $score,
                'priority_level' => $this->scoreToPriorityLevel($score),
                'estimated_tokens' => $this->estimateTokenCost($function),
                'complexity' => $function['complexity'] ?? 0,
                'reason' => $this->generatePriorityReason($function, $score, $dependencyMap[$functionId] ?? []),
                'status' => $status
            ];
        }

        // Sort by score descending
        usort($scoredFunctions, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scoredFunctions;
    }

    /**
     * Calculate priority score for a single function
     */
    private function calculateFunctionScore(
        array $function,
        array $dependencyInfo,
        bool $isOnCriticalPath,
        array $progressInfo
    ): float {
        $score = 0.0;

        // Complexity score (0-100)
        $complexity = $function['complexity'] ?? 0;
        $complexityScore = min(100, $complexity * 5); // Scale: 20+ complexity = 100 points
        $score += $complexityScore * self::COMPLEXITY_WEIGHT;

        // Dependency score (how many functions call this)
        $dependencyCount = $dependencyInfo['called_by_count'] ?? 0;
        $dependencyScore = min(100, $dependencyCount * 10); // Scale: 10+ callers = 100 points
        $score += $dependencyScore * self::DEPENDENCY_WEIGHT;

        // Recency score (recently modified = higher priority)
        $recencyScore = $this->calculateRecencyScore($progressInfo);
        $score += $recencyScore * self::RECENCY_WEIGHT;

        // Critical path score
        $criticalPathScore = $isOnCriticalPath ? 100 : 0;
        $score += $criticalPathScore * self::CRITICAL_PATH_WEIGHT;

        // Issue score
        $issueScore = $this->calculateIssueScore($function, $progressInfo);
        $score += $issueScore * self::ISSUE_WEIGHT;

        return round($score, 2);
    }

    /**
     * Calculate recency score based on modification time
     */
    private function calculateRecencyScore(array $progressInfo): float
    {
        $lastUpdated = $progressInfo['updated_at'] ?? $progressInfo['last_updated'] ?? null;

        if (!$lastUpdated) {
            return 50; // Neutral score for never-modified functions
        }

        $daysSinceUpdate = (time() - strtotime($lastUpdated)) / 86400;

        if ($daysSinceUpdate < 1) {
            return 100; // Very recent
        } elseif ($daysSinceUpdate < 7) {
            return 75; // Recent
        } elseif ($daysSinceUpdate < 30) {
            return 50; // Moderate
        } else {
            return 25; // Old
        }
    }

    /**
     * Calculate issue score based on function problems
     */
    private function calculateIssueScore(array $function, array $progressInfo): float
    {
        $score = 0;

        // Has explicit issues status
        if (($progressInfo['status'] ?? '') === 'has_issues') {
            $score += 80;
        }

        // Missing documentation
        if (empty($function['docblock']) && ($function['visibility'] ?? '') === 'public') {
            $score += 30;
        }

        // Missing return type
        if (empty($function['return_type'])) {
            $score += 20;
        }

        // Long function (>50 lines)
        $endLine = $function['end_line'] ?? $function['start_line'] ?? 0;
        $startLine = $function['start_line'] ?? 0;
        $lines = $endLine - $startLine;
        if ($lines > 50) {
            $score += 40;
        }

        return min(100, $score);
    }

    /**
     * Build dependency map for all functions
     */
    private function buildDependencyMap(array $inventory): array
    {
        $map = [];
        $functions = $inventory['functions'] ?? [];

        foreach ($functions as $function) {
            $functionId = (string)($function['id'] ?? '');
            $map[$functionId] = [
                'calls' => $function['calls'] ?? [],
                'called_by' => [],
                'called_by_count' => 0
            ];
        }

        // Build reverse dependency map
        foreach ($functions as $function) {
            $functionId = (string)($function['id'] ?? '');
            $calls = $function['calls'] ?? [];

            foreach ($calls as $calledId) {
                $calledId = (string)$calledId;
                if (isset($map[$calledId])) {
                    $map[$calledId]['called_by'][] = $functionId;
                    $map[$calledId]['called_by_count']++;
                }
            }
        }

        return $map;
    }

    /**
     * Identify critical paths in the codebase
     * Critical paths are entry points and heavily-used utilities
     */
    private function identifyCriticalPaths(array $inventory): array
    {
        $criticalPaths = [];
        $functions = $inventory['functions'] ?? [];

        foreach ($functions as $function) {
            $functionId = (string)($function['id'] ?? '');
            $name = strtolower($function['name'] ?? '');

            // Entry points
            if ($this->isEntryPoint($name, $function)) {
                $criticalPaths[] = $functionId;
                continue;
            }

            // Core utilities
            if ($this->isCoreUtility($name, $function)) {
                $criticalPaths[] = $functionId;
                continue;
            }
        }

        return $criticalPaths;
    }

    /**
     * Check if function is an entry point
     */
    private function isEntryPoint(string $name, array $function): bool
    {
        $entryPoints = ['execute', 'handle', 'run', 'main', 'process', '__invoke'];

        foreach ($entryPoints as $ep) {
            if (str_contains($name, $ep)) {
                return true;
            }
        }

        // Command execute methods
        if (str_contains($function['file_path'] ?? '', 'Command.php') && $name === 'execute') {
            return true;
        }

        return false;
    }

    /**
     * Check if function is a core utility
     */
    private function isCoreUtility(string $name, array $function): bool
    {
        $corePatterns = ['read', 'write', 'save', 'load', 'parse', 'analyze', 'calculate'];

        foreach ($corePatterns as $pattern) {
            if (str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert score to priority level
     */
    private function scoreToPriorityLevel(float $score): string
    {
        if ($score >= 70) {
            return 'critical';
        } elseif ($score >= 50) {
            return 'high';
        } elseif ($score >= 30) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Estimate token cost for AI context
     * Uses rough heuristic: 1 token ≈ 4 characters
     */
    private function estimateTokenCost(array $function): int
    {
        $body = $function['body'] ?? '';
        $docblock = $function['docblock'] ?? '';
        $signature = $function['signature'] ?? '';

        $totalChars = strlen($body) + strlen($docblock) + strlen($signature);
        return (int)($totalChars / 4);
    }

    /**
     * Generate human-readable priority reason
     */
    private function generatePriorityReason(array $function, float $score, array $dependencyInfo): string
    {
        $reasons = [];

        $complexity = $function['complexity'] ?? 0;
        if ($complexity > 15) {
            $reasons[] = "high complexity ({$complexity})";
        }

        $calledBy = $dependencyInfo['called_by_count'] ?? 0;
        if ($calledBy > 5) {
            $reasons[] = "used by {$calledBy} functions";
        }

        if (empty($function['docblock']) && ($function['visibility'] ?? '') === 'public') {
            $reasons[] = "missing documentation";
        }

        if (empty($function['return_type'])) {
            $reasons[] = "missing return type";
        }

        $lines = ($function['end_line'] ?? 0) - ($function['start_line'] ?? 0);
        if ($lines > 50) {
            $reasons[] = "long function ({$lines} lines)";
        }

        if (empty($reasons)) {
            return "standard priority";
        }

        return implode(', ', $reasons);
    }

    /**
     * Normalize progress by function data
     */
    private function normalizeProgressByFunction($byFunction): array
    {
        if (is_object($byFunction)) {
            $byFunction = (array)$byFunction;
        }

        $normalized = [];
        if (!is_array($byFunction)) {
            return $normalized;
        }

        foreach ($byFunction as $functionId => $entry) {
            $normalized[(string)$functionId] = is_object($entry) ? (array)$entry : (array)$entry;
        }

        return $normalized;
    }

    /**
     * Get top N priority functions for AI to work on
     *
     * @param array $scoredFunctions Scored functions from scoreFunctions()
     * @param int $limit Number of functions to return
     * @param int $maxTokens Maximum total tokens for AI context window
     * @return array Top priority functions within token budget
     */
    public function getTopPriorityFunctions(array $scoredFunctions, int $limit = 10, int $maxTokens = 50000): array
    {
        $selected = [];
        $totalTokens = 0;

        foreach ($scoredFunctions as $function) {
            if (count($selected) >= $limit) {
                break;
            }

            $functionTokens = $function['estimated_tokens'] ?? 0;
            if ($totalTokens + $functionTokens > $maxTokens) {
                continue; // Skip if it would exceed token budget
            }

            $selected[] = $function;
            $totalTokens += $functionTokens;
        }

        return [
            'functions' => $selected,
            'total_tokens' => $totalTokens,
            'count' => count($selected)
        ];
    }

    /**
     * Group functions by file for efficient AI processing
     *
     * @param array $scoredFunctions Scored functions
     * @return array Functions grouped by file with aggregate metrics
     */
    public function groupByFile(array $scoredFunctions): array
    {
        $grouped = [];

        foreach ($scoredFunctions as $function) {
            $file = $function['file'] ?? 'unknown';

            if (!isset($grouped[$file])) {
                $grouped[$file] = [
                    'file' => $file,
                    'functions' => [],
                    'total_score' => 0.0,
                    'avg_score' => 0.0,
                    'total_tokens' => 0,
                    'count' => 0,
                    'priority_level' => 'low'
                ];
            }

            $grouped[$file]['functions'][] = $function;
            $grouped[$file]['total_score'] += $function['score'];
            $grouped[$file]['total_tokens'] += $function['estimated_tokens'];
            $grouped[$file]['count']++;
        }

        // Calculate averages and determine file priority
        foreach ($grouped as $file => &$data) {
            $data['avg_score'] = round($data['total_score'] / $data['count'], 2);
            $data['priority_level'] = $this->scoreToPriorityLevel($data['avg_score']);
        }

        // Sort by average score
        uasort($grouped, fn($a, $b) => $b['avg_score'] <=> $a['avg_score']);

        return array_values($grouped);
    }
}