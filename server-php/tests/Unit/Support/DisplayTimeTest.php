<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Support\DisplayTime;
use CodeIgniter\Test\CIUnitTestCase;

final class DisplayTimeTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        DisplayTime::useZone(null);
        parent::tearDown();
    }

    public function testDefaultsToIstFromTheSetting(): void
    {
        $this->assertSame('Asia/Kolkata', ReachConfig::registry()['ui.timezone']['default']);
        $this->assertSame('Asia/Kolkata', DisplayTime::zoneName());
        $this->assertSame('IST', DisplayTime::label());
    }

    public function testZoneLessStringsAreUtcAndOffsetsAreHonoured(): void
    {
        DisplayTime::useZone('Asia/Kolkata');
        foreach ([
            '2026-10-18 01:04:00',            // date() / TIMESTAMP — UTC on this server
            '2026-10-18 01:04:00+00',         // Postgres TIMESTAMPTZ
            '2026-10-18 01:04:00.123456+00',
            '2026-10-18T01:04:00Z',
            '2026-10-18T01:04:00+00:00',
            '2026-10-18 06:34:00+05:30',
            '2026-10-17T21:04:00-04:00',
        ] as $value) {
            $this->assertSame('18-10-2026 06:34 IST', DisplayTime::format($value), $value);
        }
        $this->assertSame('18-10-2026 06:34', DisplayTime::format('2026-10-18 01:04:00', '—', false));
        $this->assertSame('06:34 IST', DisplayTime::time('2026-10-18 01:04:00'));
    }

    public function testInstantsDateObjectsAndTimestamps(): void
    {
        DisplayTime::useZone('Asia/Kolkata');
        $ts = (new \DateTimeImmutable('2026-10-18 20:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $this->assertSame('19-10-2026 01:30 IST', DisplayTime::format($ts), 'crosses midnight into the IST day');
        $this->assertSame('19-10-2026', DisplayTime::date($ts));
        $this->assertSame('18-10-2026 06:34 IST', DisplayTime::format(new \DateTimeImmutable('2026-10-18 01:04:00', new \DateTimeZone('UTC'))));
    }

    public function testDateOnlyValuesNeverShift(): void
    {
        DisplayTime::useZone('America/New_York');
        $this->assertSame('03-08-2026', DisplayTime::date('2026-08-03'));
        $this->assertSame('03-08-2026', DisplayTime::format('2026-08-03'));
        $this->assertSame('—', DisplayTime::time('2026-08-03'));
    }

    public function testIsDstFreeAndFallsBack(): void
    {
        DisplayTime::useZone('Asia/Kolkata');
        $this->assertSame('15-01-2026 17:30 IST', DisplayTime::format('2026-01-15 12:00:00'));
        $this->assertSame('15-07-2026 17:30 IST', DisplayTime::format('2026-07-15 12:00:00'));
        $this->assertSame('—', DisplayTime::format(null));
        $this->assertSame('n/a', DisplayTime::format('not a date', 'n/a'));
        $this->assertSame('—', DisplayTime::date('2026-02-30'));
    }

    public function testAnotherZoneAndAnExplicitOverride(): void
    {
        DisplayTime::useZone('UTC');
        $this->assertSame('18-10-2026 01:04 UTC', DisplayTime::format('2026-10-18 01:04:00'));
        $this->assertSame('18-10-2026 06:34 IST', DisplayTime::format('2026-10-18 01:04:00', '—', true, 'Asia/Kolkata'));
        DisplayTime::useZone('Not/AZone');
        $this->assertSame('Asia/Kolkata', DisplayTime::zoneName(), 'an invalid zone never replaces IST');
    }

    public function testUtcSlotStatesBothZones(): void
    {
        DisplayTime::useZone('Asia/Kolkata');
        $this->assertSame('08:00 UTC (13:30 IST)', DisplayTime::utcSlot(8));
        $this->assertSame('22:30 UTC (04:00 IST)', DisplayTime::utcSlot(22, 30));
    }

    public function testTheSettingOnlyAcceptsListedZones(): void
    {
        $this->assertSame('UTC', ReachConfig::validate('ui.timezone', 'UTC'));
        $this->expectException(\InvalidArgumentException::class);
        ReachConfig::validate('ui.timezone', 'Mars/Olympus');
    }
}
