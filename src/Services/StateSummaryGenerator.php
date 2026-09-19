<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Services;

use ClaudeProjectManager\DatabaseManager;

/**
 * Generates lightweight summary from full project state
 * Addresses performance concerns with large STATE.json files
 */
class StateSummaryGenerator
{
    private DatabaseManager $database;
    
    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Generate lightweight project summary
     * @return array Summarized project data
     */
    public function generateSummary(): array
    {
        $project = $this->database->read('project');
        $inventory = $this->database->read('inventory');
        $progress = $this->database->read('progress');
        $sessions = $this->database->read('sessions');
        $tasks = $this->database->read('tasks');

        return [
            'generated_at' => date('c'),
            'project_overview' => $this->buildProjectOverview($project, $inventory),
            'completion_summary' => $this->buildCompletionSummary($inventory, $progress),
            'recent_activity' => $this->buildRecentActivity($sessions, $progress, $inventory, $project),
            'critical_metrics' => $this->buildCriticalMetrics($inventory, $tasks),
            'file_summary' => $this->buildFileSummary($inventory, $progress)
        ];
    }

    /**
     * Build project overview section
     * @return array Project metadata and basic statistics
     */
    private function buildProjectOverview(array $project, array $inventory): array
    {
        $files = $inventory['files'] ?? [];
        if (!is_array($files)) {
            $files = [];
        }

        $totalFunctions = $this->countTotalFunctions($inventory, $project);
        $totalFiles = count($files);

        if ($totalFiles === 0 && isset($project['analysis_stats']['total_files'])) {
            $totalFiles = (int) $project['analysis_stats']['total_files'];
        }

        if ($totalFunctions === 0 && isset($project['analysis_stats']['total_functions'])) {
            $totalFunctions = (int) $project['analysis_stats']['total_functions'];
        }

        return [
            'name' => $project['name'] ?? 'Unknown Project',
            'framework' => $project['detected_framework'] ?? 'Unknown',
            'language' => $project['primary_language'] ?? 'Mixed',
            'total_files' => $totalFiles,
            'total_functions' => $totalFunctions,
            'analyzed_at' => $project['last_analysis'] ?? null
        ];
    }

    /**
     * Build completion summary with progress statistics
     * @return array Completion statistics and percentages
     */
    private function buildCompletionSummary(array $inventory, array $progress): array
    {
        $functions = $inventory['functions'] ?? [];
        $progressByFunction = $this->normalizeProgressByFunction($progress['by_function'] ?? []);
        $statusCounts = [
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'verified' => 0,
            'has_issues' => 0
        ];

        foreach ($functions as $function) {
            $functionId = isset($function['id']) ? (string) $function['id'] : null;
            $status = $function['status'] ?? 'pending';

            if ($functionId !== null && isset($progressByFunction[$functionId]['status'])) {
                $status = $progressByFunction[$functionId]['status'];
            }

            if (!isset($statusCounts[$status])) {
                $status = 'pending';
            }

            $statusCounts[$status]++;
        }

        $total = count($functions);
        if ($total === 0 && isset($progress['global_stats']['total_functions'])) {
            $total = (int) $progress['global_stats']['total_functions'];
        }

        $completed = ($statusCounts['completed'] ?? 0) + ($statusCounts['verified'] ?? 0);
        $completionPercentage = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;
        $lastUpdated = $this->getLatestProgressTimestamp($progressByFunction, $progress['global_stats']['last_updated'] ?? null);

        return [
            'total_functions' => $total,
            'completed' => $completed,
            'in_progress' => $statusCounts['in_progress'] ?? 0,
            'pending' => $statusCounts['pending'] ?? 0,
            'completion_percentage' => $completionPercentage,
            'last_updated' => $lastUpdated
        ];
    }

