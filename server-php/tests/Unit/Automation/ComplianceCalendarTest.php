<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Libraries\Automation\Routine\ComplianceCalendar;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Statutory default due dates — a wrong date here would be published as
 * advice, so the common ones are pinned.
 *
 * @covers \App\Libraries\Automation\Routine\ComplianceCalendar
 */
final class ComplianceCalendarTest extends CIUnitTestCase
{
    /** @return array<string, string> date => titles */
    private function window(string $from, int $days = 30): array
    {
        $out = [];
        foreach ((new ComplianceCalendar())->upcoming(new \DateTimeImmutable($from), $days) as $e) {
            $out[$e['date']] = ($out[$e['date']] ?? '') . '|' . $e['title'];
            $this->assertStringContainsString('extensions', $e['note']);
        }

        return $out;
    }

    public function testMonthlyGstAndTdsDates(): void
    {
        $w = $this->window('2026-11-01', 29);
        $this->assertStringContainsString('TDS/TCS deposit for October 2026', $w['2026-11-07']);
        $this->assertStringContainsString('GSTR-1 for October 2026', $w['2026-11-11']);
        $this->assertStringContainsString('PF and ESI contributions for October 2026', $w['2026-11-15']);
        $this->assertStringContainsString('GSTR-3B for October 2026', $w['2026-11-20']);
        $this->assertStringContainsString('PMT-06', $w['2026-11-25']);
        $this->assertStringContainsString('MGT-7', $w['2026-11-29']);
    }

    public function testQuarterlyAndMarchSpecialCases(): void
    {
        $oct = $this->window('2026-10-01', 30);
        $this->assertStringContainsString('TCS return (27EQ) for the quarter July–September 2026', $oct['2026-10-15']);
        $this->assertStringContainsString('CMP-08', $oct['2026-10-18']);
        $this->assertStringContainsString('TDS returns (24Q/26Q/27Q) for the quarter July–September 2026', $oct['2026-10-31']);

        $apr = $this->window('2027-04-01', 29);
        $this->assertArrayNotHasKey('2027-04-07', $apr, 'March TDS is due 30 April, not 7 April');
        $this->assertStringContainsString('TDS deposit for March 2027', $apr['2027-04-30']);

        $jan = $this->window('2027-01-01', 30);
        $this->assertStringContainsString('quarter October–December 2026', $jan['2027-01-31']);

        $mar = $this->window('2027-03-01', 20);
        $this->assertStringContainsString('cumulative 100%', $mar['2027-03-15']);
    }

    public function testTheWindowIsBounded(): void
    {
        $dates = array_keys($this->window('2026-10-17', 30));
        $this->assertSame('2026-10-18', min($dates));
        $this->assertLessThanOrEqual('2026-11-16', max($dates));
    }
}
