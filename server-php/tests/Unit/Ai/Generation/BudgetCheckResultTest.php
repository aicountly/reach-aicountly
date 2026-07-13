<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Generation;

use App\Libraries\Ai\Generation\BudgetCheckResult;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Generation\BudgetCheckResult
 */
class BudgetCheckResultTest extends CIUnitTestCase
{
    public function testAllowedResult(): void
    {
        $result = new BudgetCheckResult(true, false);
        $this->assertTrue($result->allowed);
        $this->assertFalse($result->hardBlocked);
        $this->assertFalse($result->nearWarning);
    }

    public function testHardBlockedResult(): void
    {
        $result = new BudgetCheckResult(false, true, 'global', 'global', 'daily', 100.0, 100.0);
        $this->assertFalse($result->allowed);
        $this->assertTrue($result->hardBlocked);
        $this->assertSame('global', $result->scopeType);
        $this->assertSame('daily', $result->periodType);
    }

    public function testWarningResult(): void
    {
        $result = new BudgetCheckResult(true, false, 'provider', 'openai', 'monthly', 80.0, 100.0, true);
        $this->assertTrue($result->allowed);
        $this->assertFalse($result->hardBlocked);
        $this->assertTrue($result->nearWarning);
    }
}
