<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Notifications;

use ClaudeProjectManager\ConfigManager;

/**
 * Sends notifications via Telegram bot
 */
class TelegramNotifier
{
    private ConfigManager $config;
    private string $botToken;
    private string $chatId;
    private bool $enabled;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $telegramConfig = $config->getTelegramConfig();
        $this->enabled = $telegramConfig['enabled'];
        $this->botToken = $telegramConfig['bot_token'];
        $this->chatId = $telegramConfig['chat_id'];
    }

    /**
     * Send a message to Telegram
     */
    public function sendMessage(string $message): bool
    {
        if (!$this->enabled || empty($this->botToken) || empty($this->chatId)) {
            return false;
        }

        try {
            $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
            
            $data = [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($data),
                    'timeout' => 10
                ]
            ]);

            $result = @file_get_contents($url, false, $context);
            
            if ($result === false) {
                error_log('Telegram notification failed: Network error');
                return false;
            }

            $response = json_decode($result, true);
            
            if (!isset($response['ok']) || !$response['ok']) {
                error_log('Telegram notification failed: ' . ($response['description'] ?? 'Unknown error'));
                return false;
            }

            return true;

        } catch (\Exception $e) {
            error_log('Telegram notification exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify about project initialization completion
     */
    public function notifyProjectInitialized(string $projectName, array $stats): bool
    {
        $message = "🚀 *Project Initialized*\n\n";
        $message .= "**Project**: {$projectName}\n";
        $message .= "**Files**: " . ($stats['files_count'] ?? 0) . "\n";
        $message .= "**Functions**: " . ($stats['functions_count'] ?? 0) . "\n";
        $message .= "**Classes**: " . ($stats['classes_count'] ?? 0) . "\n";
        $message .= "**Analysis Time**: " . ($stats['analysis_time'] ?? 0) . "s\n\n";
        $message .= "Ready for development work! 🎯";

        return $this->sendMessage($message);
    }

    /**
     * Notify about session resumption
     */
    public function notifySessionResumed(string $projectName, array $sessionContext): bool
    {
        $message = "🔄 *Session Resumed*\n\n";
        $message .= "**Project**: {$projectName}\n";
        $message .= "**Session ID**: " . substr($sessionContext['session_id'], -12) . "\n";
        
        if (isset($sessionContext['progress_snapshot'])) {
            $progress = $sessionContext['progress_snapshot'];
            $message .= "**Progress**: " . round($progress['completion_percentage'] ?? 0, 1) . "%\n";
            $message .= "**Completed**: " . ($progress['completed_functions'] ?? 0) . "/" . ($progress['total_functions'] ?? 0) . "\n";
        }

        if (!empty($sessionContext['resume_point'])) {
            $message .= "\n**Resume Point**: " . $sessionContext['resume_point'] . "\n";
        }

        $message .= "\nReady to continue! ⚡";

        return $this->sendMessage($message);
    }

    /**
     * Notify about milestone achievement
     */
    public function notifyMilestone(string $milestone, array $stats): bool
    {
        $message = "🎉 *Milestone Achieved*\n\n";
        $message .= "**{$milestone}**\n\n";
        $message .= "**Progress**: " . round($stats['completion_percentage'] ?? 0, 1) . "%\n";
        $message .= "**Completed Functions**: " . ($stats['completed_functions'] ?? 0) . "\n";
        $message .= "**Total Functions**: " . ($stats['total_functions'] ?? 0) . "\n";
        
        if (isset($stats['velocity'])) {
            $message .= "**Velocity**: " . round($stats['velocity'], 1) . " functions/hour\n";
        }

        $message .= "\nKeep up the great work! 💪";

        return $this->sendMessage($message);
    }

    /**
     * Notify about function completion
     */
    public function notifyFunctionCompleted(string $functionName, string $filePath, array $metadata = []): bool
    {
        $message = "✅ *Function Completed*\n\n";
        $message .= "**Function**: `{$functionName}`\n";
        $message .= "**File**: `{$filePath}`\n";
        
        if (isset($metadata['lines_of_code'])) {
            $message .= "**Lines**: " . $metadata['lines_of_code'] . "\n";
        }
        
        if (isset($metadata['complexity'])) {
            $message .= "**Complexity**: " . $metadata['complexity'] . "\n";
        }

        if (isset($metadata['session_id'])) {
            $message .= "**Session**: " . substr($metadata['session_id'], -12) . "\n";
        }

        return $this->sendMessage($message);
    }

    /**
     * Notify about errors or issues
     */
    public function notifyError(string $context, string $errorMessage): bool
    {
        $message = "❌ *Error Occurred*\n\n";
        $message .= "**Context**: {$context}\n";
        $message .= "**Error**: " . substr($errorMessage, 0, 500) . "\n";
        $message .= "**Time**: " . date('Y-m-d H:i:s') . "\n";

        return $this->sendMessage($message);
    }

    /**
     * Notify about external changes detected
     */
    public function notifyExternalChanges(array $changes): bool
    {
        if (!$changes['has_changes']) {
            return true;
        }

        $message = "🔄 *External Changes Detected*\n\n";
        
        if (!empty($changes['modified_files'])) {
            $message .= "**Modified**: " . count($changes['modified_files']) . " files\n";
        }
        
        if (!empty($changes['added_files'])) {
            $message .= "**Added**: " . count($changes['added_files']) . " files\n";
        }
        
        if (!empty($changes['deleted_files'])) {
            $message .= "**Deleted**: " . count($changes['deleted_files']) . " files\n";
        }

        if ($changes['requires_reanalysis']) {
            $message .= "\n⚠️ *Project re-analysis recommended*";
        }

        return $this->sendMessage($message);
    }

    /**
     * Send daily summary report
     */
    public function sendDailySummary(array $dailyStats): bool
    {
        $message = "📊 *Daily Summary*\n\n";
        $message .= "**Date**: " . date('Y-m-d') . "\n";
        $message .= "**Functions Completed**: " . ($dailyStats['functions_completed'] ?? 0) . "\n";
        $message .= "**Session Duration**: " . round(($dailyStats['total_session_time'] ?? 0) / 60, 1) . " hours\n";
        $message .= "**Activities**: " . ($dailyStats['total_activities'] ?? 0) . "\n";
        
        if (isset($dailyStats['velocity'])) {
            $message .= "**Average Velocity**: " . round($dailyStats['velocity'], 1) . " functions/hour\n";
        }

        if (isset($dailyStats['completion_percentage'])) {
            $message .= "**Overall Progress**: " . round($dailyStats['completion_percentage'], 1) . "%\n";
        }

        $message .= "\nGreat progress today! 🎯";

        return $this->sendMessage($message);
    }

    /**
     * Check if notifications are enabled and configured
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->botToken) && !empty($this->chatId);
    }

    /**
     * Test notification setup
     */
    public function testNotification(): bool
    {
        $message = "🧪 *Test Notification*\n\n";
        $message .= "Claude Project Manager notifications are working correctly!\n";
        $message .= "**Time**: " . date('Y-m-d H:i:s') . "\n";
        $message .= "**Status**: ✅ Connected";

        return $this->sendMessage($message);
    }
}