<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Session;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\ProjectAnalyzer;
use ClaudeProjectManager\Notifications\TelegramNotifier;

/**
 * Tracks development activities and automatically updates progress
 */
class ActivityTracker
{
    private DatabaseManager $database;
    private ProjectAnalyzer $analyzer;
    private TelegramNotifier $notifier;
    private array $lastFileTimes = [];
    private string $currentSessionId;

    public function __construct(
        DatabaseManager $database,
        ProjectAnalyzer $analyzer,
        TelegramNotifier $notifier
    ) {
        $this->database = $database;
        $this->analyzer = $analyzer;
        $this->notifier = $notifier;
        $this->currentSessionId = '';
    }

    /**
     * Set the current session ID for activity tracking
     */
    public function setCurrentSession(string $sessionId): void
    {
        $this->currentSessionId = $sessionId;
    }

    /**
     * Initialize file monitoring by storing current file timestamps
     */
    public function initializeMonitoring(string $projectRoot): void
    {
        $this->lastFileTimes = [];
        $this->scanDirectoryForTimes($projectRoot);
    }

    /**
     * Check for file changes and update progress accordingly
     */
    public function checkForChanges(string $projectRoot): array
    {
        $changes = [];
        $currentTimes = [];
        $this->scanDirectoryForTimes($projectRoot, $currentTimes);

        // Detect modified files
        foreach ($currentTimes as $file => $time) {
            if (!isset($this->lastFileTimes[$file]) || $this->lastFileTimes[$file] < $time) {
                $changes['modified'][] = $file;
                $this->processFileChange($file, 'modified');
            }
        }

        // Detect new files
        $newFiles = array_diff(array_keys($currentTimes), array_keys($this->lastFileTimes));
        foreach ($newFiles as $file) {
            $changes['added'][] = $file;
            $this->processFileChange($file, 'added');
        }

        // Detect deleted files
        $deletedFiles = array_diff(array_keys($this->lastFileTimes), array_keys($currentTimes));
        foreach ($deletedFiles as $file) {
            $changes['deleted'][] = $file;
            $this->processFileChange($file, 'deleted');
        }

        // Update stored times
        $this->lastFileTimes = $currentTimes;

        return $changes;
    }

    /**
     * Scan for completed functions based on code analysis
     */
    public function scanForCompletedFunctions(string $projectRoot): array
    {
        $completedFunctions = [];
        
        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');
            
            foreach ($inventory['functions'] ?? [] as $function) {
                $functionId = $function['id'] ?? 0;
                $currentStatus = $this->getFunctionStatus($progress['by_function'] ?? [], (string) $functionId);
                
                if ($currentStatus === 'pending' && $this->isFunctionCompleted($function)) {
                    $completedFunctions[] = $functionId;
                    $this->updateFunctionStatus($functionId, 'completed', [
                        'auto_detected' => true,
                        'detection_time' => date('c'),
                        'session_id' => $this->currentSessionId
                    ]);
                    
                    // Send notification for function completion
                    $this->notifier->notifyFunctionCompleted(
                        $function['name'] ?? 'Unknown',
                        $function['file'] ?? 'Unknown',
                        [
                            'session_id' => $this->currentSessionId,
                            'lines_of_code' => $function['lines'] ?? 0,
                            'complexity' => $function['complexity'] ?? 'Unknown'
                        ]
                    );
                    
                    // Track activity
                    $this->trackActivity(
                        'function_completed',
                        "Function {$function['name']} completed",
                        ['function_id' => $functionId]
                    );
                }
            }
            
            // Update global progress statistics
            if (!empty($completedFunctions)) {
                $this->updateGlobalProgress();
            }
            
        } catch (\Exception $e) {
            error_log('Error scanning for completed functions: ' . $e->getMessage());
        }
        