    /**
     * Build recent activity section
     * @return array Recent changes and session information
     */
    private function buildRecentActivity(array $sessions, array $progress, array $inventory, array $project): array
    {
        $recentSession = $this->getLastSession($sessions);
        $progressByFunction = $this->normalizeProgressByFunction($progress['by_function'] ?? []);
        $functionsById = $this->indexFunctionsById($inventory['functions'] ?? []);
        $projectRoot = $project['root_path'] ?? null;

        $today = date('Y-m-d');
        $filesModified = [];
        $functionsCompleted = 0;
        $recentChanges = [];

        foreach ($progressByFunction as $functionId => $entry) {
            $timestamp = $entry['updated_at'] ?? $entry['last_updated'] ?? null;
            $status = $entry['status'] ?? 'pending';
            $functionInfo = $functionsById[$functionId] ?? [];
            $filePath = $functionInfo['file_path'] ?? $entry['file'] ?? null;
            $functionName = $functionInfo['name'] ?? $entry['name'] ?? 'unknown';

            if ($timestamp) {
                $recentChanges[] = [
                    'file' => $filePath ? $this->makeRelativePath($filePath, (string) $projectRoot) : 'unknown',
                    'function' => $functionName,
                    'action' => $status,
                    'timestamp' => $timestamp
                ];

                $date = date('Y-m-d', strtotime($timestamp));
                if (in_array($status, ['completed', 'verified'], true) && $date === $today) {
                    $functionsCompleted++;
                    if ($filePath) {
                        $filesModified[$filePath] = true;
                    }
                }
            }
        }

        usort($recentChanges, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));
        $recentChanges = array_slice($recentChanges, 0, 5);

        $lastSessionTime = $recentSession['start_time'] ?? $recentSession['started_at'] ?? null;

