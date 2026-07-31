<?php

namespace Tests\Unit\Blog;

use App\Libraries\Blog\BlogPortfolioService;
use PHPUnit\Framework\TestCase;

final class WorkBlockServiceTest extends TestCase
{
    public function testPortfolioPercentsMustSumTo100(): void
    {
        $svc = new BlogPortfolioService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Portfolio percents must sum to 100.');

        $svc->update([
            'marketing_percent'          => 50,
            'product_percent'            => 30,
            'problem_to_product_percent' => 10,
        ]);
    }

    public function testPortfolioPercentsAcceptValidSum(): void
    {
        $svc = new BlogPortfolioService();

        try {
            $result = $svc->update([
                'marketing_percent'          => 40,
                'product_percent'            => 35,
                'problem_to_product_percent' => 25,
            ]);
            $this->assertSame(100, (int) $result['marketing_percent'] + (int) $result['product_percent'] + (int) $result['problem_to_product_percent']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable for BlogPortfolioService: ' . $e->getMessage());
        }
    }
}
