<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use ClaudeProjectManager\Progress\MetricsCalculator;
use ClaudeProjectManager\Notifications\TelegramNotifier;
use ClaudeProjectManager\Services\RefactoringTracker;

/**
 * Main progress tracking operations
 * Handles function and file-level progress monitoring
 */
class ProgressTracker
{
    private DatabaseManager $database;
    private TelegramNotifier $notifier;
    private MetricsCalculator $metrics;
    private RefactoringTracker $refactoringTracker;
    private array $progressData;

    /**
     * Initializes progress tracker with required dependencies
     *
     * @param DatabaseManager $database Database manager for progress persistence
     * @param TelegramNotifier $notifier Telegram notifier for progress updates
     */
    public function __construct(DatabaseManager $database, TelegramNotifier $notifier)
    {
        $this->database = $database;
        $this->notifier = $notifier;
        $this->metrics = new MetricsCalculator();
        $this->refactoringTracker = new RefactoringTracker($database);
        $this->progressData = [];

        $this->loadProgressData();
    }

    /**
     * Updates progress for a specific function with detailed tracking
     *
     * @param string $functionId Unique function identifier
     * @param string $status New status (pending, in_progress, completed, verified, has_issues)
     * @param array $metadata Additional metadata about the progress update
     * @return void
     */
    public function updateFunctionProgress(string|int $functionId, string $status, array $metadata = []): void
    {
        // Normalize function ID to string (JSON decode may convert numeric strings to ints)
        $functionId = (string)$functionId;

        $validStatuses = ['pending', 'in_progress', 'completed', 'verified', 'has_issues'];
        if (!in_array($status, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $updateData = [
            'status' => $status,
            'updated_at' => date('c'),
            'session_id' => uniqid('session_', true),
            'metadata' => $metadata
        ];

        $this->database->update('progress', "by_function.{$functionId}", $updateData);

        $inventory = $this->database->read('inventory');
        // Find function by ID in the functions array
        $functionInfo = null;
        foreach ($inventory['functions'] ?? [] as $function) {
            if (isset($function['id']) && (string)$function['id'] === $functionId) {
                $functionInfo = $function;
                break;
            }
        }
        
        if ($functionInfo && isset($functionInfo['file_path'])) {
            $this->updateFileProgress($functionInfo['file_path']);
        }

        $this->checkMilestones();
    }

    /**
     * Updates progress statistics for an entire file
     *
     * @param string $filePath Relative path to the file
     * @return void
     */
    public function updateFileProgress(string $filePath): void
    {
        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');

            $fileFunctions = array_filter(
                $inventory['functions'] ?? [],
                fn($func) => $func['file_path'] === $filePath
            );

            $totalFunctions = count($fileFunctions);
            if ($totalFunctions === 0) {
                return;
            }

            $completedFunctions = 0;
            foreach ($fileFunctions as $function) {
                $functionId = $function['id'] ?? null;
                if ($functionId) {
                    $functionProgress = $this->getFunctionProgress($progress['by_function'] ?? [], $functionId);
                    if (in_array($functionProgress['status'], ['completed', 'verified'])) {
                        $completedFunctions++;
                    }
                }
            }

            $completionPercentage = ($completedFunctions / $totalFunctions) * 100;

            $fileStatus = 'pending';
            if ($completionPercentage > 0) {
                $fileStatus = 'in_progress';
            }
            if ($completionPercentage >= 100) {
                $fileStatus = 'completed';
            }

            $fileProgressData = [
                'total_functions' => $totalFunctions,
                'completed_functions' => $completedFunctions,
                'completion_percentage' => $completionPercentage,
                'status' => $fileStatus,
                'updated_at' => date('c')
            ];

            $this->database->update('progress', "by_file.{$filePath}", $fileProgressData);

        } catch (\Exception $e) {
            error_log("Failed to update file progress for {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Gets comprehensive current progress statistics
     *
     * @return array Complete progress statistics and metrics
     */
    public function getProgressStats(): array
    {
        try {
            $inventory = $this->database->read('inventory');
            $progress = $this->database->read('progress');

            $totalFunctions = $this->safeCount($inventory['functions'] ?? []);
            $functionsByStatus = ['pending' => 0, 'in_progress' => 0, 'completed' => 0, 'verified' => 0, 'has_issues' => 0];

            foreach ($inventory['functions'] ?? [] as $function) {
                $functionId = $function['id'] ?? null;
                if ($functionId) {
                    $functionProgress = $this->getFunctionProgress($progress['by_function'] ?? [], $functionId);
                    $status = $functionProgress['status'];
                    if (isset($functionsByStatus[$status])) {
                        $functionsByStatus[$status]++;
                    }
                }
            }

            $completedCount = $functionsByStatus['completed'] + $functionsByStatus['verified'];
            $completionPercentage = $totalFunctions > 0 ? ($completedCount / $totalFunctions) * 100 : 0;

            return [
                'total_functions' => $totalFunctions,
                'completed_functions' => $completedCount,
                'completion_percentage' => round($completionPercentage, 2),
                'functions_by_status' => $functionsByStatus,
                'files_completed' => $this->getCompletedFilesCount(),
                'last_updated' => date('c'),
                'estimated_remaining_hours' => $this->estimateRemainingTime(),
                'productivity_metrics' => $this->getProductivityMetrics()
            ];

        } catch (\Exception $e) {
            error_log("Failed to get progress stats: " . $e->getMessage());
            return ['error' => 'Failed to load progress statistics'];
        }
    }

    private function loadProgressData(): void
    {
        try {
            $this->progressData = $this->database->read('progress');
        } catch (\Exception $e) {
            $this->progressData = [
                'by_file' => [],
                'by_function' => [],
                'global_stats' => []
            ];
        }
    }

    private function checkMilestones(): void
    {
        // Dummy implementation
    }

    /**
     * Handle function movement detected by refactoring tracker
     * Preserves progress when functions move between files
     * @param array $movement Movement data from RefactoringTracker
     */
    public function handleFunctionMovement(array $movement): void
    {
        $progress = $this->database->read('progress');
        $updated = false;
        
        // Find progress for the moved function
        if (isset($progress['by_function'])) {
            $byFunction = is_object($progress['by_function']) ? 
                (array) $progress['by_function'] : 
                $progress['by_function'];
                
            foreach ($byFunction as $functionId => $functionProgress) {
                $functionProgress = is_object($functionProgress) ? 
                    (array) $functionProgress : 
                    $functionProgress;
                    
                if (($functionProgress['file'] ?? '') === $movement['moved_from'] && 
                    ($functionProgress['function_name'] ?? '') === $movement['function_name']) {
                    
                    // Update file location but preserve all progress
                    $functionProgress['file'] = $movement['moved_to'];
                    $functionProgress['moved_from'] = $movement['moved_from'];
                    $functionProgress['movement_detected_at'] = $movement['detected_at'];
                    $functionProgress['movement_confidence'] = $movement['confidence'];
                    
                    $byFunction[$functionId] = $functionProgress;
                    $updated = true;
                    
                    // Log the movement
                    $this->logMovementHandled($movement, $functionProgress);
                    break;
                }
            }
            
            if ($updated) {
                $progress['by_function'] = $byFunction;
                $progress['last_updated'] = date('c');
                $this->database->write('progress', $progress);
                
                // Notify about successful progress preservation
                $this->notifyMovementHandled($movement);
            }
        }
    }

    /**
     * Detect and handle refactoring operations
     * Should be called after project analysis to catch movements
     * @param array $previousInventory Previous inventory snapshot
     * @param array $currentInventory Current inventory snapshot
     */
    public function detectAndHandleRefactoring(array $previousInventory, array $currentInventory): void
    {
        // Detect function movements
        $movements = $this->refactoringTracker->detectMovedFunctions($previousInventory, $currentInventory);
        foreach ($movements as $movement) {
            $this->handleFunctionMovement($movement);
        }
        
        // Detect file splits
        $splits = $this->refactoringTracker->detectFileSplits($previousInventory, $currentInventory);
        foreach ($splits as $split) {
            $this->handleFileSplit($split);
        }
        
        // Detect file merges  
        $merges = $this->refactoringTracker->detectFileMerges($previousInventory, $currentInventory);
        foreach ($merges as $merge) {
            $this->handleFileMerge($merge);
        }
        
        // Record all operations
        if (!empty($movements) || !empty($splits) || !empty($merges)) {
            $this->refactoringTracker->recordRefactoringOperations($movements, $splits, $merges);
        }
    }

    /**
     * Handle file split operation
     * Updates progress for all functions that moved to new files
     * @param array $split Split operation data
     */
    private function handleFileSplit(array $split): void
    {
        foreach ($split['functions_moved'] as $movedFunction) {
            $movement = [
                'function_name' => $movedFunction['name'],
                'moved_from' => $split['original_file'],
                'moved_to' => $movedFunction['moved_to'],
                'detected_at' => $split['detected_at'],
                'confidence' => $split['confidence']
            ];
            
            $this->handleFunctionMovement($movement);
        }
        
        $this->logFileSplit($split);
    }

    /**
     * Handle file merge operation
     * Updates progress for all functions consolidated into merged file
     * @param array $merge Merge operation data
     */
    private function handleFileMerge(array $merge): void
    {
        $progress = $this->database->read('progress');
        $updated = false;
        
        if (isset($progress['by_function'])) {
            $byFunction = is_object($progress['by_function']) ? 
                (array) $progress['by_function'] : 
                $progress['by_function'];
                
            // Update progress for functions from merged files
            foreach ($byFunction as $functionId => $functionProgress) {
                $functionProgress = is_object($functionProgress) ? 
                    (array) $functionProgress : 
                    $functionProgress;
                    
                if (in_array($functionProgress['file'] ?? '', $merge['merged_files'])) {
                    $functionProgress['file'] = $merge['merged_into'];
                    $functionProgress['merge_detected_at'] = $merge['detected_at'];
                    $functionProgress['original_file'] = $functionProgress['file'];
                    
                    $byFunction[$functionId] = $functionProgress;
                    $updated = true;
                }
            }
            
            if ($updated) {
                $progress['by_function'] = $byFunction;
                $progress['last_updated'] = date('c');
                $this->database->write('progress', $progress);
            }
        }
        
        $this->logFileMerge($merge);
    }

    /**
     * Log function movement handling
     */
    private function logMovementHandled(array $movement, array $functionProgress): void
    {
        $message = sprintf(
            "Function '%s' moved from %s to %s - progress preserved (status: %s)",
            $movement['function_name'],
            $movement['moved_from'],
            $movement['moved_to'],
            $functionProgress['status'] ?? 'unknown'
        );
        
        error_log("[RefactoringTracker] $message");
    }

    /**
     * Log file split handling
     */
    private function logFileSplit(array $split): void
    {
        $message = sprintf(
            "File split detected: %s -> [%s] - %d functions moved",
            $split['original_file'],
            implode(', ', $split['split_into']),
            count($split['functions_moved'])
        );
        
        error_log("[RefactoringTracker] $message");
    }

    /**
     * Log file merge handling
     */
    private function logFileMerge(array $merge): void
    {
        $message = sprintf(
            "File merge detected: [%s] -> %s - %d functions consolidated",
            implode(', ', $merge['merged_files']),
            $merge['merged_into'],
            $merge['functions_consolidated']
        );
        
        error_log("[RefactoringTracker] $message");
    }

    /**
     * Notify about movement handling via Telegram
     */
    private function notifyMovementHandled(array $movement): void
    {
        if ($movement['confidence'] >= 0.8) {
            $message = "🔄 Function Movement Detected\n" .
                      "Function: {$movement['function_name']}\n" .
                      "From: {$movement['moved_from']}\n" .
                      "To: {$movement['moved_to']}\n" .
                      "Progress preserved ✅";
                      
            $this->notifier->sendMessage($message);
        }
    }

    private function getCompletedFilesCount(): int
    {
        return 0; // Dummy
    }

    private function estimateRemainingTime(): float
    {
        return 0.0; // Dummy
    }

    private function getProductivityMetrics(): array
    {
        return []; // Dummy
    }

    /**
     * Safely get function progress from object or array
     */
    private function getFunctionProgress($byFunction, $functionId): array
    {
        // Handle object (stdClass) access
        if (is_object($byFunction)) {
            return isset($byFunction->$functionId) ? (array) $byFunction->$functionId : ['status' => 'pending'];
        }
        
        // Handle array access
        if (is_array($byFunction)) {
            return $byFunction[$functionId] ?? ['status' => 'pending'];
        }
        
        return ['status' => 'pending'];
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
}
