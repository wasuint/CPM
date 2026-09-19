<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tests\Unit\Database;

use ClaudeProjectManager\Database\FileOperations;
use PHPUnit\Framework\TestCase;

class FileOperationsTest extends TestCase
{
    private string $tempDir;
    private FileOperations $files;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpm-file-ops-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
        $this->files = new FileOperations();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testWriteThenReadReturnsTheExactContent(): void
    {
        $path = $this->tempDir . '/data.json';
        $content = json_encode(['answer' => 42, 'unicode' => 'ä€'], JSON_PRETTY_PRINT);

        $this->files->writeFile($path, $content);

        $this->assertFileExists($path);
        $this->assertSame($content, $this->files->readFile($path));
    }

    public function testWriteCreatesMissingParentDirectories(): void
    {
        $path = $this->tempDir . '/nested/deeper/data.json';

        $this->files->writeFile($path, '{}');

        $this->assertSame('{}', $this->files->readFile($path));
    }

    public function testOverwritingAnExistingFileKeepsATimestampedBackup(): void
    {
        $path = $this->tempDir . '/data.json';
        $this->files->writeFile($path, 'first');
        $this->files->writeFile($path, 'second');

        $this->assertSame('second', $this->files->readFile($path));

        $backups = glob($this->tempDir . '/.backups/data.json.backup.*');
        $this->assertNotEmpty($backups, 'An overwrite should leave a backup behind');
        $this->assertSame('first', file_get_contents($backups[0]));
    }

    public function testWriteLeavesNoTemporaryFilesBehind(): void
    {
        $path = $this->tempDir . '/data.json';
        $this->files->writeFile($path, 'payload');

        $leftovers = array_filter(
            scandir($this->tempDir) ?: [],
            static fn (string $entry): bool => str_contains($entry, '.tmp')
        );

        $this->assertSame([], array_values($leftovers));
    }

    public function testReadingAMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->files->readFile($this->tempDir . '/does-not-exist.json');
    }

    public function testBackingUpAMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->files->createBackup($this->tempDir . '/does-not-exist.json');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