        return $completedFunctions;
    }

    /**
     * Track an activity in the current session
     */
    public function trackActivity(string $type, string $description, array $metadata = []): void
    {
        try {
            $sessions = $this->database->read('sessions');
            $currentSession = $this->normalizeSessionRecord($sessions['current_session'] ?? []);
            
            if (empty($currentSession)) {
                return;
            }
            
            $activity = [
                'timestamp' => date('c'),
                'type' => $type,
                'description' => $description,
                'session_id' => $this->resolveSessionId($currentSession),
                'metadata' => $metadata
            ];
            
            $currentSession['activities'] = $this->normalizeActivityList($currentSession['activities'] ?? []);
            
            $currentSession['activities'][] = $activity;
            $currentSession['last_activity'] = date('c');
            
            $this->database->update('sessions', 'current_session', (object) $currentSession);
            
        } catch (\Exception $e) {
            error_log('Error tracking activity: ' . $e->getMessage());
        }
    }

    /**
     * Check if a function appears to be completed based on code quality
     */
    private function isFunctionCompleted(array $function): bool
    {
        $filePath = $function['file'] ?? '';
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            $content = file_get_contents($filePath);
            $functionName = $function['name'] ?? '';
            
            // Look for the function in the file content
            if (empty($functionName) || strpos($content, $functionName) === false) {
                return false;
            }
            
            // Simple heuristics for "completion":
            // 1. Function has docblock
            // 2. Function has type hints
            // 3. Function has return type
            // 4. No obvious TODOs or FIXMEs
            
            $functionPattern = '/function\s+' . preg_quote($functionName) . '\s*\(/';
            if (preg_match($functionPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                $functionStart = $matches[0][1];
                
                // Look for docblock before function (within 500 characters)
                $beforeFunction = substr($content, max(0, $functionStart - 500), 500);
                $hasDocblock = strpos($beforeFunction, '/**') !== false;
                
                // Look for type hints and return type in function signature
                $afterFunction = substr($content, $functionStart, 200);
                $hasTypeHints = strpos($afterFunction, ': ') !== false || strpos($afterFunction, 'string ') !== false || strpos($afterFunction, 'int ') !== false || strpos($afterFunction, 'array ') !== false;
                
                // Check for TODO/FIXME in function area
                $functionArea = substr($content, $functionStart, 1000);
                $hasTodos = strpos(strtolower($functionArea), 'todo') !== false || strpos(strtolower($functionArea), 'fixme') !== false;
                
                // Consider completed if has docblock AND type hints AND no todos
                return $hasDocblock && $hasTypeHints && !$hasTodos;
            }
            
        } catch (\Exception $e) {
            error_log('Error checking function completion: ' . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Process a file change and trigger relevant actions
     */
    private function processFileChange(string $file, string $changeType): void
    {
        // Only process PHP files
        if (!str_ends_with($file, '.php')) {
            return;
        }
        
        $this->trackActivity(
            'file_' . $changeType,
            "File {$changeType}: " . basename($file),
            ['file_path' => $file, 'change_type' => $changeType]
        );
    }

    /**
     * Update function status in the database
     */
    private function updateFunctionStatus(int $functionId, string $status, array $metadata = []): void
    {
        try {
            $progress = $this->database->read('progress');
            
            $byFunction = $this->normalizeKeyedMap($progress['by_function'] ?? []);
            $byFunction[(string) $functionId] = [
                'status' => $status,
                'updated_at' => date('c'),
                'session_id' => $this->currentSessionId,
                'metadata' => $metadata
            ];
            $progress['by_function'] = (object) $byFunction;
            
            $this->database->write('progress', $progress);
            
        } catch (\Exception $e) {
            error_log('Error updating function status: ' . $e->getMessage());
        }
    }

    /**
     * Update global progress statistics
     */
    private function updateGlobalProgress(): void
    {
        try {
            $progress = $this->database->read('progress');
            $inventory = $this->database->read('inventory');
            
            $totalFunctions = $this->safeCount($inventory['functions'] ?? []);
            $completedCount = 0;
            
            foreach ($progress['by_function'] ?? [] as $functionProgress) {
                if (is_object($functionProgress) && isset($functionProgress->status) && $functionProgress->status === 'completed') {
                    $completedCount++;
                } elseif (is_array($functionProgress) && ($functionProgress['status'] ?? '') === 'completed') {
                    $completedCount++;
                }
            }
            
            $completionPercentage = $totalFunctions > 0 ? ($completedCount / $totalFunctions) * 100 : 0;
            
            $progress['global_stats'] = [
                'total_functions' => $totalFunctions,
                'completed_functions' => $completedCount,
                'completion_percentage' => $completionPercentage,
                'last_updated' => date('c')
            ];
            
            $this->database->write('progress', $progress);
            
            // Check for milestone notifications
            $this->checkMilestones($completionPercentage);
            
        } catch (\Exception $e) {
            error_log('Error updating global progress: ' . $e->getMessage());
        }
    }

    /**
     * Check if we've hit any milestones and send notifications
     */
    private function checkMilestones(float $completionPercentage): void
    {
        $milestones = [25, 50, 75, 100];
        
        foreach ($milestones as $milestone) {
            if ($completionPercentage >= $milestone && !$this->hasMilestoneBeenReported($milestone)) {
                $this->notifier->notifyMilestone(
                    "{$milestone}% Completion Milestone",
                    [
                        'completion_percentage' => $completionPercentage,
                        'milestone' => $milestone
                    ]
                );
                
                $this->markMilestoneReported($milestone);
                break; // Only report one milestone at a time
            }
        }
    }

    /**
     * Check if a milestone has been reported for this project
     */
    private function hasMilestoneBeenReported(int $milestone): bool
    {
        try {
            $project = $this->database->read('project');
            $reportedMilestones = $project['reported_milestones'] ?? [];
            return in_array($milestone, $reportedMilestones);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Mark a milestone as reported
     */
    private function markMilestoneReported(int $milestone): void
    {
        try {
            $project = $this->database->read('project');
            if (!isset($project['reported_milestones'])) {
                $project['reported_milestones'] = [];
            }
            $project['reported_milestones'][] = $milestone;
            $this->database->write('project', $project);
        } catch (\Exception $e) {
            error_log('Error marking milestone as reported: ' . $e->getMessage());
        }
    }

    /**
     * Recursively scan directory for file modification times
     */
    private function scanDirectoryForTimes(string $dir, array &$times = null): void
    {
        if ($times === null) {
            $times = &$this->lastFileTimes;
        }
        
        $excludeDirs = ['vendor', 'node_modules', '.git', '.claude-project', '.cpm'];
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                $filePath = $file->getPathname();
                
                // Skip excluded directories
                $skip = false;
                foreach ($excludeDirs as $excludeDir) {
                    if (strpos($filePath, DIRECTORY_SEPARATOR . $excludeDir . DIRECTORY_SEPARATOR) !== false) {
                        $skip = true;
                        break;
                    }
                }
                
                if (!$skip && $file->isFile() && str_ends_with($filePath, '.php')) {
                    $times[$filePath] = $file->getMTime();
                }
            }
        } catch (\Exception $e) {
            error_log('Error scanning directory: ' . $e->getMessage());
        }
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
     * Normalize a session record to array access for mixed cache/disk reads.
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
            $session['activities'] = $this->normalizeActivityList($session['activities']);
        }

        return $session;
    }

    /**
     * Normalize activity lists that may come back as arrays or stdClass instances.
     */
    private function normalizeActivityList(mixed $activities): array
    {
        if (is_object($activities)) {
            $activities = (array) $activities;
        }

        return is_array($activities) ? array_values($activities) : [];
    }

    /**
     * Normalize keyed maps that may be cached as stdClass or arrays.
     */
    private function normalizeKeyedMap(mixed $value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Resolve the active session ID, preferring the tracker state then stored session data.
     */
    private function resolveSessionId(array $currentSession): string
    {
        if ($this->currentSessionId !== '') {
            return $this->currentSessionId;
        }

        $sessionId = $currentSession['session_id'] ?? '';
        return is_string($sessionId) ? $sessionId : '';
    }

    /**
     * Get the tracked status for a function from array- or object-backed progress data.
     */
    private function getFunctionStatus(mixed $byFunction, string $functionId): string
    {
        $entries = $this->normalizeKeyedMap($byFunction);
        $entry = $entries[$functionId] ?? null;

        if (is_object($entry)) {
            return is_string($entry->status ?? null) ? $entry->status : 'pending';
        }

        if (is_array($entry)) {
            return is_string($entry['status'] ?? null) ? $entry['status'] : 'pending';
        }

        return 'pending';
    }
}
