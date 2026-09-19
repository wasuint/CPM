<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tests\Unit\Analysis;

use ClaudeProjectManager\Analysis\DependencyAnalyzer;
use ClaudeProjectManager\DatabaseManager;
use PHPUnit\Framework\TestCase;

class DependencyAnalyzerTest extends TestCase
{
    private DependencyAnalyzer $analyzer;

    protected function setUp(): void
    {
        $database = $this->createMock(DatabaseManager::class);
        $this->analyzer = new DependencyAnalyzer($database);
    }

    public function testAcyclicGraphReportsNoCircularDependencies(): void
    {
        $graph = [
            'src/A.php' => ['exports' => ['A'], 'imports' => ['B']],
            'src/B.php' => ['exports' => ['B'], 'imports' => ['C']],
            'src/C.php' => ['exports' => ['C'], 'imports' => []],
        ];

        $this->assertSame([], $this->analyzer->detectCircularDependencies($graph));
    }

    public function testDirectTwoFileCycleIsDetected(): void
    {
        $graph = [
            'src/A.php' => ['exports' => ['A'], 'imports' => ['B']],
            'src/B.php' => ['exports' => ['B'], 'imports' => ['A']],
        ];

        $cycles = $this->analyzer->detectCircularDependencies($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(['src/A.php', 'src/B.php'], $cycles[0]);
    }

    public function testLongerCycleReturnsTheFullChain(): void
    {
        $graph = [
            'src/A.php' => ['exports' => ['A'], 'imports' => ['B']],
            'src/B.php' => ['exports' => ['B'], 'imports' => ['C']],
            'src/C.php' => ['exports' => ['C'], 'imports' => ['A']],
        ];

        $cycles = $this->analyzer->detectCircularDependencies($graph);

        $this->assertCount(1, $cycles);
        $this->assertSame(['src/A.php', 'src/B.php', 'src/C.php'], $cycles[0]);
    }

    public function testImportsThatResolveToNothingAreIgnored(): void
    {
        $graph = [
            'src/A.php' => ['exports' => ['A'], 'imports' => ['Vendor\\Unknown']],
        ];

        $this->assertSame([], $this->analyzer->detectCircularDependencies($graph));
    }

    public function testEmptyGraphIsHandled(): void
    {
        $this->assertSame([], $this->analyzer->detectCircularDependencies([]));
    }
}
