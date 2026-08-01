<?php

namespace Tests\Unit\Blog\Roadmap;

use App\Libraries\Blog\Roadmap\RoadmapDecisionEngine;
use PHPUnit\Framework\TestCase;

final class RoadmapDecisionEngineTest extends TestCase
{
    private RoadmapDecisionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new RoadmapDecisionEngine();
    }

    public function testLockedCandidateHolds(): void
    {
        $result = $this->engine->decide(
            ['is_locked' => true],
            ['total_score' => 90, 'deductions' => [], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::HOLD, $result['decision']);
    }

    public function testPostgresFalseStringIsNotTreatedAsLocked(): void
    {
        $result = $this->engine->decide(
            ['is_locked' => 'f', 'is_human_pinned' => 'f'],
            ['total_score' => 90, 'deductions' => [], 'factors' => []],
            ['min_score' => 40],
        );

        $this->assertSame(RoadmapDecisionEngine::CREATE_NEW, $result['decision']);
        $this->assertSame('Candidate meets scoring threshold.', $result['reason']);
    }

    public function testPostgresTrueStringIsTreatedAsLocked(): void
    {
        $result = $this->engine->decide(
            ['is_locked' => 't'],
            ['total_score' => 90, 'deductions' => [], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::HOLD, $result['decision']);
        $this->assertSame('Candidate is locked.', $result['reason']);
    }

    public function testPostgresHumanPinnedTrueStringCreatesNew(): void
    {
        $result = $this->engine->decide(
            ['is_locked' => 'f', 'is_human_pinned' => 't'],
            ['total_score' => 5, 'deductions' => [], 'factors' => []],
            ['min_score' => 40],
        );

        $this->assertSame(RoadmapDecisionEngine::CREATE_NEW, $result['decision']);
        $this->assertSame('Human-pinned candidate.', $result['reason']);
    }

    public function testCooldownCandidateHolds(): void
    {
        $result = $this->engine->decide(
            ['cooldown_until' => date('Y-m-d H:i:s', strtotime('+3 days'))],
            ['total_score' => 90, 'deductions' => [], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::HOLD, $result['decision']);
    }

    public function testHumanPinnedCreatesNew(): void
    {
        $result = $this->engine->decide(
            ['is_human_pinned' => true],
            ['total_score' => 10, 'deductions' => [], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::CREATE_NEW, $result['decision']);
    }

    public function testCannibalisationMergesCompeting(): void
    {
        $result = $this->engine->decide(
            [],
            ['total_score' => 80, 'deductions' => ['cannibalisation' => 15], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::MERGE_COMPETING, $result['decision']);
    }

    public function testNearDuplicateRejects(): void
    {
        $result = $this->engine->decide(
            [],
            ['total_score' => 80, 'deductions' => ['near_duplicate' => 25], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::REJECT, $result['decision']);
    }

    public function testHighRiskWithoutEvidenceRequiresHumanReview(): void
    {
        $result = $this->engine->decide(
            ['evidence_ready' => false],
            ['total_score' => 80, 'deductions' => [], 'factors' => []],
            ['risk_level' => 'HIGH'],
        );

        $this->assertSame(RoadmapDecisionEngine::REQUIRE_HUMAN_REVIEW, $result['decision']);
        $this->assertTrue($result['requires_human_review']);
    }

    public function testStaleContentRefreshesExisting(): void
    {
        $result = $this->engine->decide(
            ['content_item_id' => 42],
            ['total_score' => 80, 'deductions' => [], 'factors' => []],
            ['content_stale' => true],
        );

        $this->assertSame(RoadmapDecisionEngine::REFRESH_EXISTING, $result['decision']);
    }

    public function testWeakCtrStrongImpressionsImprovesTitleMeta(): void
    {
        $result = $this->engine->decide(
            ['content_item_id' => 42],
            [
                'total_score' => 80,
                'deductions'  => [],
                'factors'     => ['internal_link_value' => 4],
                'inputs'      => ['signals' => ['ctr' => 0.01, 'impressions' => 5000]],
            ],
        );

        $this->assertSame(RoadmapDecisionEngine::IMPROVE_TITLE_META, $result['decision']);
    }

    public function testLowInternalLinksImprovesLinks(): void
    {
        $result = $this->engine->decide(
            ['content_item_id' => 42],
            [
                'total_score' => 80,
                'deductions'  => [],
                'factors'     => ['internal_link_value' => 1],
                'weights'     => ['internal_link_value' => 5],
                'inputs'      => ['signals' => ['ctr' => 0.05, 'impressions' => 100]],
            ],
        );

        $this->assertSame(RoadmapDecisionEngine::IMPROVE_INTERNAL_LINKS, $result['decision']);
    }

    public function testPortfolioOverConcentrationHolds(): void
    {
        $result = $this->engine->decide(
            [],
            ['total_score' => 80, 'deductions' => ['portfolio_over_concentration' => 15], 'factors' => []],
        );

        $this->assertSame(RoadmapDecisionEngine::HOLD, $result['decision']);
    }

    public function testLowScoreHolds(): void
    {
        $result = $this->engine->decide(
            [],
            ['total_score' => 20, 'deductions' => [], 'factors' => []],
            ['min_score' => 40],
        );

        $this->assertSame(RoadmapDecisionEngine::HOLD, $result['decision']);
    }

    public function testNonProductionProductHoldsUnlessFlagged(): void
    {
        $result = $this->engine->decide(
            ['portfolio_stream' => 'product', 'primary_product_id' => 1],
            ['total_score' => 80, 'deductions' => [], 'factors' => []],
            ['product_production_ready' => false],
        );

        $this->assertSame(RoadmapDecisionEngine::HOLD, $result['decision']);
    }

    public function testNonProductionProductCreatesSupportWhenFlagged(): void
    {
        $result = $this->engine->decide(
            ['portfolio_stream' => 'product'],
            ['total_score' => 80, 'deductions' => [], 'factors' => []],
            ['product_production_ready' => false, 'create_product_support_flagged' => true],
        );

        $this->assertSame(RoadmapDecisionEngine::CREATE_PRODUCT_SUPPORT, $result['decision']);
    }

    public function testDefaultCreatesNew(): void
    {
        $result = $this->engine->decide(
            [],
            ['total_score' => 80, 'deductions' => [], 'factors' => []],
            ['min_score' => 40, 'product_production_ready' => true],
        );

        $this->assertSame(RoadmapDecisionEngine::CREATE_NEW, $result['decision']);
    }
}
