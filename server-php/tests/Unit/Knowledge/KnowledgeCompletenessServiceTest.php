<?php

namespace Tests\Unit\Knowledge;

use App\Libraries\KnowledgeCompletenessService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for KnowledgeCompletenessService.
 *
 * Uses a test-double subclass that overrides protected helper methods to return
 * controlled counts without requiring a database connection.
 */
final class KnowledgeCompletenessServiceTest extends TestCase
{
    /**
     * A product with all dimensions fully satisfied should score 100 and be AI-ready.
     */
    public function testFullyCompleteProductIsAiReady(): void
    {
        $svc = $this->makeSvc([
            'modules'      => 4,
            'features'     => ['available' => 10],
            'personas'     => 3,
            'industries'   => 3,
            'markets'      => 2,
            'problems'     => 3,
            'intents'      => 10,
            'claims'       => 4,
            'evidence'     => 6,
            'sources'      => 5,
            'brand_rules'  => 2,
        ]);

        $result = $svc->calculate($this->approvedProduct());

        $this->assertSame(100, $result['score']);
        $this->assertTrue($result['ai_ready']);
        $this->assertEmpty($result['missing']);
    }

    /**
     * Empty product should score very low and not be AI-ready.
     */
    public function testEmptyProductIsNotAiReady(): void
    {
        $svc = $this->makeSvc([
            'modules'      => 0,
            'features'     => [],
            'personas'     => 0,
            'industries'   => 0,
            'markets'      => 0,
            'problems'     => 0,
            'intents'      => 0,
            'claims'       => 0,
            'evidence'     => 0,
            'sources'      => 0,
            'brand_rules'  => 0,
        ]);

        $result = $svc->calculate([
            'id' => 2, 'slug' => 'empty', 'name' => 'Empty',
            'description' => '', 'public_url' => '', 'status' => 'draft',
        ]);

        $this->assertLessThan(30, $result['score']);
        $this->assertFalse($result['ai_ready']);
        $this->assertContains('no_modules',   $result['missing']);
        $this->assertContains('no_claims',    $result['missing']);
        $this->assertContains('no_evidence',  $result['missing']);
    }

    /**
     * Planned features trigger a warning but do not block scoring.
     */
    public function testPlannedFeaturesAddWarning(): void
    {
        $svc = $this->makeSvc([
            'modules'      => 2,
            'features'     => ['available' => 3, 'planned' => 2],
            'personas'     => 2,
            'industries'   => 2,
            'markets'      => 1,
            'problems'     => 2,
            'intents'      => 5,
            'claims'       => 2,
            'evidence'     => 3,
            'sources'      => 3,
            'brand_rules'  => 1,
        ]);

        $result = $svc->calculate($this->approvedProduct());

        $this->assertContains('has_planned_features', $result['warnings']);
    }

    /**
     * Weights constant must always sum to exactly 100.
     */
    public function testWeightsSumTo100(): void
    {
        $refl    = new \ReflectionClass(KnowledgeCompletenessService::class);
        $weights = $refl->getConstant('WEIGHTS');
        $this->assertSame(100, array_sum($weights));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function approvedProduct(): array
    {
        return [
            'id'          => 1,
            'slug'        => 'reach-ai',
            'name'        => 'Reach AI',
            'description' => 'Full and detailed product description.',
            'public_url'  => 'https://aicountly.com/reach-ai',
            'status'      => 'approved',
        ];
    }

    /**
     * Build a service stub whose protected helpers return controlled data.
     */
    private function makeSvc(array $counts): KnowledgeCompletenessService
    {
        return new class($counts) extends KnowledgeCompletenessService {
            private array $c;

            public function __construct(array $counts)
            {
                $this->c = $counts;
                // Deliberately skip parent::__construct to avoid DB model instantiation.
            }

            protected function modulesForProduct(int $id): array
            {
                return array_fill(0, $this->c['modules'], []);
            }

            protected function featureAvailabilityCountsForProduct(int $id): array
            {
                return $this->c['features'];
            }

            protected function personasForProduct(int $id): array
            {
                return array_fill(0, $this->c['personas'], []);
            }

            protected function industriesForProduct(int $id): array
            {
                return array_fill(0, $this->c['industries'], []);
            }

            protected function marketsForProduct(int $id): array
            {
                return array_fill(0, $this->c['markets'], []);
            }

            protected function problemsForProduct(int $id): array
            {
                return array_fill(0, $this->c['problems'], []);
            }

            protected function intentsForProduct(int $id): array
            {
                return array_fill(0, $this->c['intents'], []);
            }

            protected function claimsForProduct(int $id): array
            {
                return array_fill(0, $this->c['claims'], [
                    'id'               => 99,
                    'status'           => 'approved',
                    'requires_evidence'=> false,
                    'claim_summary'    => '',
                    'risk_level'       => 'low',
                ]);
            }

            protected function approvedEvidenceCountForClaim(int $claimId): int
            {
                return 1;
            }

            protected function evidenceForProduct(int $id): array
            {
                return array_fill(0, $this->c['evidence'], [
                    'id'         => 0,
                    'title'      => '',
                    'status'     => 'approved',
                    'valid_until'=> null,
                ]);
            }

            protected function isEvidenceExpired(array $evidence): bool
            {
                return false;
            }

            protected function findApprovedSources(): array
            {
                return array_fill(0, $this->c['sources'], []);
            }

            protected function brandRulesForProduct(int $id): array
            {
                return array_fill(0, $this->c['brand_rules'], []);
            }
        };
    }
}
