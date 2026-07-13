<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Grounding;

use App\Libraries\Ai\Grounding\AiGroundingContextBuilder;
use App\Libraries\Ai\Grounding\GroundingConflictDetector;
use App\Libraries\Ai\Grounding\GroundingEligibilityService;
use App\Libraries\Ai\Grounding\GroundingException;
use App\Libraries\Ai\Grounding\GroundingSizeLimiter;
use App\Libraries\KnowledgeGroundingService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Grounding\AiGroundingContextBuilder
 */
class AiGroundingContextBuilderTest extends CIUnitTestCase
{
    private function makeBuilder(array $productReturn): AiGroundingContextBuilder
    {
        $knowledge = $this->createMock(KnowledgeGroundingService::class);
        $knowledge->method('forProduct')->willReturn($productReturn);
        $knowledge->method('forIntent')->willReturn($productReturn);

        return new AiGroundingContextBuilder(
            $knowledge,
            new GroundingEligibilityService(),
            new GroundingConflictDetector(),
            new GroundingSizeLimiter(),
        );
    }

    public function testBuildForProductThrowsWhenProductNotFound(): void
    {
        $builder = $this->makeBuilder([]);
        $this->expectException(GroundingException::class);
        $builder->buildForProduct('nonexistent-slug');
    }

    public function testBuildForProductReturnsContextWithMeta(): void
    {
        $builder = $this->makeBuilder([
            'product'   => ['id' => 1, 'name' => 'Aicountly', 'status' => 'approved'],
            'features'  => [
                ['id' => 10, 'slug' => 'invoicing', 'status' => 'approved', 'deleted_at' => null, 'availability' => 'available'],
                ['id' => 11, 'slug' => 'draft_feature', 'status' => 'draft', 'deleted_at' => null, 'availability' => 'available'],
            ],
            'claims'    => [],
            'brand_rules' => [],
        ]);

        $context = $builder->buildForProduct('aicountly');

        $this->assertArrayHasKey('__built_at', $context);
        $this->assertArrayHasKey('__token_estimate', $context);
        // Draft feature should be filtered out
        $this->assertCount(1, $context['features']);
        $this->assertSame(10, $context['features'][0]['id']);
    }

    public function testPrepareSnapshotReturnsCorrectStructure(): void
    {
        $builder = $this->makeBuilder([
            'product' => ['id' => 1, 'name' => 'Test', 'status' => 'approved'],
        ]);

        $context  = $builder->buildForProduct('test');
        $snapshot = $builder->prepareSnapshot($context, 42);

        $this->assertSame(42, $snapshot['generation_request_id']);
        $this->assertNotEmpty($snapshot['snapshot_hash']);
        $this->assertNotEmpty($snapshot['snapshot_json']);
        $this->assertIsInt($snapshot['token_estimate']);
    }

    public function testConflictsAreMarkedInContext(): void
    {
        $builder = $this->makeBuilder([
            'product' => ['id' => 1, 'status' => 'approved'],
            'claims'  => [
                ['id' => 1, 'claim_type' => 'cost', 'sentiment' => 'positive', 'deleted_at' => null, 'status' => 'approved'],
                ['id' => 2, 'claim_type' => 'cost', 'sentiment' => 'negative', 'deleted_at' => null, 'status' => 'approved'],
            ],
        ]);

        $context = $builder->buildForProduct('test');
        $this->assertArrayHasKey('__conflicts', $context);
        $this->assertNotEmpty($context['__conflicts']);
    }
}
