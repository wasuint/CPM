<?php

declare(strict_types=1);

namespace ClaudeProjectManager\Tests\Unit\Progress;

use ClaudeProjectManager\Progress\MetricsCalculator;
use PHPUnit\Framework\TestCase;

class MetricsCalculatorTest extends TestCase
{
    private MetricsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MetricsCalculator();
    }

    public function testCompletionPercentageIsRoundedToTwoDecimals(): void
    {
        $this->assertSame(33.33, $this->calculator->calculateCompletionPercentage(1, 3));
        $this->assertSame(100.0, $this->calculator->calculateCompletionPercentage(7, 7));
    }

    public function testCompletionPercentageWithoutItemsDoesNotDivideByZero(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateCompletionPercentage(0, 0));
    }

    public function testVelocityIsExpressedInItemsPerHour(): void
    {
        // 6 items in 120 minutes == 3 items/hour
        $this->assertSame(3.0, $this->calculator->calculateVelocity(6, 120));
        $this->assertSame(0.0, $this->calculator->calculateVelocity(5, 0));
    }

    public function testRemainingTimeUsesVelocityAndGuardsAgainstZero(): void
    {
        $this->assertSame(5.0, $this->calculator->estimateRemainingTime(10, 2.0));
        $this->assertSame(0.0, $this->calculator->estimateRemainingTime(10, 0.0));
        $this->assertSame(0.0, $this->calculator->estimateRemainingTime(10, -1.0));
    }

    public function testTrendNeedsAtLeastTwoDataPoints(): void
    {
        $trend = $this->calculator->calculateTrend([5]);

        $this->assertSame('insufficient_data', $trend['trend']);
        $this->assertSame('unknown', $trend['direction']);
        $this->assertSame(0.0, $trend['change_percentage']);
    }

    public function testTrendComparesTheTwoMostRecentDataPoints(): void
    {
        $improving = $this->calculator->calculateTrend([1, 2, 10, 15]);
        $this->assertSame('improving', $improving['direction']);
        $this->assertSame(50.0, $improving['change_percentage']);

        $declining = $this->calculator->calculateTrend([10, 8]);
        $this->assertSame('declining', $declining['direction']);
        $this->assertSame(-20.0, $declining['change_percentage']);

        $stable = $this->calculator->calculateTrend([4, 4]);
        $this->assertSame('stable', $stable['direction']);
    }

    public function testEffortDistributionSumsToOneHundredPercent(): void
    {
        $distribution = $this->calculator->calculateEffortDistribution([
            'features' => 30,
            'bugs' => 10,
            'docs' => 10,
        ]);

        $this->assertSame(60.0, $distribution['features']);
        $this->assertSame(20.0, $distribution['bugs']);
        $this->assertSame(20.0, $distribution['docs']);
        $this->assertEqualsWithDelta(100.0, array_sum($distribution), 0.01);
    }

    public function testEffortDistributionOfEmptyDataIsEmpty(): void
    {
        $this->assertSame([], $this->calculator->calculateEffortDistribution([]));
        $this->assertSame([], $this->calculator->calculateEffortDistribution(['a' => 0]));
    }
}
