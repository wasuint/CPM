<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use ClaudeProjectManager\ConfigManager;
use ClaudeProjectManager\Installer;
use ClaudeProjectManager\Services\PermissionManager;
use PHPUnit\Framework\TestCase;

/**
 * `tools:fix-perms` must tighten .cpm/ to the installer's restrictive modes
 * (not loosen it to world-readable 0755/0644), and every path it prints in a
 * suggested shell command must be shell-quoted.
 */
final class PermissionManagerModesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('Requires POSIX permissions and a non-root user');
        }
        // A space and a quote in the path exercise shell quoting.
        $this->root = sys_get_temp_dir() . "/cpm perms it's-" . uniqid();
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('chmod -R u+rwX ' . escapeshellarg($this->root) . ' && rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function test_installer_exposes_shared_modes(): void
    {
        $this->assertSame(0770, Installer::CPM_DIR_MODE);
        $this->assertSame(0660, Installer::CPM_FILE_MODE);
    }

    public function test_fixer_tightens_to_installer_modes(): void
    {
        $cpm = $this->root . '/.cpm';
        foreach (['', '/db', '/context', '/logs', '/config'] as $sub) {
            mkdir($cpm . $sub, 0777, true);
            chmod($cpm . $sub, 0755);
        }
        file_put_contents($cpm . '/db/project.json', '{}');
        chmod($cpm . '/db/project.json', 0644);
        file_put_contents($cpm . '/context/DIGEST.md', '# x');
        chmod($cpm . '/context/DIGEST.md', 0666);

        $manager = new PermissionManager(new ConfigManager($this->root));
        $manager->fixPermissions(false);
        clearstatcache();

        foreach (['', '/db', '/context', '/logs', '/config'] as $sub) {
            $this->assertSame('770', $this->mode($cpm . $sub), "dir .cpm{$sub}");
        }
        $this->assertSame('660', $this->mode($cpm . '/db/project.json'));
        $this->assertSame('660', $this->mode($cpm . '/context/DIGEST.md'));
    }

    public function test_suggested_commands_quote_paths_and_use_installer_modes(): void
    {
        $cpm = $this->root . '/.cpm';
        mkdir($cpm, 0700);
        chmod($cpm, 0500); // not writable -> recommendation; logs dir missing -> recommendation

        $manager = new PermissionManager(new ConfigManager($this->root));
        $result = $manager->diagnosePermissions();
        chmod($cpm, 0700);

        $commands = array_merge(...array_map(
            static fn (array $r): array => $r['commands'],
            $result['recommendations']
        ));
        $this->assertNotEmpty($commands);

        $all = implode("\n", $commands);
        $this->assertStringContainsString('chmod 770 ' . escapeshellarg($cpm), $all);
        $this->assertStringContainsString('mkdir -p ' . escapeshellarg($cpm . '/logs'), $all);
        $this->assertStringNotContainsString('755', $all);
        $this->assertStringNotContainsString('775', $all);
        $this->assertStringNotContainsString('644', $all);
        // No raw (unquoted) occurrence of the path may remain.
        $this->assertStringNotContainsString(' ' . $cpm, str_replace(' ' . escapeshellarg($cpm), '', $all));
    }

    private function mode(string $path): string
    {
        return substr(sprintf('%o', fileperms($path)), -3);
    }
}
