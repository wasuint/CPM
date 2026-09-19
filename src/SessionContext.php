<?php

declare(strict_types=1);

namespace ClaudeProjectManager;

/**
 * Represents complete session context for Claude continuity
 */
class SessionContext
{
    private string $sessionId;
    private array $projectContext;
    private array $progressSnapshot;
    private array $recentActivity;
    private array $nextRecommendations;
    private string $resumePoint;

    /**
     * Initializes session context
     *
     * @param string $sessionId Unique session identifier
     * @param array $projectContext Complete project context information
     * @param array $progressSnapshot Current progress snapshot
     * @param array $recentActivity Recent session activity
     * @param array $nextRecommendations Recommended next steps
     * @param string $resumePoint Description of where to resume
     */
    public function __construct(
        string $sessionId,
        array $projectContext,
        array $progressSnapshot,
        array $recentActivity = [],
        array $nextRecommendations = [],
        string $resumePoint = ''
    ) {
        $this->sessionId = $sessionId;
        $this->projectContext = $projectContext;
        $this->progressSnapshot = $progressSnapshot;
        $this->recentActivity = $recentActivity;
        $this->nextRecommendations = $nextRecommendations;
        $this->resumePoint = $resumePoint;
    }

    /**
     * Gets the session ID
     *
     * @return string Session ID
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Gets complete project context
     *
     * @return array Project context
     */
    public function getProjectContext(): array
    {
        return $this->projectContext;
    }

    /**
     * Gets current progress snapshot
     *
     * @return array Progress snapshot
     */
    public function getProgressSnapshot(): array
    {
        return $this->progressSnapshot;
    }

    /**
     * Gets recent activity information
     *
     * @return array Recent activity
     */
    public function getRecentActivity(): array
    {
        return $this->recentActivity;
    }

    /**
     * Gets next recommended steps
     *
     * @return array Next recommendations
     */
    public function getNextRecommendations(): array
    {
        return $this->nextRecommendations;
    }

    /**
     * Gets resume point description
     *
     * @return string Resume point
     */
    public function getResumePoint(): string
    {
        return $this->resumePoint;
    }

    /**
     * Generates Claude-friendly context summary
     *
     * @return string Formatted context for Claude
     */
    public function generateClaudeContext(): string
    {
        $context = "## Session Context for Claude\n\n";
        
        if (!empty($this->resumePoint)) {
            $context .= "**Where we left off:** " . $this->resumePoint . "\n\n";
        }

        $context .= "**Project Status:**\n";
        $context .= "- Total Functions: " . ($this->progressSnapshot['total_functions'] ?? 0) . "\n";
        $context .= "- Completed: " . ($this->progressSnapshot['completed_functions'] ?? 0) . "\n";
        $context .= "- Progress: " . round($this->progressSnapshot['completion_percentage'] ?? 0, 1) . "%\n\n";

        if (!empty($this->recentActivity)) {
            $context .= "**Recent Activity:**\n";
            foreach (array_slice($this->recentActivity, -3) as $activity) {
                $context .= "- " . $activity['description'] . " (" . $activity['timestamp'] . ")\n";
            }
            $context .= "\n";
        }

        if (!empty($this->nextRecommendations)) {
            $context .= "**Recommended Next Steps:**\n";
            foreach (array_slice($this->nextRecommendations, 0, 5) as $recommendation) {
                $context .= "- " . $recommendation . "\n";
            }
        }

        return $context;
    }

    /**
     * Converts to array for database storage
     *
     * @return array Array representation
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'project_context' => $this->projectContext,
            'progress_snapshot' => $this->progressSnapshot,
            'recent_activity' => $this->recentActivity,
            'next_recommendations' => $this->nextRecommendations,
            'resume_point' => $this->resumePoint,
            'created_at' => date('c')
        ];
    }
}