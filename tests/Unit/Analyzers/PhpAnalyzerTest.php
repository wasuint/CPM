<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tests\Unit\Analyzers;

use ClaudeProjectManager\Analyzers\PhpAnalyzer;
use ClaudeProjectManager\ConfigManager;
use PHPUnit\Framework\TestCase;

class PhpAnalyzerTest extends TestCase
{
    private string $tempDir;
    private PhpAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cpm-php-analyzer-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
        $this->analyzer = new PhpAnalyzer(new ConfigManager($this->tempDir));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testAnalysesClassesAndMethodsOfASmallFixture(): void
    {
        $fixture = $this->writeFixture('Calculator.php', <<<'PHP_FIXTURE'
<?php

declare(strict_types=1);

namespace Demo;

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    private function reset(): void
    {
    }
}
PHP_FIXTURE);

        $analysis = $this->analyzer->analyzeFile($fixture);

        $this->assertSame($fixture, $analysis->getFilePath());
        $this->assertSame('php', $analysis->getFileType());

        $classes = $analysis->getClasses();
        $this->assertCount(1, $classes);
        $this->assertSame('Calculator', $classes[0]['name']);

        // Methods are reported on their class; getFunctions() holds only
        // free-standing functions.
        $methods = [];
        foreach ($classes[0]['methods'] as $method) {
            $methods[$method['name']] = $method['visibility'];
        }

        $this->assertSame(['add' => 'public', 'reset' => 'private'], $methods);
        $this->assertSame([], $analysis->getFunctions());
    }

    public function testMetricsCountFunctionsClassesAndLines(): void
    {
        $fixture = $this->writeFixture('Simple.php', <<<'PHP_FIXTURE'
<?php

function alpha(): string
{
    return 'a';
}

function beta(): string
{
    return 'b';
}
PHP_FIXTURE);

        $metrics = $this->analyzer->analyzeFile($fixture)->getMetrics();

        $this->assertSame(2, $metrics['functions_count']);
        $this->assertSame(0, $metrics['classes_count']);
        $this->assertGreaterThan(0, $metrics['lines']);
        $this->assertSame(filesize($fixture), $metrics['size']);
    }

    public function testFilesOverFiveHundredLinesRaiseASizeIssue(): void
    {
        $body = str_repeat("// padding line\n", 520);
        $fixture = $this->writeFixture('Long.php', "<?php\n\n" . $body);

        $issues = $this->analyzer->analyzeFile($fixture)->getIssues();

        $this->assertNotEmpty($issues);
        $this->assertSame('file_size', $issues[0]['type']);
        $this->assertSame('warning', $issues[0]['severity']);
    }

    public function testAnalysingAMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->analyzer->analyzeFile($this->tempDir . '/nope.php');
    }

    public function testToArrayExposesTheAnalysisAsPlainData(): void
    {
        $fixture = $this->writeFixture('Tiny.php', "<?php\n\nfunction tiny(): void {}\n");

        $data = $this->analyzer->analyzeFile($fixture)->toArray();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('functions', $data);
        $this->assertArrayHasKey('metrics', $data);
    }

    private function writeFixture(string $name, string $code): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $code);

        return $path;
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
