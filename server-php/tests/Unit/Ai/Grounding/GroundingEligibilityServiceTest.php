<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Grounding;

use App\Libraries\Ai\Grounding\GroundingEligibilityService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Grounding\GroundingEligibilityService
 */
class GroundingEligibilityServiceTest extends CIUnitTestCase
{
    private GroundingEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GroundingEligibilityService();
    }

    public function testApprovedEntityIsEligible(): void
    {
        $entity = ['status' => 'approved', 'deleted_at' => null];
        $this->assertTrue($this->service->isEligible($entity));
    }

    public function testDraftEntityIsIneligible(): void
    {
        $entity = ['status' => 'draft', 'deleted_at' => null];
        $this->assertFalse($this->service->isEligible($entity));
    }

    public function testDeletedEntityIsIneligible(): void
    {
        $entity = ['status' => 'approved', 'deleted_at' => '2024-01-01 00:00:00'];
        $this->assertFalse($this->service->isEligible($entity));
    }

    public function testExpiredEntityIsIneligible(): void
    {
        $entity = ['status' => 'approved', 'deleted_at' => null, 'valid_until' => '2020-01-01 00:00:00'];
        $this->assertFalse($this->service->isEligible($entity));
    }

    public function testInternalOnlyEntityIsIneligible(): void
    {
        $entity = ['status' => 'approved', 'deleted_at' => null, 'internal_only' => true];
        $this->assertFalse($this->service->isEligible($entity));
    }

    public function testConfidentialEntityIsIneligible(): void
    {
        $entity = ['status' => 'approved', 'deleted_at' => null, 'is_confidential' => true];
        $this->assertFalse($this->service->isEligible($entity));
    }

    public function testFeatureWithUnavailableAvailabilityIsIneligible(): void
    {
        $feature = ['status' => 'approved', 'deleted_at' => null, 'availability' => 'planned'];
        $this->assertFalse($this->service->isFeatureEligible($feature));
    }

    public function testFeatureWithAvailableAvailabilityIsEligible(): void
    {
        $feature = ['status' => 'approved', 'deleted_at' => null, 'availability' => 'available'];
        $this->assertTrue($this->service->isFeatureEligible($feature));
    }

    public function testFilterEligibleFiltersCorrectly(): void
    {
        $entities = [
            ['id' => 1, 'status' => 'approved', 'deleted_at' => null],
            ['id' => 2, 'status' => 'draft', 'deleted_at' => null],
            ['id' => 3, 'status' => 'approved', 'deleted_at' => null],
        ];

        $filtered = $this->service->filterEligible($entities);
        $this->assertCount(2, $filtered);
        $this->assertSame(1, $filtered[0]['id']);
        $this->assertSame(3, $filtered[1]['id']);
    }
}
