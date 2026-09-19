<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end regression test for the lost-update race: concurrent
 * `cpm issue add` invocations used to overwrite each other's writes
 * (read-modify-write without a transaction lock), silently dropping
 * issues after their IDs had already been printed.
 */
final class IssueConcurrencyTest extends TestCase
{
    private const PARALLEL_ADDS = 6;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/cpm-concurrency-' . uniqid();
        mkdir($this->projectDir, 0777, true);
        file_put_contents($this->projectDir . '/dummy.txt', "placeholder\n");

        // Initialize the project so schemas and database files exist.
        $this->runCpm(['start'], 120);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    public function test_parallel_issue_adds_lose_no_updates(): void
    {
        $processes = [];
        $binary = dirname(__DIR__, 2) . '/bin/claude-project';

        for ($i = 1; $i <= self::PARALLEL_ADDS; $i++) {
            $cmd = sprintf(
                '%s %s issue add -t %s -c task -p low --json',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($binary),
                escapeshellarg("parallel-{$i}")
            );
            $processes[] = proc_open($cmd, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes, $this->projectDir);
            fclose($pipes[1]);
        }

        foreach ($processes as $process) {
            $this->assertIsResource($process);
            proc_close($process);
        }

        $listOutput = $this->runCpm(['issue', 'list', '--all', '--json'], 60);
        $payload = json_decode($listOutput, true);

        $this->assertIsArray($payload, "issue list --json did not return JSON: {$listOutput}");
        $issues = $payload['issues'] ?? [];

        $this->assertCount(self::PARALLEL_ADDS, $issues, 'Concurrent adds lost issues');

        $ids = array_column($issues, 'id');
        $this->assertCount(self::PARALLEL_ADDS, array_unique($ids), 'Duplicate issue IDs were allocated');
    }

    private function runCpm(array $args, int $timeoutSeconds): string
    {
        $binary = dirname(__DIR__, 2) . '/bin/claude-project';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' '
            . implode(' ', array_map('escapeshellarg', $args))
            . ' 2>/dev/null';

        $process = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, $this->projectDir);

        $this->assertIsResource($process);

        stream_set_timeout($pipes[1], $timeoutSeconds);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return (string) $output;
    }
}
