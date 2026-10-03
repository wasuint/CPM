<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\ProjectAnalyzer;
use ClaudeProjectManager\Session\ContextBuilder;
use ClaudeProjectManager\Session\ChangeDetector;
use ClaudeProjectManager\Session\ActivityTracker;
use ClaudeProjectManager\Database\DatabaseFileNotFoundException;

/**
 * Main session management for Claude continuity
 * Coordinates session state and provides context for resumption
 */
class SessionManager
{
    private DatabaseManager $database;
    private ContextBuilder $contextBuilder;
    private ChangeDetector $changeDetector;
    private ?ActivityTracker $activityTracker;
    private ConfigManager $config;
    private string $currentSessionId;
    private ?string $trackedSessionId = null;
    private array $sessionData;

    /**
     * Initializes session manager with required dependencies
     *
     * @param DatabaseManager $database Database manager for session persistence
     * @param ActivityTracker $activityTracker Activity tracking for automatic progress updates
     */
    public function __construct(DatabaseManager $database, ?ActivityTracker $activityTracker = null)
    {
        $this->database = $database;
        $this->config = new ConfigManager(getcwd());
        $this->contextBuilder = new ContextBuilder($database);
        $this->changeDetector = new ChangeDetector($database, new ProjectAnalyzer($database, $this->config));
        $this->activityTracker = $activityTracker;
        $this->currentSessionId = uniqid('session_', true);
        $this->sessionData = [];
        $this->loadCurrentSessionData();
    }

    /**
     * Starts a new Claude session and prepares comprehensive context
     *
     * @param string $command Optional initial command or instruction
     * @return SessionContext Complete context object for Claude
     */
    public function startSession(string $command = ''): SessionContext
    {
        $sessionContext = $this->contextBuilder->buildNewSessionContext($this->currentSessionId, $command);
        
        $this->sessionData = [
            'session_id' => $this->currentSessionId,
            'started_at' => date('c'),
            'command' => $command,
            'status' => 'active',
            'last_activity' => date('c'),
            'activities' => []
        ];

        $this->saveCurrentSession();
        
        // Initialize activity tracking
        if ($this->activityTracker) {
            $this->activityTracker->setCurrentSession($this->currentSessionId);
            $this->trackedSessionId = $this->currentSessionId;
            $this->activityTracker->initializeMonitoring(getcwd());
        }
        
        error_log("Session started: {$this->currentSessionId}");
        
        return $sessionContext;
    }

    /**
     * Updates current session with progress and activity information
     *
     * @param string $currentTask Description of current task being worked on
     * @param array $progressData Current progress information and metrics
     * @return void
     */
    public function updateSession(string $currentTask, array $progressData): void
    {
        $this->loadCurrentSessionData(true);

        if (empty($this->sessionData)) {
            return;
        }

        $this->sessionData['last_task'] = $currentTask;
        $this->sessionData['last_activity'] = date('c');
        $this->sessionData['progress_snapshot'] = $progressData;
        
        if (!isset($this->sessionData['activities']) || !is_array($this->sessionData['activities'])) {
            $this->sessionData['activities'] = [];
        }

        $this->sessionData['activities'][] = [
            'timestamp' => date('c'),
            'description' => $currentTask,
            'type' => 'task_update'
        ];

        $this->saveCurrentSession();
    }

    /**
     * Ends current session and saves final state for future resumption
     *
     * @param string $completionStatus Session completion status
     * @param array $summary Session summary and accomplishments
     * @return void
     */
    public function endSession(string $completionStatus, array $summary): void
    {
        $this->sessionData['ended_at'] = date('c');
        $this->sessionData['completion_status'] = $completionStatus;
        $this->sessionData['summary'] = $summary;
        $this->sessionData['status'] = 'completed';

        // Archive to history
        $this->archiveSession();
        
        // Clear current session
        $this->database->update('sessions', 'current_session', (object) []);
        
        error_log("Session ended: {$this->currentSessionId}");
    }

