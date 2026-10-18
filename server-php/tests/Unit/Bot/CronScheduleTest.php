<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Libraries\Bot\CronSchedule;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class CronScheduleTest extends CIUnitTestCase
{
    private static function at(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
    }

    public function testNextRunForCommonShapes(): void
    {
        $now = self::at('2026-10-18 10:07:30'); // Sunday
        $this->assertSame('2026-10-18T10:10:00+00:00', (new CronSchedule('*/5 * * * *'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-18T11:00:00+00:00', (new CronSchedule('0 * * * *'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-19T00:30:00+00:00', (new CronSchedule('30 0 * * *'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-18T10:30:00+00:00', (new CronSchedule('30 10 * * 0'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-25T10:30:00+00:00', (new CronSchedule('30 10 * * 0'))->nextAfter(self::at('2026-10-18 10:30:00'))->format('c'));
        $this->assertSame('2026-10-18T10:08:00+00:00', (new CronSchedule('* * * * *'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-18T10:30:00+00:00', (new CronSchedule('30 2-14/2 * * *'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-18T15:30:00+00:00', (new CronSchedule('30 15,17,19,21,23,1 * * *'))->nextAfter($now)->format('c'));
        $this->assertSame('2026-10-19T00:30:00+00:00', (new CronSchedule('30 0 * * 1'))->nextAfter($now)->format('c'));
    }

    public function testMatchesAndSundayAsSeven(): void
    {
        $this->assertTrue((new CronSchedule('30 10 * * 7'))->matches(self::at('2026-10-18 10:30:00')));
        $this->assertFalse((new CronSchedule('30 10 * * 7'))->matches(self::at('2026-10-19 10:30:00')));
        $this->assertTrue((new CronSchedule('*/15 * * * *'))->matches(self::at('2026-10-19 10:45:00')));
        $this->assertSame(288, (new CronSchedule('*/5 * * * *'))->timesPerDay());
    }

    public function testRejectsMalformedExpressions(): void
    {
        foreach (['* * * *', '61 * * * *', '*/0 * * * *', 'a * * * *', '5-2 * * * *'] as $bad) {
            try {
                new CronSchedule($bad);
                $this->fail("accepted '{$bad}'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
