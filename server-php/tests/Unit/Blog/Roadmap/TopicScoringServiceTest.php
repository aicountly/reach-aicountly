<?php

namespace Tests\Unit\Blog\Roadmap;

use App\Libraries\Blog\Roadmap\ScoringWeights;
use App\Libraries\Blog\Roadmap\TopicScoringService;
use PHPUnit\Framework\TestCase;

final class TopicScoringServiceTest extends TestCase
{
    private TopicScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TopicScoringService();
    }

    public function testFullSignalsProduceExpectedTotal(): void
    {
        $signals = [
            'impressions'         => 10000,
            'clicks'              => 1000,
            'ctr'                 => 1.0,
            'avg_position'        => 1,
            'organic_sessions'    => 5000,
            'product_priority'    => 1.0,
            'audience_problem'    => 1.0,
            'leads'               => 100,
            'conversions'         => 50,
            'product_visits'      => 1000,
            'content_gap'         => 1.0,
            'seasonality'         => 1.0,
            'internal_link_value' => 1.0,
            'evidence_ready'      => true,
        ];

        $score = $this->service->scoreCandidate(['id' => 1], $signals, ScoringWeights::defaults());

        $this->assertSame(100.0, $score['total_score']);
        $this->assertSame([], $score['inputs']['missing_signals']);
    }

    public function testMissingSignalsProduceZeroContributionAndAreRecorded(): void
    {
        $score = $this->service->scoreCandidate(['id' => 2], [], ScoringWeights::defaults());

        $this->assertSame(0.0, $score['total_score']);
        $this->assertNotEmpty($score['inputs']['missing_signals']);
        $this->assertContains('impressions', $score['inputs']['missing_signals']);
        $this->assertContains('product_priority', $score['inputs']['missing_signals']);
    }

    public function testColdStartFloorAppliesForDiscoveredTopics(): void
    {
        putenv('BLOG_ROADMAP_COLD_START_ENABLED=true');
        putenv('BLOG_ROADMAP_COLD_START_SCORE=55');
        $_ENV['BLOG_ROADMAP_COLD_START_ENABLED'] = 'true';
        $_ENV['BLOG_ROADMAP_COLD_START_SCORE'] = '55';

        $score = $this->service->scoreCandidate(
            ['id' => 9, 'source' => 'work_block_discover_topics', 'evidence_ready' => true],
            ['missing_signals' => ['content_identity'], 'evidence_ready' => true],
            ScoringWeights::defaults(),
        );

        $this->assertSame(55.0, $score['total_score']);
        $this->assertTrue($score['inputs']['cold_start_applied'] ?? false);
    }

    public function testDeductionsSubtractFromTotal(): void
    {
        $signals = [
            'product_priority' => 1.0,
            'audience_problem' => 1.0,
            'content_gap'      => 1.0,
            'seasonality'      => 1.0,
            'internal_link_value' => 1.0,
            'evidence_ready'   => true,
            'cannibalisation'  => true,
        ];

        $score = $this->service->scoreCandidate(['id' => 3], $signals, ScoringWeights::defaults());

        $this->assertSame(15.0, $score['deductions']['cannibalisation']);
        $this->assertSame(15.0, $score['deduction_total']);
        $this->assertLessThan(70.0, $score['total_score']);
    }

    public function testTotalIsClampedAtZero(): void
    {
        $weights = new ScoringWeights(
            deductions: [
                'near_duplicate' => 200,
            ],
        );

        $score = $this->service->scoreCandidate(
            ['id' => 4],
            ['near_duplicate' => true, 'product_priority' => 0.1],
            $weights,
        );

        $this->assertSame(0.0, $score['total_score']);
    }
}
