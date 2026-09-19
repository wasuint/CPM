<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tests\Unit\Database;

use ClaudeProjectManager\Database\SchemaPathResolver;
use PHPUnit\Framework\TestCase;

class SchemaPathResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpm-schema-path-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/.cpm/db', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testPrefersSchemasPublishedNextToTheDatabaseDirectory(): void
    {
        $published = $this->tempDir . '/.cpm/schemas';
        mkdir($published, 0775, true);

        $this->assertSame(
            $published,
            SchemaPathResolver::fromDatabasePath($this->tempDir . '/.cpm/db')
        );
    }

    public function testTrailingSlashOnTheDatabasePathIsIgnored(): void
    {
        $published = $this->tempDir . '/.cpm/schemas';
        mkdir($published, 0775, true);

        $this->assertSame(
            $published,
            SchemaPathResolver::fromDatabasePath($this->tempDir . '/.cpm/db/')
        );
    }

    public function testFallsBackToThePackageSchemasWhenNothingIsPublished(): void
    {
        $this->assertSame(
            SchemaPathResolver::packageSchemaPath(),
            SchemaPathResolver::fromDatabasePath($this->tempDir . '/.cpm/db')
        );
    }

    public function testNeverResolvesToTheProjectRootSchemasDirectory(): void
    {
        // Regression: the old `dirname($dbPath) . '/../schemas'` expression
        // pointed at <project root>/schemas, which is not where schemas live.
        $resolved = SchemaPathResolver::fromDatabasePath($this->tempDir . '/.cpm/db');

        $this->assertNotSame($this->tempDir . '/schemas', $resolved);
        $this->assertStringNotContainsString('/..', $resolved);
    }

    public function testPackageSchemaPathExistsAndContainsSchemaFiles(): void
    {
        $path = SchemaPathResolver::packageSchemaPath();

        $this->assertDirectoryExists($path);
        $this->assertNotEmpty(glob($path . '/*.schema.json'));
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
