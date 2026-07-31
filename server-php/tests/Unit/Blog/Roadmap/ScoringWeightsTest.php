<?php

namespace Tests\Unit\Blog\Roadmap;

use App\Libraries\Blog\Roadmap\ScoringWeights;
use PHPUnit\Framework\TestCase;

final class ScoringWeightsTest extends TestCase
{
    public function testDefaultsSumTo100(): void
    {
        $weights = ScoringWeights::defaults();

        $this->assertSame(100, $weights->sum());
    }

    public function testAssertValidThrowsOnNegativeWeight(): void
    {
        $weights = new ScoringWeights(searchOpportunity: -1);

        $this->expectException(\InvalidArgumentException::class);
        $weights->assertValid();
    }

    public function testFromArrayRoundTrip(): void
    {
        $row = [
            'search_opportunity'   => 25,
            'product_priority'     => 25,
            'audience_problem'     => 10,
            'conversion_potential' => 10,
            'content_gap'          => 10,
            'seasonality'          => 10,
            'internal_link_value'  => 5,
            'evidence_readiness'   => 5,
            'deductions_json'      => json_encode(['cannibalisation' => 12]),
        ];

        $weights = ScoringWeights::fromArray($row);
        $this->assertSame(100, $weights->sum());
        $this->assertSame(12.0, $weights->deductions['cannibalisation']);
    }
}