    /**
     * Resumes previous session and provides continuation context
     *
     * @param bool $skipIncremental Skip incremental analysis for faster startup
     * @return SessionContext Context for resuming where left off
     */
    public function resumeSession(bool $skipIncremental = false): SessionContext
    {
        try {
            $sessions = $this->database->read('sessions');
            $lastSession = $sessions['current_session'] ?? null;

            if (!$lastSession) {
                return $this->startSession('Resume previous work');
            }

            // Detect external changes
            $projectRoot = getcwd();
            $changes = $this->changeDetector->detectChanges($projectRoot);
            
            if ($changes['has_changes']) {
                error_log("External changes detected: " . $this->changeDetector->getChangesSummary($changes));
                
                if ($changes['requires_reanalysis'] && !$skipIncremental) {
                    $changedFiles = array_merge($changes['modified_files'], $changes['added_files']);
                    
                    // CRITICAL FIX: Apply exclusion patterns to filter out unwanted files
                    $filteredFiles = $this->filterFilesByExclusions($changedFiles);
                    $totalFiles = count($filteredFiles);
                    
                    if ($totalFiles === 0) {
                        error_log("All detected changes are in excluded directories (e.g. venv, node_modules). No re-analysis needed.");
                    } else {
                        error_log("Starting incremental re-analysis of {$totalFiles} files (filtered from " . count($changedFiles) . " total changes)...");
                        
                        // Create progress callback for large file sets
                        $progressCallback = null;
                        if ($totalFiles > 50) {
                            $progressCallback = function($current, $total, $filePath) {
                                if ($current % 10 === 0 || $current === $total) {
                                    error_log("Re-analyzing files: {$current}/{$total} ({$filePath})");
                                }
                            };
                        }
                        
                        // Use a reasonable timeout for incremental analysis (5 minutes for normal cases, 10 minutes for large sets)
                        $timeoutSeconds = $totalFiles > 1000 ? 600 : 300;
                        
                        $reanalysisResults = $this->changeDetector->performIncrementalReanalysis(
                            $filteredFiles,
                            $progressCallback,
                            $timeoutSeconds
                        );
                        
                        error_log("Incremental re-analysis completed: " . json_encode(array_merge($reanalysisResults, [
                            'files_processed' => $reanalysisResults['reanalyzed_files'] ?? 0,
                            'total_files' => $totalFiles
                        ])));
                    }
                } elseif ($changes['requires_reanalysis'] && $skipIncremental) {
                    error_log("Incremental analysis skipped due to --skip-incremental flag. Run without this flag for full analysis.");
                }
            }

            $this->currentSessionId = uniqid('session_', true);
            $sessionContext = $this->contextBuilder->buildResumeContext($this->currentSessionId, $lastSession);

            $this->sessionData = [
                'session_id' => $this->currentSessionId,
                'started_at' => date('c'),
                'status' => 'resumed',
                'resumed_from' => $this->getSessionProperty($lastSession, 'session_id'),
                'external_changes' => $changes,
                'last_activity' => date('c'),
                'activities' => []
            ];

            $this->saveCurrentSession();
            
            error_log("Session resumed: {$this->currentSessionId}");

            return $sessionContext;
            
        } catch (\Exception $e) {
            error_log("Failed to resume session: " . $e->getMessage());
            return $this->startSession('Failed to resume - starting fresh');
        }
    }

    /**
     * Provides comprehensive project context for Claude understanding
     *
     * @return array Complete project context information
     */
    public function getProjectContext(): array
    {
        try {
            $project = $this->database->read('project');
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');
            $tasks = $this->database->read('tasks');

            return [
                'project_metadata' => $project,
                'inventory_summary' => [
                    'total_files' => $this->safeCount($inventory['files'] ?? []),
                    'total_functions' => $this->safeCount($inventory['functions'] ?? []),
                    'total_classes' => $this->safeCount($inventory['classes'] ?? [])
                ],
                'progress_summary' => $progress['global_stats'] ?? [],
                'active_tasks' => $tasks['active_tasks'] ?? [],
                'session_id' => $this->currentSessionId
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to load project context: ' . $e->getMessage(),
                'session_id' => $this->currentSessionId
            ];
        }
    }

    /**
     * Tracks function-level progress during current session
     *
     * @param string $functionId Unique function identifier
     * @param string $status New status for the function
     * @param array $metadata Additional metadata about the progress
     * @return void
     */
    public function trackFunctionProgress(string|int $functionId, string $status, array $metadata = []): void
    {
        // Normalize function ID to string (JSON decode may convert numeric strings to ints)
        $functionId = (string)$functionId;

        $metadata['session_id'] = $this->currentSessionId;
        $metadata['tracked_at'] = date('c');

        $this->sessionData['activities'][] = [
            'timestamp' => date('c'),
            'description' => "Function {$functionId} status changed to {$status}",
            'type' => 'function_progress',
            'function_id' => $functionId,
            'status' => $status
        ];

        $this->sessionData['last_activity'] = date('c');
        $this->saveCurrentSession();
        
        // Update progress tracking
        $this->database->update('progress', "by_function.{$functionId}", [
            'status' => $status,
            'updated_at' => date('c'),
            'session_id' => $this->currentSessionId,
            'metadata' => $metadata
        ]);
    }

