<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use ClaudeProjectManager\Commands\TemplateCommand;
use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cpm template export` used to write to a predictable /tmp path built from
 * an unvalidated --language (path traversal, symlink following). The
 * language must be whitelisted and the file created exclusively.
 */
final class TemplateExportPathTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
    }

    private function tester(): CommandTester
    {
        $database = $this->createMock(DatabaseManager::class);
        $database->method('read')->willReturn([]);

        return new CommandTester(new TemplateCommand($database, $this->createMock(SessionManager::class)));
    }

    public function test_export_rejects_language_with_path_traversal(): void
    {
        $marker = 'cpmtrav' . uniqid();
        $tester = $this->tester();
        $exitCode = $tester->execute([
            'action' => 'export',
            '--language' => '../' . $marker,
            '--json' => true,
        ]);

        $created = glob(sys_get_temp_dir() . '/' . $marker . '*') ?: [];
        $this->created = array_merge($this->created, $created);
        $this->created = array_merge($this->created, glob('/' . $marker . '*') ?: []);

        $this->assertSame(1, $exitCode);
        $this->assertSame([], $created);
    }

    public function test_export_creates_unpredictable_private_file(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['action' => 'export', '--language' => 'php', '--json' => true]);
        $this->assertSame(0, $exitCode);

        $payload = json_decode($tester->getDisplay(), true);
        $path = $payload['data']['export_path'];
        $this->created[] = $path;

        $this->assertFileExists($path);
        $this->assertFalse(is_link($path));
        $this->assertDoesNotMatchRegularExpression('#cpm_templates_php_\d{8}_\d{6}\.json$#', $path);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3));
        }
        $this->assertSame('php', json_decode((string) file_get_contents($path), true)['language']);
    }
}
