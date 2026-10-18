<?php

namespace Tests\Unit\Dashboard;

use App\Libraries\Dashboard\Forecast;
use CodeIgniter\Test\CIUnitTestCase;

final class ForecastTest extends CIUnitTestCase
{
    /** @return array<string, float> */
    private static function series(int $days, callable $f, string $from = '2026-07-01'): array
    {
        $out = [];
        $d   = new \DateTimeImmutable($from);
        for ($i = 0; $i < $days; $i++) {
            $out[$d->modify("+{$i} day")->format('Y-m-d')] = $f($i, (int) $d->modify("+{$i} day")->format('N'));
        }

        return $out;
    }

    public function testHiddenUntilThereAreEnoughDays(): void
    {
        $r = Forecast::daily(self::series(20, static fn () => 10));
        $this->assertFalse($r['available']);
        $this->assertSame(20, $r['points']);
        $this->assertStringContainsString('20 of 28 days', $r['reason']);

        $this->assertFalse(Forecast::daily([])['available']);
    }

    public function testRecoversALinearTrendExactly(): void
    {
        $r = Forecast::daily(self::series(60, static fn (int $t) => 10 + 2 * $t), 28);

        $this->assertTrue($r['available']);
        $this->assertEqualsWithDelta(2.0, $r['slope_per_day'], 1e-6);
        $this->assertSame('up', $r['trend']);
        // Next day is t=60 → 130; a perfect fit has a zero-width band.
        $this->assertEqualsWithDelta(130.0, $r['series'][0]['value'], 1e-6);
        $this->assertEqualsWithDelta($r['series'][0]['value'], $r['series'][0]['high'], 1e-6);
        $this->assertStringContainsString('Least-squares linear trend over the last 60 days', $r['method']);
    }

    public function testDayOfWeekPatternIsCarriedForward(): void
    {
        // Flat 20/day, but weekends (N=6,7) at 5.
        $r = Forecast::daily(self::series(56, static fn (int $t, int $w) => $w >= 6 ? 5 : 20), 7);

        $byWeekday = [];
        foreach ($r['series'] as $p) {
            $byWeekday[(int) (new \DateTimeImmutable($p['date']))->format('N')] = $p['value'];
        }
        $this->assertEqualsWithDelta(20.0, $byWeekday[3], 0.5);
        $this->assertEqualsWithDelta(5.0, $byWeekday[7], 0.5);
        $this->assertSame('flat', $r['trend']);
    }

    public function testMissingDaysCountAsZeroAndNothingGoesNegative(): void
    {
        // Sparse, declining, tiny volume — like a new site's Search Console.
        $data = ['2026-08-01' => 3, '2026-08-15' => 1, '2026-09-10' => 1];
        $r    = Forecast::daily($data, 28);

        $this->assertTrue($r['available']);
        $this->assertSame(41, $r['points'], 'every day between first and last counts');
        $this->assertTrue($r['low_volume']);
        foreach ($r['series'] as $p) {
            $this->assertGreaterThanOrEqual(0.0, $p['low']);
            $this->assertGreaterThanOrEqual($p['low'], $p['value']);
            $this->assertGreaterThanOrEqual($p['value'], $p['high']);
        }
        $this->assertGreaterThan(0.0, $r['projected']['high'] - $r['projected']['low'], 'noise widens the band');
    }
}
