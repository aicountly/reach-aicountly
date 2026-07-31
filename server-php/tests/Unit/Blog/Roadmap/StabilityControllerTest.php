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