    /**
     * Creates session checkpoint for recovery purposes
     *
     * @param string $checkpointName Descriptive name for the checkpoint
     * @return void
     */
    public function createCheckpoint(string $checkpointName): void
    {
        $checkpoint = [
            'name' => $checkpointName,
            'created_at' => date('c'),
            'session_id' => $this->currentSessionId,
            'session_data' => $this->sessionData,
            'project_context' => $this->getProjectContext()
        ];

        // Add to session checkpoints
        if (!isset($this->sessionData['checkpoints'])) {
            $this->sessionData['checkpoints'] = [];
        }
        
        $this->sessionData['checkpoints'][] = $checkpoint;
        $this->saveCurrentSession();
        
        error_log("Checkpoint created: {$checkpointName}");
    }

    /**
     * Generates comprehensive status report for current session
     *
     * @return array Detailed session status and progress report
     */
    public function generateStatusReport(): array
    {
        $startTime = strtotime($this->sessionData['started_at'] ?? 'now');
        $currentTime = time();
        $sessionDuration = $currentTime - $startTime;

        return [
            'session_id' => $this->currentSessionId,
            'duration_minutes' => round($sessionDuration / 60, 1),
            'activities_count' => count($this->sessionData['activities'] ?? []),
            'last_activity' => $this->sessionData['last_activity'] ?? null,
            'current_task' => $this->sessionData['last_task'] ?? 'No current task',
            'checkpoints' => count($this->sessionData['checkpoints'] ?? []),
            'status' => $this->sessionData['status'] ?? 'active',
            'project_context' => $this->getProjectContext()
        ];
    }

