<?php

declare(strict_types=1);

namespace Tests\Unit\Session;

use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\Session\ActivityTracker;
use ClaudeProjectManager\SessionManager;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    /** @test */
    public function it_hydrates_and_updates_the_existing_session_during_monitoring(): void
    {
        $database = $this->createMock(DatabaseManager::class);
        $activityTracker = $this->createMock(ActivityTracker::class);

        $database->expects($this->exactly(2))
            ->method('read')
            ->with('sessions')
            ->willReturn([
                'current_session' => (object) [
                    'session_id' => 'session_456',
                    'started_at' => '2026-03-21T09:00:00+00:00',
                    'status' => 'active',
                    'activities' => [
                        ['timestamp' => '2026-03-21T09:05:00+00:00', 'type' => 'existing']
                    ]
                ]
            ]);

        $activityTracker->expects($this->once())
            ->method('setCurrentSession')
            ->with('session_456');

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

                    return $session->session_id === 'session_456'
                        && $session->started_at === '2026-03-21T09:00:00+00:00'
                        && $session->status === 'active'
                        && count($session->activities) === 2
                        && is_array($lastActivity)
                        && $lastActivity['type'] === 'task_update'
                        && $lastActivity['description'] === 'Automatic monitoring detected changes';
                })
            );

        $manager = new SessionManager($database, $activityTracker);
        $manager->updateSession('Automatic monitoring detected changes', ['monitoring_check' => true]);
    }
}
