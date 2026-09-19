<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

use ClaudeProjectManager\Tasks\TypeCheckingTask;
use ClaudeProjectManager\Tasks\DocumentationTask;
use ClaudeProjectManager\Tasks\CodeQualityTask;

/**
 * Main task coordination and management
 * Orchestrates different types of analysis and improvement tasks
 */
class TaskManager
{
    private DatabaseManager $database;
    private ProgressTracker $progress;
    private TypeCheckingTask $typeChecker;
    private DocumentationTask $documentationTask;
    private CodeQualityTask $qualityTask;
    private array $activeTasks = [];

    /**
     * Initializes task manager with required dependencies
     *
     * @param DatabaseManager $database Database manager for storing task data
     * @param ProgressTracker $progress Progress tracker for monitoring execution
     */
    public function __construct(DatabaseManager $database, ProgressTracker $progress)
    {
        $this->database = $database;
        $this->progress = $progress;
        $this->typeChecker = new TypeCheckingTask($database);
        $this->documentationTask = new DocumentationTask($database);
        $this->qualityTask = new CodeQualityTask($database);
        
        $this->loadActiveTasks();
    }

    /**
     * Creates a new task from command description
     * Parses requirements and generates actionable task definition
     *
     * @param string $taskDescription Human-readable task description
     * @param array $scope Optional scope limitation (files, functions, etc.)
     * @param array $rules Optional custom rules for this task
     * @return string Unique task ID for tracking
     */
    public function createTask(string $taskDescription, array $scope = [], array $rules = []): string
    {
        $taskId = 'task_' . uniqid() . '_' . time();
        $taskType = $this->determineTaskType($taskDescription);
        
        $taskDefinition = [
            'id' => $taskId,
            'description' => $taskDescription,
            'type' => $taskType,
            'scope' => $scope,
            'rules' => $rules,
            'created_at' => date('c'),
            'status' => 'pending',
            'progress' => 0,
            'estimated_effort' => $this->estimateTask($scope, $rules)
        ];

        $this->database->update('tasks', "definitions.{$taskId}", $taskDefinition);
        $this->activeTasks[$taskId] = $taskDefinition;
        
        error_log("Task created: {$taskId} - {$taskDescription}");
        
        return $taskId;
    }

    /**
     * Gets current status of a task
     * Provides real-time progress and status information
     *
     * @param string $taskId Task identifier
     * @return array Current task status with progress details
     */
    public function getTaskStatus(string $taskId): array
    {
        try {
            $tasks = $this->database->read('tasks');
            $task = $tasks['definitions'][$taskId] ?? null;
            
            if (!$task) {
                return ['error' => 'Task not found'];
            }

            return [
                'id' => $taskId,
                'description' => $task['description'],
                'status' => $task['status'],
                'progress' => $task['progress'] ?? 0,
                'type' => $task['type'],
                'created_at' => $task['created_at'],
                'estimated_effort' => $task['estimated_effort'] ?? []
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to load task status: ' . $e->getMessage()];
        }
    }

    /**
     * Lists all active tasks with their current status
     *
     * @return array Array of active tasks with status information
     */
    public function getActiveTasks(): array
    {
        try {
            $tasks = $this->database->read('tasks');
            $activeTasks = [];
            
            foreach ($tasks['definitions'] ?? [] as $taskId => $task) {
                if (in_array($task['status'], ['pending', 'in_progress', 'paused'])) {
                    $activeTasks[] = [
                        'id' => $taskId,
                        'description' => $task['description'],
                        'status' => $task['status'],
                        'progress' => $task['progress'] ?? 0,
                        'created_at' => $task['created_at']
                    ];
                }
            }
            
            return $activeTasks;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Updates task progress
     *
     * @param string $taskId Task identifier
     * @param float $progress Progress percentage (0-100)
     * @param string $status Optional status update
     * @return void
     */
    public function updateTaskProgress(string $taskId, float $progress, string $status = null): void
    {
        try {
            $this->database->update('tasks', "definitions.{$taskId}.progress", $progress);
            $this->database->update('tasks', "definitions.{$taskId}.last_updated", date('c'));
            
            if ($status) {
                $this->database->update('tasks', "definitions.{$taskId}.status", $status);
            }
            
            if (isset($this->activeTasks[$taskId])) {
                $this->activeTasks[$taskId]['progress'] = $progress;
                if ($status) {
                    $this->activeTasks[$taskId]['status'] = $status;
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to update task progress: " . $e->getMessage());
        }
    }

    /**
     * Completes a task
     *
     * @param string $taskId Task identifier
     * @return array Task completion summary
     */
    public function completeTask(string $taskId): array
    {
        try {
            $this->database->update('tasks', "definitions.{$taskId}.status", 'completed');
            $this->database->update('tasks', "definitions.{$taskId}.completed_at", date('c'));
            $this->database->update('tasks', "definitions.{$taskId}.progress", 100);
            
            unset($this->activeTasks[$taskId]);
            
            return [
                'task_id' => $taskId,
                'status' => 'completed',
                'completed_at' => date('c')
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to complete task: ' . $e->getMessage()];
        }
    }

    /**
     * Load active tasks from database
     */
    private function loadActiveTasks(): void
    {
        try {
            $tasks = $this->database->read('tasks');
            foreach ($tasks['definitions'] ?? [] as $taskId => $task) {
                if (in_array($task['status'], ['pending', 'in_progress', 'paused'])) {
                    $this->activeTasks[$taskId] = $task;
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to load active tasks: " . $e->getMessage());
        }
    }

    /**
     * Estimates task complexity and required time
     * Analyzes scope and rules to provide effort estimates
     *
     * @param array $scope Task scope (files, functions, etc.)
     * @param array $rules Task rules and criteria
     * @return array Complexity and time estimates
     */
    private function estimateTask(array $scope, array $rules): array
    {
        $fileCount = $this->safeCount($scope['files'] ?? []);
        $functionCount = $this->safeCount($scope['functions'] ?? []);
        $ruleComplexity = count($rules);
        
        $baseTime = ($fileCount * 5) + ($functionCount * 2);
        $complexityMultiplier = 1 + ($ruleComplexity * 0.2);
        
        return [
            'estimated_minutes' => round($baseTime * $complexityMultiplier),
            'complexity_score' => $ruleComplexity,
            'file_count' => $fileCount,
            'function_count' => $functionCount
        ];
    }

    /**
     * Determines task type from description
     * Parses description to identify what kind of task to create
     *
     * @param string $description Task description
     * @return string Task type identifier
     */
    private function determineTaskType(string $description): string
    {
        $description = strtolower($description);
        
        if (strpos($description, 'type') !== false || strpos($description, 'typing') !== false) {
            return 'type_checking';
        }
        
        if (strpos($description, 'document') !== false || strpos($description, 'comment') !== false) {
            return 'documentation';
        }
        
        if (strpos($description, 'quality') !== false || strpos($description, 'standard') !== false) {
            return 'code_quality';
        }
        
        return 'general_analysis';
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