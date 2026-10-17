<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\SaasProductTaxonomy;

/**
 * Topic suggestions and the screen list for the reach-video routine.
 *
 * The compliance calendar lists the *usual* statutory due dates for Indian
 * small businesses; government extensions are common, so every entry tells
 * the routine to confirm the current date before stating it.
 */
final class VideoTopicPlanner
{
    /** Recurring monthly due dates (day of month). */
    private const MONTHLY = [
        7  => 'TDS/TCS deposit for last month (usually the 7th)',
        11 => 'GSTR-1 for monthly filers (usually the 11th)',
        13 => 'IFF / GSTR-1 for QRMP filers in quarter months (usually the 13th)',
        15 => 'PF and ESI contributions (usually the 15th)',
        20 => 'GSTR-3B for monthly filers (usually the 20th)',
    ];

    /** Annual / quarterly due dates as [month, day, label]. */
    private const ANNUAL = [
        [4, 30, 'MSME-1 half-yearly return (Oct–Mar) (usually 30 April)'],
        [5, 31, 'TDS return for Q4 (usually 31 May)'],
        [6, 15, 'First advance-tax instalment (usually 15 June)'],
        [7, 31, 'TDS return for Q1 and ITR for non-audit cases (usually 31 July)'],
        [9, 15, 'Second advance-tax instalment (usually 15 September)'],
        [9, 30, 'DIR-3 KYC and the tax-audit report (usually 30 September)'],
        [10, 30, 'AOC-4 financial statements (usually within 30 days of the AGM)'],
        [10, 31, 'TDS return for Q2, MSME-1 (Apr–Sep) and ITR for audit cases (usually 31 October)'],
        [11, 29, 'MGT-7 annual return (usually within 60 days of the AGM)'],
        [12, 15, 'Third advance-tax instalment (usually 15 December)'],
        [12, 31, 'GSTR-9 / 9C annual GST return and belated ITR (usually 31 December)'],
        [1, 31, 'TDS return for Q3 (usually 31 January)'],
        [3, 15, 'Final advance-tax instalment (usually 15 March)'],
        [3, 31, 'Year-end closing: books, GST reconciliation, investments for the year (31 March)'],
    ];

    /**
     * Public pages the routine may ask to screenshot (screenshot scenes must be
     * an https URL on aicountly.com or a *.aicountly.com app page).
     *
     * @return list<array{url: string, shows: string}>
     */
    public static function screens(): array
    {
        $base = 'https://aicountly.com';
        $pages = [
            '/'                      => 'AICOUNTLY home page — the product suite overview',
            '/platform'              => 'Platform overview',
            '/pricing'               => 'Pricing plans',
            '/smart-books'           => 'Smart Books (accounting) product page',
            '/financial-reporting'   => 'Financial Reporting product page',
            '/auditor'               => 'Auditor product page',
            '/secretarial'           => 'Secretarial (company law compliance) product page',
            '/hrms'                  => 'HRMS product page',
            '/our-people'            => 'Our People product page',
            '/contacts'              => 'Contacts product page',
            '/calendar'              => 'Calendar product page',
            '/vault'                 => 'Vault (document storage) product page',
            '/inventory'             => 'Inventory product page',
            '/billing'               => 'Billing product page',
            '/pos'                   => 'POS product page',
            '/releases'              => 'Release notes — what shipped recently',
            '/guides'                => 'Workflow guides index',
            '/help'                  => 'Help centre (knowledge base)',
            '/community'             => 'Community Q&A',
            '/blogs'                 => 'Blog',
        ];
        $out = [];
        foreach ($pages as $path => $shows) {
            $out[] = ['url' => $base . $path, 'shows' => $shows];
        }
        return $out;
    }

    /** @return list<array{topic: string, angle: string, kind: string}> */
    public static function recommend(?\DateTimeImmutable $today = null, int $days = 30): array
    {
        $today = ($today ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->setTime(0, 0);
        $end   = $today->modify("+{$days} days");
        $out   = [];

        for ($d = $today; $d <= $end; $d = $d->modify('+1 day')) {
            $day   = (int) $d->format('j');
            $month = (int) $d->format('n');
            $label = self::MONTHLY[$day] ?? null;
            if ($label !== null) {
                $out[] = ['topic' => $label, 'angle' => 'Due ' . $d->format('j M Y') . ' — explain what is due, who files it and how AICOUNTLY helps prepare it. Confirm the current due date on the official portal first; extensions are common.', 'kind' => 'compliance'];
            }
            foreach (self::ANNUAL as [$m, $dd, $text]) {
                if ($m === $month && $dd === $day) {
                    $out[] = ['topic' => $text, 'angle' => 'Due ' . $d->format('j M Y') . ' — a practical checklist video. Confirm the current due date before stating it.', 'kind' => 'compliance'];
                }
            }
        }

        foreach (array_slice(SaasProductTaxonomy::products(), 0, 12, true) as $slug => $label) {
            $out[] = ['topic' => "Getting started with {$label}", 'angle' => 'A launch walkthrough of what the product does today, grounded in its current code and release notes. Use its aicountly.com product page for screenshots.', 'kind' => 'launch', 'product_slug' => $slug];
        }

        return $out;
    }
}
