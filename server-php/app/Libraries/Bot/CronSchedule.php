<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

/**
 * A five-field cron expression (minute hour day-of-month month day-of-week),
 * evaluated in UTC — the server clock every Reach cron line and
 * `reach:schedule` timetable uses. Supports `*`, `n`, `a-b`, `a,b`, and
 * `/step` on any of those. Day-of-week 0 and 7 are Sunday.
 *
 * Used by the bot activity registry to show "next run" and to describe a
 * schedule in IST and UTC. It never decides when anything runs — cron and
 * `reach:schedule` do; a test pins the registry to their timetable.
 */
final class CronSchedule
{
    /** @var array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<int>} */
    private array $sets;

    private bool $domRestricted;
    private bool $dowRestricted;

    public function __construct(public readonly string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Cron expression must have 5 fields: '{$expression}'.");
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $sets   = [];
        foreach ($parts as $i => $field) {
            $sets[$i] = self::expand($field, $ranges[$i][0], $ranges[$i][1]);
        }
        // 7 is Sunday too.
        if (in_array(7, $sets[4], true)) {
            $sets[4] = array_values(array_unique(array_merge(array_diff($sets[4], [7]), [0])));
            sort($sets[4]);
        }
        $this->sets          = $sets;
        $this->domRestricted = $parts[2] !== '*';
        $this->dowRestricted = $parts[4] !== '*';
    }

    public function matches(\DateTimeImmutable $at): bool
    {
        $utc = $at->setTimezone(new \DateTimeZone('UTC'));
        return in_array((int) $utc->format('i'), $this->sets[0], true)
            && in_array((int) $utc->format('G'), $this->sets[1], true)
            && $this->dayMatches($utc);
    }

    /** The first minute strictly after $after that matches (UTC), or null within 400 days. */
    public function nextAfter(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $utc   = new \DateTimeZone('UTC');
        $start = $after->setTimezone($utc);
        $start = $start->setTime((int) $start->format('G'), (int) $start->format('i'))->modify('+1 minute');
        $day   = $start->setTime(0, 0);

        for ($d = 0; $d < 400; $d++, $day = $day->modify('+1 day')) {
            if (! $this->dayMatches($day)) {
                continue;
            }
            foreach ($this->sets[1] as $hour) {
                foreach ($this->sets[0] as $minute) {
                    $candidate = $day->setTime($hour, $minute);
                    if ($candidate >= $start) {
                        return $candidate;
                    }
                }
            }
        }
        return null;
    }

    /** How many times a day this fires (for "every N minutes" labels). */
    public function timesPerDay(): int
    {
        return count($this->sets[0]) * count($this->sets[1]);
    }

    private function dayMatches(\DateTimeImmutable $utc): bool
    {
        if (! in_array((int) $utc->format('n'), $this->sets[3], true)) {
            return false;
        }
        $dom = in_array((int) $utc->format('j'), $this->sets[2], true);
        $dow = in_array((int) $utc->format('w'), $this->sets[4], true);
        // Standard cron: when both day fields are restricted, either may match.
        if ($this->domRestricted && $this->dowRestricted) {
            return $dom || $dow;
        }
        return $dom && $dow;
    }

    /** @return list<int> */
    private static function expand(string $field, int $min, int $max): array
    {
        $out = [];
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepRaw] = explode('/', $part, 2);
                if (! ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                    throw new \InvalidArgumentException("Bad cron step '{$stepRaw}'.");
                }
                $step = (int) $stepRaw;
            }
            if ($part === '*') {
                [$lo, $hi] = [$min, $max];
            } elseif (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                [$lo, $hi] = [(int) $m[1], (int) $m[2]];
            } elseif (ctype_digit($part)) {
                $lo = (int) $part;
                $hi = $step > 1 ? $max : $lo;
            } else {
                throw new \InvalidArgumentException("Bad cron field '{$field}'.");
            }
            if ($lo < $min || $hi > $max || $lo > $hi) {
                throw new \InvalidArgumentException("Cron field '{$field}' is out of range {$min}-{$max}.");
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $out[] = $v;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}