        return [
            'last_session' => $lastSessionTime,
            'files_modified_today' => count($filesModified),
            'functions_completed_today' => $functionsCompleted,
            'recent_changes' => $recentChanges
        ];
    }

    /**
     * Build critical metrics section
     * @return array Key quality and progress indicators
     */
    private function buildCriticalMetrics(array $inventory, array $tasks): array
    {
        $criticalIssues = $this->countCriticalIssues($tasks);
        $typeStats = $this->analyzeTypeCoverage($inventory);
        
        return [
            'high_complexity_functions' => $this->countHighComplexityFunctions($inventory),
            'missing_documentation' => $this->countMissingDocumentation($inventory),
            'type_coverage' => $typeStats['coverage_percentage'],
            'critical_issues' => $criticalIssues,
            'quality_score' => $this->calculateQualityScore($inventory),
            'debt_ratio' => $this->calculateTechnicalDebtRatio($inventory),
            'test_coverage' => $this->calculateTestCoverage($inventory),
            'complexity_issues' => $this->countComplexityIssues($inventory)
        ];
    }

    /**
     * Build file summary section
     * @return array File analysis statistics by status and language
     */
    private function buildFileSummary(array $inventory, array $progress): array
    {
        $files = $inventory['files'] ?? [];
        $progressByFunction = $this->normalizeProgressByFunction($progress['by_function'] ?? []);
        
        $statusStats = ['fully_analyzed' => 0, 'partially_analyzed' => 0, 'pending_analysis' => 0];
        $languageStats = [];
        $priorityFiles = [];
        
        foreach ($files as $filePath => $file) {
            // Count by analysis status
            $totalFunctions = $file['total_functions'] ?? count($file['functions'] ?? []);
            $analyzedFunctions = $this->countCompletedFunctionsInFile($file['functions'] ?? [], $progressByFunction);

            if ($totalFunctions > 0 && $analyzedFunctions >= $totalFunctions) {
                $statusStats['fully_analyzed']++;
            } elseif ($analyzedFunctions > 0) {
                $statusStats['partially_analyzed']++;
            } else {
                $statusStats['pending_analysis']++;
            }
            
            // Count by language
            $language = $file['language'] ?? $file['file_type'] ?? 'unknown';
            $languageStats[$language] = ($languageStats[$language] ?? 0) + 1;
            
            // Build priority files list
            $pendingFunctions = $this->countPendingFunctionsInFile($file['functions'] ?? [], $progressByFunction);
            $issues = $this->countIssuesInFile($file);
            
            if ($pendingFunctions > 0 || $issues > 0) {
                $priority = $this->calculateFilePriority($pendingFunctions, $issues, $file);
                $priorityFiles[] = [
                    'file' => $file['relative_path'] ?? basename((string) $filePath),
                    'pending_functions' => $pendingFunctions,
                    'issues' => $issues,
                    'priority' => $priority
                ];
            }
        }
        
        // Sort priority files by priority and pending functions
        usort($priorityFiles, function($a, $b) {
            $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
            $aPriority = $priorityOrder[$a['priority']] ?? 0;
            $bPriority = $priorityOrder[$b['priority']] ?? 0;
            
            if ($aPriority === $bPriority) {
                return $b['pending_functions'] - $a['pending_functions'];
            }
            return $bPriority - $aPriority;
        });
        
        return [
            'by_status' => $statusStats,
            'by_language' => $languageStats,
            'priority_files' => $priorityFiles
        ];
    }

    /**
     * Count total functions across all files
     * @param array $inventory Inventory data
     * @return int Total function count
     */
    private function countTotalFunctions(array $inventory, array $project): int
    {
        $functions = $inventory['functions'] ?? [];
        if (is_array($functions) && !empty($functions)) {
            return count($functions);
        }

        return (int) ($project['analysis_stats']['total_functions'] ?? 0);
    }

    /**
     * Get the most recent session information
     * @param array $sessions Sessions data
     * @return array|null Last session data
     */
    private function getLastSession(array $sessions): ?array
    {
        $sessionList = $sessions['sessions'] ?? $sessions['session_history'] ?? [];

        if (isset($sessions['current_session'])) {
            $sessionList[] = $sessions['current_session'];
        }

        if (empty($sessionList)) {
            return null;
        }
        
        // Sort by start_time and get the last one
        usort($sessionList, function ($a, $b) {
            $aTime = $a['start_time'] ?? $a['started_at'] ?? null;
            $bTime = $b['start_time'] ?? $b['started_at'] ?? null;

            return strtotime((string) $bTime) - strtotime((string) $aTime);
        });
        return $sessionList[0] ?? null;
    }

    /**
     * Get today's changes from progress data
     * @param array $progress Progress data
     * @return array Today's statistics
     */
    private function getTodayChanges(array $progress): array
    {
        // Legacy fallback retained for backward compatibility with older schemas
        return [
            'files_count' => 0,
            'functions_completed' => 0,
            'recent_changes' => []
        ];
    }

    /**
     * Count critical issues from tasks
     * @param array $tasks Tasks data
     * @return int Number of critical issues
     */
    private function countCriticalIssues(array $tasks): int
    {
        $critical = 0;
        foreach ($tasks['tasks'] ?? [] as $task) {
            if (($task['priority'] ?? '') === 'critical' && ($task['status'] ?? '') !== 'completed') {
                $critical++;
            }
        }
        return $critical;
    }

    /**
     * Count functions with high complexity
     * @param array $inventory Inventory data
     * @return int Number of high complexity functions
     */
    private function countHighComplexityFunctions(array $inventory): int
    {
        $count = 0;
        foreach ($inventory['files'] ?? [] as $file) {
            foreach ($file['functions'] ?? [] as $function) {
                if (($function['complexity'] ?? 0) > 15) { // Threshold for high complexity
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Count functions missing documentation
     * @param array $inventory Inventory data
     * @return int Number of undocumented functions
     */
    private function countMissingDocumentation(array $inventory): int
    {
        $count = 0;
        foreach ($inventory['files'] ?? [] as $file) {
            foreach ($file['functions'] ?? [] as $function) {
                if (empty($function['docblock']) || trim($function['docblock']) === '') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Normalize progress map keyed by function id.
     */
    private function normalizeProgressByFunction($byFunction): array
    {
        if (is_object($byFunction)) {
            $byFunction = (array) $byFunction;
        }

        $normalized = [];
        if (!is_array($byFunction)) {
            return $normalized;
        }

        foreach ($byFunction as $functionId => $entry) {
            $normalized[(string) $functionId] = is_object($entry) ? (array) $entry : (array) $entry;
        }

        return $normalized;
    }

    /**
     * Determine the latest progress timestamp.
     */
    private function getLatestProgressTimestamp(array $progressByFunction, ?string $fallback): ?string
    {
        $latest = $fallback;

        foreach ($progressByFunction as $entry) {
            $timestamp = $entry['updated_at'] ?? $entry['last_updated'] ?? null;
            if (!$timestamp) {
                continue;
            }

            if ($latest === null || strtotime($timestamp) > strtotime($latest)) {
                $latest = $timestamp;
            }
        }

        return $latest;
    }

    /**
     * Create map of function id to inventory entry.
     */
    private function indexFunctionsById(array $functions): array
    {
        $indexed = [];
        foreach ($functions as $function) {
            if (!isset($function['id'])) {
                continue;
            }
            $indexed[(string) $function['id']] = $function;
        }
        return $indexed;
    }

    /**
     * Count functions marked as completed/verified for a file.
     */
    private function countCompletedFunctionsInFile(array $functions, array $progressByFunction): int
    {
        $count = 0;

        foreach ($functions as $function) {
            $functionId = isset($function['id']) ? (string) $function['id'] : null;
            $status = $function['status'] ?? 'pending';

            if ($functionId !== null && isset($progressByFunction[$functionId]['status'])) {
                $status = $progressByFunction[$functionId]['status'];
            }

            if (in_array($status, ['completed', 'verified'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Convert absolute path to project-relative path when possible.
     */
    private function makeRelativePath(?string $path, string $projectRoot): string
    {
        if ($path === null || $path === '') {
            return 'unknown';
        }

        if ($projectRoot === '') {
            return $path;
        }

        $normalizedRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        if (str_starts_with($path, $normalizedRoot)) {
            return ltrim(substr($path, strlen($normalizedRoot)), DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /**
     * Analyze type coverage statistics
     * @param array $inventory Inventory data
     * @return array Type coverage statistics
     */
    private function analyzeTypeCoverage(array $inventory): array
    {
        $totalFunctions = 0;
        $typedFunctions = 0;
        
        foreach ($inventory['files'] ?? [] as $file) {
            foreach ($file['functions'] ?? [] as $function) {
                $totalFunctions++;
                
                // Check if function has return type and parameter types
                $hasReturnType = !empty($function['return_type']);
                $hasParameterTypes = true;
                
                foreach ($function['parameters'] ?? [] as $param) {
                    if (empty($param['type'])) {
                        $hasParameterTypes = false;
                        break;
                    }
                }
                
                if ($hasReturnType && $hasParameterTypes) {
                    $typedFunctions++;
                }
            }
        }
        
        return [
            'total_functions' => $totalFunctions,
            'typed_functions' => $typedFunctions,
            'coverage_percentage' => $totalFunctions > 0 ? round($typedFunctions / $totalFunctions * 100, 1) : 0
        ];
    }

    /**
     * Calculate overall code quality score
     */
    private function calculateQualityScore(array $inventory): int
    {
        $total = 0;
        $score = 0;
        
        foreach ($inventory['files'] ?? [] as $file) {
            foreach ($file['functions'] ?? [] as $function) {
                $total++;
                $functionScore = 100;
                
                // Deduct for missing documentation
                if (empty($function['docblock'])) {
                    $functionScore -= 20;
                }
                
                // Deduct for high complexity
                if (($function['complexity'] ?? 0) > 15) {
                    $functionScore -= 30;
                }
                
                // Deduct for missing types
                if (empty($function['return_type'])) {
                    $functionScore -= 15;
                }
                
                $score += max(0, $functionScore);
            }
        }
        
        return $total > 0 ? (int)($score / $total) : 100;
    }

    /**
     * Calculate technical debt ratio (AI-optimized)
     *
     * Uses weighted scoring system instead of binary classification
     * to provide more accurate AI decision-making data.
     */
    private function calculateTechnicalDebtRatio(array $inventory): float
    {
        $totalFunctions = 0;
        $totalDebtScore = 0;

        foreach ($inventory['files'] ?? [] as $file) {
            foreach ($file['functions'] ?? [] as $function) {
                $totalFunctions++;
                $debtScore = $this->calculateFunctionDebtScore($function);
                $totalDebtScore += $debtScore;
            }
        }

        if ($totalFunctions === 0) {
            return 0.0;
        }

        // Average debt score (0-100 per function) converted to percentage
        $averageDebt = ($totalDebtScore / $totalFunctions);
        return round($averageDebt, 1);
    }

    /**
     * Calculate debt score for a single function (0-100)
     *
     * Weighted factors:
     * - Critical issues: 40 points (complexity > 20, missing error handling)
     * - Quality issues: 30 points (excessive complexity 15-20)
     * - Documentation: 15 points (missing docblocks for public methods)
     * - Type safety: 15 points (missing type hints)
     */
    private function calculateFunctionDebtScore(array $function): float
    {
        $score = 0;
        $complexity = $function['complexity'] ?? 0;
        $visibility = $function['visibility'] ?? 'public';
        $name = strtolower($function['name'] ?? '');

        // Critical complexity issues (40 points)
        if ($complexity > 20) {
            $score += 40;
        } elseif ($complexity > 15) {
            // Moderate complexity (30 points)
            $score += 30;
        } elseif ($complexity > 10) {
            // Acceptable but notable complexity (15 points)
            $score += 15;
        }

        // Documentation debt (15 points, but only for public methods)
        if ($visibility === 'public' && empty($function['docblock'])) {
            // Exception: magic methods and constructors get partial weight
            if (str_starts_with($name, '__')) {
                $score += 5; // Reduced weight for magic methods
            } else {
                $score += 15;
            }
        }

        // Type safety debt (15 points)
        if (empty($function['return_type']) && !str_starts_with($name, '__construct')) {
            $score += 10; // Missing return type
        }

        // Parameter types (5 points)
        $hasUntypedParams = false;
        foreach ($function['parameters'] ?? [] as $param) {
            if (empty($param['type'])) {
                $hasUntypedParams = true;
                break;
            }
        }
        if ($hasUntypedParams) {
            $score += 5;
        }

        // Function length issues (10 points)
        $lines = ($function['end_line'] ?? 0) - ($function['start_line'] ?? 0);
        if ($lines > 100) {
            $score += 10; // Very long function
        } elseif ($lines > 50) {
            $score += 5; // Long function
        }

        return min(100, $score);
    }

    /**
     * Calculate test coverage (placeholder - would need test integration)
     */
    private function calculateTestCoverage(array $inventory): float
    {
        // Placeholder implementation - would need integration with test coverage tools
        return 0.0;
    }

    /**
     * Count complexity issues
     */
    private function countComplexityIssues(array $inventory): int
    {
        return $this->countHighComplexityFunctions($inventory);
    }

    /**
     * Count pending functions in a specific file
     */
    private function countPendingFunctionsInFile(array $functions, array $progressByFunction): int
    {
        $count = 0;

        foreach ($functions as $function) {
            $functionId = isset($function['id']) ? (string) $function['id'] : null;
            $status = $function['status'] ?? 'pending';

            if ($functionId !== null && isset($progressByFunction[$functionId]['status'])) {
                $status = $progressByFunction[$functionId]['status'];
            }

            if ($status === 'pending') {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Count issues in a file using intelligent, context-aware analysis
     */
    private function countIssuesInFile(array $file): int
    {
        $filePath = $file['relative_path'] ?? '';
        $fileType = $this->classifyFileType($filePath);
        $functions = $file['functions'] ?? [];
        
        // Critical issues (always count these)
        $criticalIssues = $this->countCriticalFunctionIssues($functions);
        
        // Implementation quality issues (semantic analysis)
        $implementationIssues = $this->countImplementationIssues($functions);
        
        // Cosmetic issues (weighted by file type and context)
        $cosmeticIssues = $this->countCosmeticIssues($functions, $fileType);
        
        // Calculate weighted score
        $totalIssues = ($criticalIssues * 3) + ($implementationIssues * 2) + $cosmeticIssues;
        
        // Apply file-type specific adjustments
        return $this->adjustIssuesForFileType($totalIssues, $fileType, count($functions));
    }
    
    /**
     * Classify file type for context-aware analysis
     */
    private function classifyFileType(string $filePath): string
    {
        if (str_contains($filePath, '/Intelligence/')) return 'intelligence';
        if (str_contains($filePath, '/Commands/')) return 'command';
        if (str_contains($filePath, '/Analysis/')) return 'analysis';
        if (str_contains($filePath, '/Services/')) return 'service';
        if (str_contains($filePath, '/Tests/')) return 'test';
        if (str_contains($filePath, 'Manager.php')) return 'manager';
        if (str_contains($filePath, 'Analyzer.php')) return 'analyzer';
        return 'general';
    }
    
    /**
     * Count critical issues that indicate broken functionality
     */
    private function countCriticalFunctionIssues(array $functions): int
    {
        $issues = 0;
        
        foreach ($functions as $function) {
            $body = $function['body'] ?? '';
            $name = $function['name'] ?? '';
            
            // Detect placeholder implementations
            if ($this->isPlaceholderImplementation($body, $name)) {
                $issues++;
            }
            
            // Detect error-prone patterns
            if ($this->hasErrorPronePatterns($body)) {
                $issues++;
            }
            
            // Detect missing critical error handling
            if ($this->lacksCriticalErrorHandling($body, $name)) {
                $issues++;
            }
        }
        
        return $issues;
    }
    
    /**
     * Count implementation quality issues
     */
    private function countImplementationIssues(array $functions): int
    {
        $issues = 0;
        
        foreach ($functions as $function) {
            $body = $function['body'] ?? '';
            $complexity = $function['complexity'] ?? 0;
            
            // Only flag excessive complexity for non-analysis functions
            if ($complexity > 20 && !$this->isLegitimatelyComplex($function)) {
                $issues++;
            }
            
            // Detect code smells
            if ($this->hasCodeSmells($body)) {
                $issues++;
            }
            
            // Detect inconsistent patterns
            if ($this->hasInconsistentPatterns($body)) {
                $issues++;
            }
        }
        
        return $issues;
    }
    
    /**
     * Count cosmetic issues (documentation, types)
     */
    private function countCosmeticIssues(array $functions, string $fileType): int
    {
        $issues = 0;
        
        // Different standards for different file types
        $requireStrictDocs = in_array($fileType, ['intelligence', 'analysis', 'manager']);
        $requireStrictTypes = in_array($fileType, ['service', 'manager']);
        
        foreach ($functions as $function) {
            $visibility = $function['visibility'] ?? 'public';
            $name = $function['name'] ?? '';
            
            // Public methods should have documentation
            if ($visibility === 'public' && empty($function['docblock'])) {
                $issues++;
            }
            
            // Complex private methods in intelligence files should have docs
            if ($requireStrictDocs && $visibility === 'private' && 
                strlen($function['body'] ?? '') > 200 && empty($function['docblock'])) {
                $issues += 0.5; // Half weight for private methods
            }
            
            // Missing return types (only for non-magic methods)
            if ($requireStrictTypes && empty($function['return_type']) && 
                !str_starts_with($name, '__')) {
                $issues += 0.3; // Low weight for missing types
            }
        }
        
        return (int) round($issues);
    }
    
    /**
     * Detect placeholder implementations
     */
    private function isPlaceholderImplementation(string $body, string $name): bool
    {
        $body = strtolower($body);
        
        // Common placeholder patterns
        $placeholderPatterns = [
            'throw new \\exception(\'not implemented\')',
            'todo:',
            'fixme:',
            'placeholder',
            'return [];',
            'return null;',
            'return false;',
            'return 0;',
            'return \'\';'
        ];
        
        foreach ($placeholderPatterns as $pattern) {
            if (str_contains($body, $pattern)) {
                // But not if it's a deliberate default value with context
                if (str_contains($body, 'default') || str_contains($body, 'empty') || 
                    strlen($body) > 100) {
                    continue;
                }
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detect error-prone patterns
     */
    private function hasErrorPronePatterns(string $body): bool
    {
        $patterns = [
            'file_get_contents\(',  // Direct file operations
            'file_put_contents\(',
            'json_decode\(.*true\)', // JSON without error checking
            '@', // Error suppression
            'empty\(.*trim\(' // Potential null issues
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $body)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if function lacks critical error handling
     */
    private function lacksCriticalErrorHandling(string $body, string $name): bool
    {
        // Methods that should have error handling
        $criticalMethods = ['read', 'write', 'save', 'load', 'parse', 'validate'];
        
        foreach ($criticalMethods as $method) {
            if (str_contains(strtolower($name), $method)) {
                // Should have try-catch or explicit error checking
                if (!str_contains($body, 'try') && !str_contains($body, 'throw') &&
                    !str_contains($body, 'error') && strlen($body) > 50) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if high complexity is legitimate for analysis functions
     */
    private function isLegitimatelyComplex(array $function): bool
    {
        $name = strtolower($function['name'] ?? '');
        $body = $function['body'] ?? '';
        
        // Analysis and pattern detection methods can be legitimately complex
        $complexityExceptions = [
            'analyze', 'detect', 'calculate', 'extract', 'parse', 'generate',
            'process', 'transform', 'validate', 'evaluate'
        ];
        
        foreach ($complexityExceptions as $exception) {
            if (str_contains($name, $exception)) {
                return true;
            }
        }
        
        // Methods with substantial logic (not just conditionals) can be complex
        if (substr_count($body, 'foreach') + substr_count($body, 'for') > 2) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect code smells
     */
    private function hasCodeSmells(string $body): bool
    {
        // God method (too many responsibilities)
        if (substr_count($body, 'new ') > 5 && strlen($body) > 500) {
            return true;
        }
        
        // Duplicated code patterns
        if (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*\$[a-zA-Z_][a-zA-Z0-9_]*/', $body) > 10) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect inconsistent patterns
     */
    private function hasInconsistentPatterns(string $body): bool
    {
        // Mixed error handling styles
        $hasTryCatch = str_contains($body, 'try');
        $hasErrorLog = str_contains($body, 'error_log');
        $hasThrow = str_contains($body, 'throw');
        
        // If method has some error handling but inconsistent
        if (($hasTryCatch && $hasErrorLog && !$hasThrow) || 
            (!$hasTryCatch && $hasThrow)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Apply file-type specific adjustments
     */
    private function adjustIssuesForFileType(int $issues, string $fileType, int $functionCount): int
    {
        switch ($fileType) {
            case 'intelligence':
            case 'analysis':
                // Intelligence and analysis files are expected to be complex
                // Reduce issue count if it's mainly cosmetic
                return max(1, (int) ($issues * 0.6));
                
            case 'command':
                // Command files should be simple
                return $issues;
                
            case 'manager':
                // Manager files are critical, any issues are important
                return $issues;
                
            case 'test':
                // Test files have different quality standards
                return max(0, (int) ($issues * 0.3));
                
            default:
                return $issues;
        }
    }

    /**
     * Calculate file priority based on pending functions and issues
     */
    private function calculateFilePriority(int $pendingFunctions, int $issues, array $file): string
    {
        $score = $pendingFunctions * 2 + $issues;
        
        // Consider file size and importance
        $functionCount = count($file['functions'] ?? []);
        if ($functionCount > 20) {
            $score *= 1.5; // Boost priority for larger files
        }
        
        if ($score >= 20) {
            return 'high';
        } elseif ($score >= 8) {
            return 'medium';
        } else {
            return 'low';
        }
    }
}
