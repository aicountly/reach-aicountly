<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DistributionJobTypesTest extends CIUnitTestCase
{
    public function testAllReturns6Types(): void
    {
        $all = DistributionJobTypes::all();
        $this->assertCount(6, $all, 'Expected exactly 6 Phase 7 distribution job types.');
    }

    public function testAllTypesAreStrings(): void
    {
        foreach (DistributionJobTypes::all() as $type) {
            $this->assertIsString($type);
            $this->assertStringStartsWith('distribution.', $type);
        }
    }

    public function testScheduleConstantCorrect(): void
    {
        $this->assertSame('distribution.campaign.schedule', DistributionJobTypes::CAMPAIGN_SCHEDULE);
    }

    public function testReconcileConstantCorrect(): void
    {
        $this->assertSame('distribution.campaign.reconcile', DistributionJobTypes::CAMPAIGN_RECONCILE);
    }

    public function testAllTypesUnique(): void
    {
        $all = DistributionJobTypes::all();
        $this->assertSame(count($all), count(array_unique($all)), 'Job types must be unique.');
    }
}
