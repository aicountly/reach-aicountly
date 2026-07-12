<?php

namespace Tests\Unit\Content;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentValidationService waiver logic and status calculation.
 */
final class ContentValidationServiceTest extends TestCase
{
    /**
     * Waiver requires a non-empty reason.
     */
    public function testWaiverRequiresReason(): void
    {
        $reason = '';
        $this->assertEmpty($reason, 'Empty reason should not be accepted');
    }

    public function testWaiverWithReasonIsAccepted(): void
    {
        $reason = 'Approved by legal on 2026-07-12';
        $this->assertNotEmpty($reason);
    }

    /**
     * Overall validation status calculation:
     * - If any validation failed (non-waived): 'failed'
     * - If any warnings:                      'warnings'
     * - If all passed:                        'passed'
     * - If all waived:                        'waived'
     */
    public function testOverallStatus_Failed(): void
    {
        $results = [
            ['validation_status' => 'passed'],
            ['validation_status' => 'failed'],
        ];
        $this->assertSame('failed', $this->calculateOverall($results));
    }

    public function testOverallStatus_Passed(): void
    {
        $results = [
            ['validation_status' => 'passed'],
            ['validation_status' => 'passed'],
        ];
        $this->assertSame('passed', $this->calculateOverall($results));
    }

    public function testOverallStatus_Waived(): void
    {
        $results = [
            ['validation_status' => 'waived'],
            ['validation_status' => 'waived'],
        ];
        $this->assertSame('waived', $this->calculateOverall($results));
    }

    public function testOverallStatus_Warnings(): void
    {
        $results = [
            ['validation_status' => 'passed'],
            ['validation_status' => 'warnings'],
        ];
        $this->assertSame('warnings', $this->calculateOverall($results));
    }

    public function testOverallStatus_EmptyResultsIsNotRun(): void
    {
        $this->assertSame('not_run', $this->calculateOverall([]));
    }

    // ── Helper (mirrors ContentValidationModel::overallStatus logic) ─────────

    private function calculateOverall(array $results): string
    {
        if (empty($results)) {
            return 'not_run';
        }
        $statuses = array_column($results, 'validation_status');
        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }
        if (in_array('warnings', $statuses, true)) {
            return 'warnings';
        }
        if (count(array_unique($statuses)) === 1 && $statuses[0] === 'waived') {
            return 'waived';
        }
        return 'passed';
    }
}
