<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use ClaudeProjectManager\ConfigManager;
use PHPUnit\Framework\TestCase;

/**
 * A hostile repository can commit .cpm/.env (for example enabling Telegram
 * with an attacker's bot token). CPM must ignore a git-tracked .cpm/.env and
 * keep honouring an untracked, local one.
 */
final class TrackedEnvFileTest extends TestCase
{
    private string $repo;
    private string $logFile;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        exec('git --version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('git is not available');
        }
        $this->repo = sys_get_temp_dir() . '/cpm-tracked-env-' . uniqid();
        mkdir($this->repo . '/.cpm', 0700, true);
        exec('git -C ' . escapeshellarg($this->repo) . ' init -q 2>&1');
        $this->logFile = $this->repo . '.log';
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->logFile);
        exec('rm -rf ' . escapeshellarg($this->repo));
    }

    public function test_tracked_cpm_env_is_not_loaded_and_warns(): void
    {
        $key = 'CPM_TEST_TRACKED_' . strtoupper(uniqid());
        file_put_contents($this->repo . '/.cpm/.env', "{$key}=attacker-token\n");
        exec('git -C ' . escapeshellarg($this->repo) . ' add -f .cpm/.env 2>&1');

        $config = new ConfigManager($this->repo);

        $this->assertArrayNotHasKey($key, $_ENV);
        $this->assertNull($config->env($key));
        $this->assertFileExists($this->logFile);
        $this->assertStringContainsString('tracked by git', (string) file_get_contents($this->logFile));
    }

    public function test_env_reached_through_symlinked_cpm_dir_is_not_loaded(): void
    {
        // Repo commits evil/.env and a symlink .cpm -> evil; git only tracks
        // evil/.env, so a check on ".cpm/.env" alone would be bypassed.
        $key = 'CPM_TEST_SYMLINK_' . strtoupper(uniqid());
        rmdir($this->repo . '/.cpm');
        mkdir($this->repo . '/evil');
        file_put_contents($this->repo . '/evil/.env', "{$key}=attacker-token\n");
        symlink('evil', $this->repo . '/.cpm');
        exec('git -C ' . escapeshellarg($this->repo) . ' add -f evil/.env .cpm 2>&1');

        $config = new ConfigManager($this->repo);

        $this->assertArrayNotHasKey($key, $_ENV);
        $this->assertNull($config->env($key));
    }

    public function test_env_outside_project_via_symlink_is_not_loaded(): void
    {
        $key = 'CPM_TEST_OUTSIDE_' . strtoupper(uniqid());
        $outside = $this->repo . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/.env', "{$key}=attacker-token\n");
        rmdir($this->repo . '/.cpm');
        symlink($outside, $this->repo . '/.cpm');

        try {
            $config = new ConfigManager($this->repo);
            $this->assertArrayNotHasKey($key, $_ENV);
            $this->assertNull($config->env($key));
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function test_tracked_legacy_claude_project_env_is_not_loaded(): void
    {
        $key = 'CPM_TEST_LEGACY_' . strtoupper(uniqid());
        rmdir($this->repo . '/.cpm');
        mkdir($this->repo . '/.claude-project');
        file_put_contents($this->repo . '/.claude-project/.env', "{$key}=attacker-token\n");
        exec('git -C ' . escapeshellarg($this->repo) . ' add -f .claude-project/.env 2>&1');

        $config = new ConfigManager($this->repo);

        $this->assertArrayNotHasKey($key, $_ENV);
        $this->assertNull($config->env($key));
    }

    public function test_untracked_cpm_env_is_still_loaded(): void
    {
        $key = 'CPM_TEST_UNTRACKED_' . strtoupper(uniqid());
        file_put_contents($this->repo . '/.cpm/.env', "{$key}=local-value\n");

        $config = new ConfigManager($this->repo);

        $this->assertSame('local-value', $config->env($key));
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
