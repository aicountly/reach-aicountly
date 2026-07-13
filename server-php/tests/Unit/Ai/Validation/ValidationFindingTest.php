<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Validation;

use App\Libraries\Ai\Validation\ValidationFinding;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Validation\ValidationFinding
 */
class ValidationFindingTest extends CIUnitTestCase
{
    public function testIsBlockingForCriticalFailure(): void
    {
        $finding = new ValidationFinding('test', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_CRITICAL, 'Title', 'Msg');
        $this->assertTrue($finding->isBlocking());
    }

    public function testIsBlockingForHighFailure(): void
    {
        $finding = new ValidationFinding('test', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_HIGH, 'Title', 'Msg');
        $this->assertTrue($finding->isBlocking());
    }

    public function testIsNotBlockingForPassedStatus(): void
    {
        $finding = new ValidationFinding('test', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_CRITICAL, 'Title', 'Msg');
        $this->assertFalse($finding->isBlocking());
    }

    public function testIsNotBlockingForWarningStatus(): void
    {
        $finding = new ValidationFinding('test', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Title', 'Msg');
        $this->assertFalse($finding->isBlocking());
    }

    public function testToArrayHasExpectedKeys(): void
    {
        $finding = new ValidationFinding('type_x', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Title', 'Message', 'field', ['key' => 'val'], 'fix hint');
        $arr     = $finding->toArray();

        $this->assertArrayHasKey('validator_type', $arr);
        $this->assertArrayHasKey('status', $arr);
        $this->assertArrayHasKey('severity', $arr);
        $this->assertSame('type_x', $arr['validator_type']);
    }
}