    /**
     * Gets session history for analysis and reporting
     *
     * @param int $limit Maximum number of sessions to return
     * @return array Historical session data
     */
    public function getSessionHistory(int $limit = 10): array
    {
        try {
            $sessions = $this->database->read('sessions');
            $history = $sessions['session_history'] ?? [];
            
            // Sort by start time (most recent first)
            usort($history, function($a, $b) {
                return strtotime($b['started_at']) - strtotime($a['started_at']);
            });
            
            return array_slice($history, 0, $limit);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Saves current session data to database
     *
     * @return void
     */
    private function saveCurrentSession(): void
    {
        try {
            $this->database->update('sessions', 'current_session', (object) $this->sessionData);
        } catch (\Exception $e) {
            error_log("Failed to save current session: " . $e->getMessage());
        }
    }

    /**
     * Archives current session to history
     *
     * @return void
     */
    private function archiveSession(): void
    {
        try {
            $sessions = $this->database->read('sessions');
            $history = $sessions['session_history'] ?? [];
            
            $history[] = $this->sessionData;
            
            // Keep only last 50 sessions
            if (count($history) > 50) {
                $history = array_slice($history, -50);
            }
            
            $this->database->update('sessions', 'session_history', $history);
        } catch (\Exception $e) {
            error_log("Failed to archive session: " . $e->getMessage());
        }
    }

    /**
     * Perform automatic monitoring check for changes and progress
     *
     * @return array Summary of detected changes and updates
     */
    public function performMonitoringCheck(): array
    {
        if (!$this->activityTracker) {
            return ['error' => 'Activity tracker not available'];
        }
        
        $projectRoot = getcwd();
        $results = [
            'file_changes' => [],
            'completed_functions' => [],
            'activities_detected' => 0
        ];
        
        try {
            // Check for file changes
            $fileChanges = $this->activityTracker->checkForChanges($projectRoot);
            $results['file_changes'] = $fileChanges;
            
            // Scan for completed functions
            $completedFunctions = $this->activityTracker->scanForCompletedFunctions($projectRoot);
            $results['completed_functions'] = $completedFunctions;
            
            // Count total activities
            $results['activities_detected'] = 
                count($fileChanges['modified'] ?? []) + 
                count($fileChanges['added'] ?? []) + 
                count($fileChanges['deleted'] ?? []) + 
                count($completedFunctions);
            
            // Update session with monitoring results
            if ($results['activities_detected'] > 0) {
                $this->updateSession(
                    'Automatic monitoring detected changes',
                    [
                        'monitoring_check' => true,
                        'activities_detected' => $results['activities_detected'],
                        'completed_functions' => count($completedFunctions)
                    ]
                );
            }
            
        } catch (\Exception $e) {
            $results['error'] = 'Monitoring check failed: ' . $e->getMessage();
            error_log('Monitoring check error: ' . $e->getMessage());
        }
        
        return $results;
    }

    /**
     * Start background monitoring (for long-running processes)
     *
     * @param int $intervalSeconds How often to check for changes
     */
    public function startBackgroundMonitoring(int $intervalSeconds = 30): void
    {
        if (!$this->activityTracker) {
            error_log('Cannot start monitoring: ActivityTracker not available');
            return;
        }
        
        $this->activityTracker->trackActivity(
            'monitoring_started',
            'Background monitoring started',
            ['interval_seconds' => $intervalSeconds]
        );
        
        error_log("Background monitoring started with {$intervalSeconds}s interval");
        
        // Note: In a production environment, this would typically be handled by a 
        // separate daemon process or cron job rather than a blocking loop
        while (true) {
            sleep($intervalSeconds);
            
            try {
                $results = $this->performMonitoringCheck();
                if ($results['activities_detected'] > 0) {
                    error_log("Monitoring detected {$results['activities_detected']} activities");
                }
            } catch (\Exception $e) {
                error_log('Background monitoring error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Safely get session property from object or array
     */
    private function getSessionProperty($session, string $property): mixed
    {
        // Handle object (stdClass) access
        if (is_object($session)) {
            return $session->$property ?? null;
        }
        
        // Handle array access
        if (is_array($session)) {
            return $session[$property] ?? null;
        }
        
        return null;
    }

    /**
     * Safely count array or object elements
     */
    private function safeCount($value): int
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_object($value)) {
            return count((array) $value);
        }
        return 0;
    }

    /**
     * Load the persisted current session so commands like monitor can extend it safely.
     */
    private function loadCurrentSessionData(bool $forceRefresh = false): void
    {
        if (!$forceRefresh && !empty($this->sessionData)) {
            return;
        }

        try {
            $sessions = $this->database->read('sessions');
            $currentSession = $this->normalizeSessionRecord($sessions['current_session'] ?? null);

            if (empty($currentSession)) {
                return;
            }

            $this->sessionData = $currentSession;

            if (!empty($currentSession['session_id']) && is_string($currentSession['session_id'])) {
                $this->currentSessionId = $currentSession['session_id'];
            }

            if ($this->activityTracker && $this->trackedSessionId !== $this->currentSessionId) {
                $this->activityTracker->setCurrentSession($this->currentSessionId);
                $this->trackedSessionId = $this->currentSessionId;
            }
        } catch (DatabaseFileNotFoundException $e) {
            // The project has not been initialised yet. That is the normal
            // state for `cpm --version`, `cpm list`, or any invocation from a
            // directory that is not a CPM project, and is not a failure.
            return;
        } catch (\Exception $e) {
            error_log('Failed to load current session: ' . $e->getMessage());
        }
    }

    /**
     * Normalize session data loaded from the database cache or JSON decode.
     */
    private function normalizeSessionRecord(mixed $session): array
    {
        if (is_object($session)) {
            $session = (array) $session;
        }

        if (!is_array($session)) {
            return [];
        }

        if (isset($session['activities'])) {
            if (is_object($session['activities'])) {
                $session['activities'] = (array) $session['activities'];
            }
            if (!is_array($session['activities'])) {
                $session['activities'] = [];
            } else {
                $session['activities'] = array_values($session['activities']);
            }
        }

        return $session;
    }

    /**
     * Filters changed files by applying exclusion patterns
     * This prevents incremental reanalysis of files in venv, node_modules, etc.
     *
     * @param array $changedFiles List of changed file paths
     * @return array Filtered list of files that should be reanalyzed
     */
    private function filterFilesByExclusions(array $changedFiles): array
    {
        $exclusionPatterns = $this->config->getExclusionPatterns();
        $filteredFiles = [];
        
        foreach ($changedFiles as $filePath) {
            $shouldExclude = false;
            
            foreach ($exclusionPatterns as $pattern) {
                // Handle directory patterns (with trailing slash)
                if (str_ends_with($pattern, '/')) {
                    if (strpos($filePath, $pattern) === 0) {
                        $shouldExclude = true;
                        break;
                    }
                }
                // Handle glob patterns with *
                elseif (strpos($pattern, '*') !== false) {
                    if (fnmatch($pattern, $filePath) || fnmatch($pattern, basename($filePath))) {
                        $shouldExclude = true;
                        break;
                    }
                }
                // Handle exact directory matches (add trailing slash for directory check)
                else {
                    if (strpos($filePath, $pattern . '/') === 0) {
                        $shouldExclude = true;
                        break;
                    }
                    // Also check if it's the exact file/directory name
                    if ($filePath === $pattern || basename($filePath) === $pattern) {
                        $shouldExclude = true;
                        break;
                    }
                }
            }
            
            if (!$shouldExclude) {
                $filteredFiles[] = $filePath;
            }
        }
        
        return $filteredFiles;
    }
}
