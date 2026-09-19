<?php

declare(strict_types=1);

namespace Tests\Unit\Session;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Notifications\TelegramNotifier;
use ClaudeProjectManager\ProjectAnalyzer;
use ClaudeProjectManager\Session\ActivityTracker;
use PHPUnit\Framework\TestCase;

class ActivityTrackerTest extends TestCase
{
    /** @test */
    public function it_tracks_activity_when_current_session_is_cached_as_object(): void
    {
        $database = $this->createMock(DatabaseManager::class);
        $analyzer = $this->createMock(ProjectAnalyzer::class);
        $notifier = $this->createMock(TelegramNotifier::class);

        $database->expects($this->once())
            ->method('read')
            ->with('sessions')
            ->willReturn([
                'current_session' => (object) [
                    'session_id' => 'session_123',
                    'activities' => [
                        ['timestamp' => '2026-03-21T00:00:00+00:00', 'type' => 'existing']
                    ]
                ]
            ]);

        $database->expects($this->once())
            ->method('update')
            ->with(
                'sessions',
                'current_session',
                $this->callback(function ($session): bool {
                    if (!$session instanceof \stdClass || !is_array($session->activities ?? null)) {
                        return false;
                    }

                    $lastActivity = end($session->activities);

                    return $session->session_id === 'session_123'
                        && count($session->activities) === 2
                        && is_array($lastActivity)
                        && $lastActivity['description'] === 'File modified: demo.php'
                        && $lastActivity['session_id'] === 'session_123';
                })
            );

        $tracker = new ActivityTracker($database, $analyzer, $notifier);
        $tracker->trackActivity('file_modified', 'File modified: demo.php', ['file_path' => '/tmp/demo.php']);
    }
}

