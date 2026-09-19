<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Running CPM outside an initialised project must leave no trace (D-020):
 * no stray `.cpm/` directory and no "Failed to load current session" noise.
 * Commands that initialise a project must still create one.
 */
class NoProjectSideEffectsTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cpm-noproject-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700) && !is_dir($base)) {
            $this->fail("Could not create temp dir $base");
        }
        $this->workDir = (string) realpath($base);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function noProjectCommands(): array
    {
        return [
            'version' => [['--version']],
            'help' => [['--help']],
            'list' => [['list']],
            'context:digest' => [['context:digest']],
        ];
    }

    /**
     * @dataProvider noProjectCommands
     * @param list<string> $args
     */
    public function testCommandOutsideProjectLeavesNoTrace(array $args): void
    {
        [, $output] = $this->runCpm($args);

        $this->assertDirectoryDoesNotExist(
            $this->workDir . '/.cpm',
            'Running `cpm ' . implode(' ', $args) . '` outside a project must not create .cpm/'
        );
        $this->assertStringNotContainsString('Failed to load current session', $output);
    }

    public function testContextDigestOutsideProjectFailsWithHint(): void
    {
        [$exitCode, $output] = $this->runCpm(['context:digest']);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('cpm start', $output);
    }

    public function testStartInitialisesProjectInFreshDirectory(): void
    {
        [$exitCode, $output] = $this->runCpm(['start', '--no-interaction']);

        $this->assertSame(0, $exitCode, $output);
        $this->assertDirectoryExists($this->workDir . '/.cpm/db');
        $this->assertStringNotContainsString('Failed to load current session', $output);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCpm(array $args): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/cpm';
        $command = array_merge([PHP_BINARY, $bin], $args);

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->workDir
        );
        $this->assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, $output];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
