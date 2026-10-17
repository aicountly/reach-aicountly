<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

/**
 * Recurring Indian SMB compliance due dates (GST, TDS/TCS, advance tax,
 * PF/ESI, income-tax returns, MCA filings) for the blog routine's topic
 * planning.
 *
 * These are the STATUTORY DEFAULT dates. CBIC, CBDT and MCA extend them by
 * notification often enough that nothing here may be published as fact
 * without checking the current position — every entry says so, and the
 * vetting rubric removes any date it is not certain of.
 */
final class ComplianceCalendar
{
    private const VERIFY = 'Statutory default; check CBIC/CBDT/MCA notifications for extensions before stating it.';

    /**
     * @return list<array{date: string, authority: string, title: string, applies_to: string, note: string}>
     */
    public function upcoming(\DateTimeImmutable $from, int $days = 30): array
    {
        $from  = $from->setTime(0, 0);
        $until = $from->modify("+{$days} days");
        $out   = [];

        // Walk month by month from the month before $from (monthly items fall
        // due in the month after their period) to the month of $until.
        $cursor = $from->modify('first day of this month');
        $last   = $until->modify('first day of this month');
        while ($cursor <= $last) {
            foreach ($this->forMonth((int) $cursor->format('Y'), (int) $cursor->format('n')) as $event) {
                $d = new \DateTimeImmutable($event['date']);
                if ($d >= $from && $d <= $until) {
                    $out[] = $event + ['note' => self::VERIFY];
                }
            }
            $cursor = $cursor->modify('+1 month');
        }

        usort($out, static fn (array $a, array $b): int => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);

        return $out;
    }

