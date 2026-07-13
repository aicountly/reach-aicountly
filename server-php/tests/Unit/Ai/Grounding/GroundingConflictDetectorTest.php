<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Grounding;

use App\Libraries\Ai\Grounding\GroundingConflictDetector;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Grounding\GroundingConflictDetector
 */
class GroundingConflictDetectorTest extends CIUnitTestCase
{
    private GroundingConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new GroundingConflictDetector();
    }

    public function testNoConflictsForConsistentClaims(): void
    {
        $context = [
            'claims' => [
                ['id' => 1, 'claim_type' => 'performance', 'sentiment' => 'positive'],
                ['id' => 2, 'claim_type' => 'performance', 'sentiment' => 'positive'],
            ],
        ];

        $this->assertEmpty($this->detector->detect($context));
    }

    public function testDetectsClaimSentimentConflict(): void
    {
        $context = [
            'claims' => [
                ['id' => 1, 'claim_type' => 'cost', 'sentiment' => 'positive'],
                ['id' => 2, 'claim_type' => 'cost', 'sentiment' => 'negative'],
            ],
        ];

        $conflicts = $this->detector->detect($context);
        $this->assertNotEmpty($conflicts);
        $this->assertSame('claim_sentiment_conflict', $conflicts[0]['type']);
    }

    public function testDetectsDuplicateFeatureSlug(): void
    {
        $context = [
            'features' => [
                ['id' => 10, 'slug' => 'invoicing'],
                ['id' => 11, 'slug' => 'invoicing'],
            ],
        ];

        $conflicts = $this->detector->detect($context);
        $this->assertNotEmpty($conflicts);
        $this->assertSame('duplicate_feature', $conflicts[0]['type']);
    }

    public function testEmptyContextHasNoConflicts(): void
    {
        $this->assertEmpty($this->detector->detect([]));
    }
}
