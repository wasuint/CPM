<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use ClaudeProjectManager\Commands\GenerateCodeCommand;
use ClaudeProjectManager\DatabaseManager;
use ClaudeProjectManager\SessionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The generated file path is built from the `name` argument, so a name with
 * path separators or ".." must not be able to write outside the project.
 */
final class GenerateCodeNameTest extends TestCase
{
    private string $previousCwd;
    private string $parent;
    private string $project;

    protected function setUp(): void
    {
        $this->previousCwd = getcwd() ?: '.';
        $this->parent = sys_get_temp_dir() . '/cpm-gen-' . uniqid();
        $this->project = $this->parent . '/project';
        mkdir($this->project, 0700, true);
        chdir($this->project);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        exec('rm -rf ' . escapeshellarg($this->parent));
    }

    private function generate(string $name): int
    {
        $database = $this->createMock(DatabaseManager::class);
        $database->method('read')->willReturn([]);
        $tester = new CommandTester(new GenerateCodeCommand($database, $this->createMock(SessionManager::class)));

        return $tester->execute(['type' => 'function', 'name' => $name, '--json' => true]);
    }

    public function test_name_with_traversal_is_rejected_and_nothing_written_outside(): void
    {
        $exitCode = $this->generate('../../../escaped');

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($this->parent . '/escaped.php');
    }

    public function test_name_with_separator_is_rejected(): void
    {
        $this->assertSame(1, $this->generate('Sub/Dir'));
        $this->assertSame(1, $this->generate('Sub\\Dir'));
    }

    public function test_symlinked_src_directory_cannot_escape_the_project(): void
    {
        $elsewhere = $this->parent . '/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $this->project . '/src');

        $this->assertSame(1, $this->generate('Planted'));
        $this->assertFileDoesNotExist($elsewhere . '/Functions/Planted.php');
    }

    public function test_plain_name_still_generates_inside_project(): void
    {
        $this->assertSame(0, $this->generate('HandleOrder'));
        $this->assertFileExists($this->project . '/src/Functions/HandleOrder.php');
    }
}