    /**
     * Everything that falls due in calendar month $month of $year.
     *
     * @return list<array{date: string, authority: string, title: string, applies_to: string}>
     */
    private function forMonth(int $year, int $month): array
    {
        $prev      = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
        $prevLabel = $prev->format('F Y');
        $prevM     = (int) $prev->format('n');
        $on        = static fn (int $day): string => sprintf('%04d-%02d-%02d', $year, $month, $day);
        $events    = [];

        // ── Monthly (period = previous month) ───────────────────────────────
        // TDS/TCS for March is due 30 April, not 7 April.
        if ($prevM !== 3) {
            $events[] = ['date' => $on(7), 'authority' => 'CBDT', 'title' => "TDS/TCS deposit for {$prevLabel}", 'applies_to' => 'Deductors and collectors'];
        }
        if ($month === 4) {
            $events[] = ['date' => $on(30), 'authority' => 'CBDT', 'title' => "TDS deposit for {$prevLabel}", 'applies_to' => 'Deductors (non-government)'];
        }
        $events[] = ['date' => $on(10), 'authority' => 'CBIC', 'title' => "GSTR-7 (TDS) and GSTR-8 (TCS) for {$prevLabel}", 'applies_to' => 'GST TDS deductors and e-commerce operators'];
        $events[] = ['date' => $on(11), 'authority' => 'CBIC', 'title' => "GSTR-1 for {$prevLabel}", 'applies_to' => 'Monthly GST filers'];
        $events[] = ['date' => $on(15), 'authority' => 'EPFO/ESIC', 'title' => "PF and ESI contributions for {$prevLabel}", 'applies_to' => 'Employers registered under EPF/ESI'];
        $events[] = ['date' => $on(20), 'authority' => 'CBIC', 'title' => "GSTR-3B for {$prevLabel}", 'applies_to' => 'Monthly GST filers'];

        $firstTwoOfQuarter = in_array($prevM, [4, 5, 7, 8, 10, 11, 1, 2], true);
        if ($firstTwoOfQuarter) {
            $events[] = ['date' => $on(13), 'authority' => 'CBIC', 'title' => "IFF (optional invoice upload) for {$prevLabel}", 'applies_to' => 'QRMP taxpayers'];
            $events[] = ['date' => $on(25), 'authority' => 'CBIC', 'title' => "GST payment (PMT-06) for {$prevLabel}", 'applies_to' => 'QRMP taxpayers'];
        }

        // ── Quarterly (month after the quarter ends) ────────────────────────
        $quarterEnd = [7 => 'April–June', 10 => 'July–September', 1 => 'October–December', 4 => 'January–March'];
        if (isset($quarterEnd[$month])) {
            $q = $quarterEnd[$month] . ' ' . ($month === 1 ? $year - 1 : $year);
            $events[] = ['date' => $on(13), 'authority' => 'CBIC', 'title' => "GSTR-1 for the quarter {$q}", 'applies_to' => 'QRMP taxpayers'];
            $events[] = ['date' => $on(18), 'authority' => 'CBIC', 'title' => "CMP-08 for the quarter {$q}", 'applies_to' => 'Composition taxpayers'];
            $events[] = ['date' => $on(22), 'authority' => 'CBIC', 'title' => "GSTR-3B for the quarter {$q} (22nd or 24th, by state)", 'applies_to' => 'QRMP taxpayers'];
            if ($month !== 4) {
                $events[] = ['date' => $on(15), 'authority' => 'CBDT', 'title' => "TCS return (27EQ) for the quarter {$q}", 'applies_to' => 'Tax collectors'];
                $events[] = ['date' => $on(31), 'authority' => 'CBDT', 'title' => "TDS returns (24Q/26Q/27Q) for the quarter {$q}", 'applies_to' => 'Deductors'];
            }
        }
        if ($month === 5) {
            $events[] = ['date' => $on(15), 'authority' => 'CBDT', 'title' => 'TCS return (27EQ) for the quarter January–March ' . $year, 'applies_to' => 'Tax collectors'];
            $events[] = ['date' => $on(31), 'authority' => 'CBDT', 'title' => 'TDS returns (24Q/26Q/27Q) for the quarter January–March ' . $year, 'applies_to' => 'Deductors'];
        }
        $form16a = [8 => 'April–June', 11 => 'July–September', 2 => 'October–December', 6 => 'January–March'];
        if (isset($form16a[$month])) {
            $events[] = ['date' => $on(15), 'authority' => 'CBDT', 'title' => "Form 16A to deductees for the quarter {$form16a[$month]}", 'applies_to' => 'Deductors (non-salary TDS)'];
        }

        // ── Advance tax ─────────────────────────────────────────────────────
        $advance = [6 => '15%', 9 => '45%', 12 => '75%', 3 => '100%'];
        if (isset($advance[$month])) {
            $events[] = ['date' => $on(15), 'authority' => 'CBDT', 'title' => "Advance tax instalment (cumulative {$advance[$month]})", 'applies_to' => 'Taxpayers with tax liability of ₹10,000 or more'];
        }

        // ── Annual ──────────────────────────────────────────────────────────
        $annual = [
            4  => [[30, 'MCA', 'MSME-1 for October–March (payments due to MSMEs over 45 days)', 'Companies with such dues'],
                   [30, 'CBIC', 'GSTR-4 annual return (previous financial year)', 'Composition taxpayers']],
            6  => [[15, 'CBDT', 'Form 16 to employees', 'Employers deducting salary TDS']],
            7  => [[31, 'CBDT', 'Income-tax return (non-audit cases)', 'Individuals, HUFs and firms not liable to audit']],
            9  => [[30, 'CBDT', 'Tax audit report (Form 3CA/3CB-3CD)', 'Businesses liable to tax audit'],
                   [30, 'MCA', 'DIR-3 KYC for directors', 'Directors holding a DIN'],
                   [30, 'MCA', 'Annual general meeting (companies)', 'Companies other than OPCs']],
            10 => [[15, 'MCA', 'ADT-1 auditor appointment (within 15 days of an AGM held on 30 September)', 'Companies'],
                   [30, 'MCA', 'AOC-4 financial statements (within 30 days of an AGM held on 30 September)', 'Companies'],
                   [31, 'CBDT', 'Income-tax return (audit cases)', 'Businesses liable to tax audit'],
                   [31, 'MCA', 'MSME-1 for April–September (payments due to MSMEs over 45 days)', 'Companies with such dues']],
            11 => [[29, 'MCA', 'MGT-7/7A annual return (within 60 days of an AGM held on 30 September)', 'Companies']],
            12 => [[31, 'CBIC', 'GSTR-9 / GSTR-9C annual return (previous financial year)', 'GST taxpayers above the annual-return threshold']],
        ];
        foreach ($annual[$month] ?? [] as [$day, $authority, $title, $appliesTo]) {
            $events[] = ['date' => $on($day), 'authority' => $authority, 'title' => $title, 'applies_to' => $appliesTo];
        }

        return $events;
    }
}
