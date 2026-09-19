<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Session;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\SessionContext;

/**
 * Builds comprehensive session contexts for Claude continuity
 */
class ContextBuilder
{
    private DatabaseManager $database;

    /**
     * Initializes context builder
     *
     * @param DatabaseManager $database Database manager for context data
     */
    public function __construct(DatabaseManager $database)
    {
        $this->database = $database;
    }

    /**
     * Builds comprehensive context for new session
     *
     * @param string $sessionId Session identifier
     * @param string $command Initial command or instruction
     * @return SessionContext Complete session context
     */
    public function buildNewSessionContext(string $sessionId, string $command = ''): SessionContext
    {
        $projectContext = $this->buildProjectContext();
        $progressSnapshot = $this->buildProgressSnapshot();
        $recentActivity = $this->buildRecentActivity();
        $nextRecommendations = $this->buildNextRecommendations($command);
        $resumePoint = $this->buildResumePoint();

        return new SessionContext(
            $sessionId,
            $projectContext,
            $progressSnapshot,
            $recentActivity,
            $nextRecommendations,
            $resumePoint
        );
    }

    /**
     * Builds context for resuming previous session
     *
     * @param string $sessionId Session identifier
     * @param array $lastSessionData Previous session data
     * @return SessionContext Resume context
     */
    public function buildResumeContext(string $sessionId, array $lastSessionData): SessionContext
    {
        $projectContext = $this->buildProjectContext();
        $progressSnapshot = $this->buildProgressSnapshot();
        $recentActivity = $this->buildRecentActivity();
        $nextRecommendations = $lastSessionData['next_recommendations'] ?? [];
        $resumePoint = $this->buildResumePointFromLastSession($lastSessionData);

        return new SessionContext(
            $sessionId,
            $projectContext,
            $progressSnapshot,
            $recentActivity,
            $nextRecommendations,
            $resumePoint
        );
    }

    /**
     * Builds complete project context information
     *
     * @return array Project context
     */
    private function buildProjectContext(): array
    {
        try {
            $project = $this->database->read('project');
            $inventory = $this->database->read('inventory');

            return [
                'project_name' => $project['name'] ?? 'Unknown Project',
                'project_type' => $project['type'] ?? 'mixed',
                'total_files' => $this->safeCount($inventory['files'] ?? []),
                'total_functions' => $this->safeCount($inventory['functions'] ?? []),
                'total_classes' => $this->safeCount($inventory['classes'] ?? []),
                'last_analysis' => $project['last_analysis'] ?? null,
                'project_root' => $project['root_path'] ?? ''
            ];
        } catch (\Exception $e) {
            return [
                'project_name' => 'Unknown Project',
                'error' => 'Failed to load project context: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Builds current progress snapshot
     *
     * @return array Progress snapshot
     */
    private function buildProgressSnapshot(): array
    {
        try {
            $progress = $this->database->read('progress');
            $inventory = $this->database->read('inventory');

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
                'last_updated' => date('c')
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to load progress snapshot: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Builds recent activity information
     *
     * @return array Recent activity
     */
    private function buildRecentActivity(): array
    {
        try {
            $sessions = $this->database->read('sessions');
            $recentActivity = [];

            // Get activities from recent sessions
            $sessionHistory = array_slice($sessions['session_history'] ?? [], -5);
            foreach ($sessionHistory as $session) {
                if (isset($session['activities'])) {
                    $recentActivity = array_merge($recentActivity, $session['activities']);
                }
            }

            // Sort by timestamp and return most recent
            usort($recentActivity, fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

            return array_slice($recentActivity, 0, 10);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Builds next recommendations based on current state
     *
     * @param string $command Optional command context
     * @return array Next recommendations
     */
    private function buildNextRecommendations(string $command = ''): array
    {
        $recommendations = [];

        try {
            $progress = $this->database->read('progress');
            $inventory = $this->database->read('inventory');

            // Find next pending functions
            $pendingFunctions = [];
            foreach ($inventory['functions'] ?? [] as $function) {
                $functionId = $function['id'] ?? null;
                if ($functionId) {
                    $functionProgress = $this->getFunctionProgress($progress['by_function'] ?? [], $functionId);
                    if ($functionProgress['status'] === 'pending') {
                        $pendingFunctions[] = $function;
                    }
                }
            }

            if (!empty($pendingFunctions)) {
                $recommendations[] = "Continue with pending functions: " . count($pendingFunctions) . " remaining";
                
                // Recommend specific next functions
                $nextFunctions = array_slice($pendingFunctions, 0, 3);
                foreach ($nextFunctions as $function) {
                    $recommendations[] = "Implement function: {$function['name']} in {$function['file_path']}";
                }
            }

            if (!empty($command)) {
                $recommendations[] = "Process command: " . $command;
            }

        } catch (\Exception $e) {
            $recommendations[] = "Analyze project structure and identify next steps";
        }

        return $recommendations;
    }

    /**
     * Builds resume point description
     *
     * @return string Resume point description
     */
    private function buildResumePoint(): string
    {
        try {
            $sessions = $this->database->read('sessions');
            $lastSession = $sessions['current_session'] ?? null;

            $lastTask = $this->getSessionProperty($lastSession, 'last_task');
            if ($lastTask) {
                return "Previously working on: " . $lastTask;
            }

            return "Starting fresh session - ready to begin development work";
        } catch (\Exception $e) {
            return "Ready to begin development work";
        }
    }

    /**
     * Builds resume point from last session data
     *
     * @param array $lastSessionData Previous session data
     * @return string Resume point description
     */
    private function buildResumePointFromLastSession(array $lastSessionData): string
    {
        if (isset($lastSessionData['last_task'])) {
            return "Resuming: " . $lastSessionData['last_task'];
        }

        if (isset($lastSessionData['current_focus'])) {
            return "Continue focus on: " . $lastSessionData['current_focus'];
        }

        return "Resuming previous session";
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
     * Safely get session property from object or array
     */
    private function getSessionProperty($session, string $property)
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
}