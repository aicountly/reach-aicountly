<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Grounding;

use App\Libraries\Ai\Grounding\GroundingSizeLimiter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Grounding\GroundingSizeLimiter
 */
class GroundingSizeLimiterTest extends CIUnitTestCase
{
    public function testSmallContextPassesThrough(): void
    {
        $limiter  = new GroundingSizeLimiter(10000);
        $context  = ['product' => ['id' => 1, 'name' => 'Test'], 'features' => []];
        $result   = $limiter->limit($context);

        $this->assertArrayNotHasKey('__truncated', $result);
    }

    public function testLargeContextGetsTrimmed(): void
    {
        $limiter = new GroundingSizeLimiter(200);

        $context = [
            'product'  => ['id' => 1, 'name' => 'Aicountly'],
            'evidence' => array_fill(0, 50, ['id' => 1, 'text' => str_repeat('x', 10)]),
        ];

        $result = $limiter->limit($context);

        $this->assertLessThanOrEqual(200, strlen(json_encode($result)));
        $this->assertArrayHasKey('__truncated', $result);
        $this->assertContains('evidence', $result['__truncated']);
    }

    public function testExceedsLimitReturnsTrueForBigContext(): void
    {
        $limiter = new GroundingSizeLimiter(10);
        $this->assertTrue($limiter->exceedsLimit(['key' => str_repeat('x', 50)]));
    }

    public function testEstimateTokens(): void
    {
        $limiter  = new GroundingSizeLimiter();
        $context  = ['title' => str_repeat('x', 400)]; // ~400 chars / 4 = 100 tokens
        $estimate = $limiter->estimateTokens($context);
        $this->assertGreaterThan(0, $estimate);
    }
}
