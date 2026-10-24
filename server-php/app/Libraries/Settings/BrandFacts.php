<?php

declare(strict_types=1);

namespace App\Libraries\Settings;

use App\Libraries\Automation\Structured\ProductCatalog;

/**
 * Who Aicountly is, in the words every vetting context and the reach-command
 * brief share, so the vetting model accepts posts about Aicountly Academy
 * (training run by Aicountly) as well as the software — and refuses the
 * claims a course post must never make. One copy, here.
 */
final class BrandFacts
{
    public const COMPANY = 'Aicountly (aicountly.com) builds Indian cloud accounting, compliance & business software and runs Aicountly Academy — industry training for college students (Aicountly Edge long-term programme, Aicountly Aspire short-term courses), with admissions, tests, project evaluation and certificates run by Aicountly.';

    /**
     * The claim rules for the courses. They narrow what may be said; they
     * never widen the offer (BrandOffer) or any other rule.
     */
    public const ACADEMY_RULES = 'Aicountly Academy courses are training that Aicountly runs itself, not software and not Beta. '
        . 'Never state a fee amount, discount, scholarship, EMI, duration, start date, seat count, eligibility or syllabus detail unless the context states it; '
        . 'never promise or imply placement, jobs, interviews, internships, salaries, stipends, exam results or a pass; '
        . 'never call a course free unless the offer says so; '
        . 'the certificate is issued by Aicountly Academy — never call it a degree, diploma or a government-, university- or ICAI-recognised qualification.';

    /**
     * Aicountly Academy and its courses for a vetting context.
     *
     * @return array{courses: list<array{name:string, url:?string, what:?string}>, rules: string}
     */
    public static function academy(): array
    {
        return [
            'courses' => array_map(static fn (array $p): array => [
                'name' => (string) $p['name'],
                'url'  => $p['public_url'],
                'what' => $p['summary'],
            ], ProductCatalog::academy()),
            'rules' => self::ACADEMY_RULES,
        ];
    }
}
