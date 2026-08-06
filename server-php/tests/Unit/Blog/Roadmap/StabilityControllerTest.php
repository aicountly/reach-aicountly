<?php

namespace Tests\Unit\Blog\Roadmap;

use App\Libraries\Blog\Roadmap\StabilityController;
use PHPUnit\Framework\TestCase;

final class StabilityControllerTest extends TestCase
{
    public function testShouldSkipForMovementWhenDeltaBelowThreshold(): void
    {
        $controller = new StabilityController(minScoreMovement: 5.0);

        $this->assertTrue($controller->shouldSkipForMovement(52.0, 50.0));
        $this->assertFalse($controller->shouldSkipForMovement(56.0, 50.0));
    }

    public function testShouldNotSkipWhenNoPreviousScore(): void
    {
        $controller = new StabilityController(minScoreMovement: 5.0);

        $this->assertFalse($controller->shouldSkipForMovement(10.0, null));
    }

    public function testDailyAndWeeklyCapsFromConstructor(): void
    {
        $controller = new StabilityController(maxDailyCandidates: 7, maxWeeklyPublications: 3);

        $this->assertSame(7, $controller->dailyCandidateCap());
        $this->assertSame(3, $controller->weeklyPublicationCap());
    }

    public function testWeeklyCapDefaultsToAFullWeekAtTheDailyRate(): void
    {
        // A weekly ceiling below the daily cap stops production after day one.
        $controller = new StabilityController(maxDailyCandidates: 10);

        $this->assertSame(70, $controller->weeklyPublicationCap());
        $this->assertFalse($controller->capsSnapshot()['weekly_cap_below_daily']);
    }

    public function testCapsSnapshotFlagsAWeeklyCeilingBelowTheDailyCap(): void
    {
        $controller = new StabilityController(maxDailyCandidates: 10, maxWeeklyPublications: 5);

        $snapshot = $controller->capsSnapshot();

        $this->assertSame(10, $snapshot['daily_cap']);
        $this->assertSame(5, $snapshot['weekly_cap']);
        $this->assertTrue($snapshot['weekly_cap_below_daily']);
    }

    public function testPortfolioOverConcentratedWhenActualExceedsTargetPlusMargin(): void
    {
        $controller = new StabilityController();
        $counts = [
            'marketing'          => 8,
            'product'            => 1,
            'problem_to_product' => 1,
        ];
        $target = [
            'marketing'          => 45,
            'product'            => 35,
            'problem_to_product' => 20,
        ];

        $this->assertTrue($controller->portfolioOverConcentrated('marketing', $counts, $target));
        $this->assertFalse($controller->portfolioOverConcentrated('product', $counts, $target));
    }

    public function testPortfolioNotOverConcentratedWhenEmptyCounts(): void
    {
        $controller = new StabilityController();

        $this->assertFalse($controller->portfolioOverConcentrated('marketing', [], ['marketing' => 45]));
    }
}
