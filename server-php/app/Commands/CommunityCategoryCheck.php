<?php

namespace App\Commands;

use App\Libraries\Community\CommunityPublicRecordProvisioner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark community:category-check` — report questions whose category will
 * not survive the trip to aicountly.com.
 *
 * Reach and the public site keep separate category vocabularies.
 * CommunityPublicRecordProvisioner bridges the known divergences, but a
 * category added on either side silently falls through: the receiver rejects
 * what it does not recognise, the provisioner drops the category, and the
 * question is filed under the public site's default — a GST question landing
 * in whatever sorts first. Nothing fails, so nobody notices.
 *
 * This makes that visible before publishing rather than after. It is
 * deliberately read-only: which slug a question *should* map to is an
 * editorial decision, not one to guess at from a cron job.
 */
class CommunityCategoryCheck extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'community:category-check';
    protected $description = 'Report community categories that do not resolve to a public-site category slug.';
    protected $usage       = 'community:category-check [--json]';
    protected $options     = [
        '--json' => 'Emit machine-readable JSON instead of a table.',
    ];

    /**
     * Slugs aicountly.com publishes, as seeded by its migration 006/008.
     *
     * Hard-coded because the receiver exposes no category listing — the
     * honest fix is one shared taxonomy or a read endpoint, and until then a
     * list that is checked is better than a mapping that is assumed. If this
     * command reports a category you know exists publicly, this list is what
     * went stale.
     */
    private const PUBLIC_SLUGS = [
        'gst',
        'income-tax',
        'tds-tcs',
        'mca-company-law',
        'audit-accounting',
        'payroll',
        'banking-brs',
        'saas-product-help',
        'technical-api',
    ];

    public function run(array $params): int
    {
        $asJson = array_key_exists('json', $params) || CLI::getOption('json');

        $rows = Database::connect()->query(
            "SELECT COALESCE(NULLIF(TRIM(category), ''), '(none)') AS category,
                    COUNT(*) AS question_count
               FROM reach_community_questions
              GROUP BY 1
              ORDER BY 2 DESC, 1 ASC"
        )->getResultArray();

        $report   = [];
        $unmapped = 0;

        foreach ($rows as $row) {
            $category = (string) $row['category'];
            $count    = (int) $row['question_count'];

            if ($category === '(none)') {
                // No category set: the receiver files these under its default
                // by design, which is a content-entry gap rather than drift.
                $report[] = [
                    'category'   => $category,
                    'questions'  => $count,
                    'maps_to'    => '(public default)',
                    'status'     => 'no-category',
                ];
                continue;
            }

            $mapped = CommunityPublicRecordProvisioner::publicCategoryFor($category);
            $known  = in_array($mapped, self::PUBLIC_SLUGS, true);

            if (! $known) {
                $unmapped += $count;
            }

            $report[] = [
                'category'  => $category,
                'questions' => $count,
                'maps_to'   => $mapped,
                'status'    => $known
                    ? ($mapped === $category ? 'direct' : 'mapped')
                    : 'UNMAPPED',
            ];
        }

        if ($asJson) {
            CLI::write(json_encode([
                'event'              => 'community_category_check',
                'ts'                 => gmdate('c'),
                'categories'         => $report,
                'unmapped_questions' => $unmapped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $unmapped > 0 ? 2 : 0;
        }

        CLI::table(
            array_map(static fn (array $r) => [
                $r['category'],
                (string) $r['questions'],
                $r['maps_to'],
                $r['status'],
            ], $report),
            ['Reach category', 'Questions', 'Publishes as', 'Status']
        );

        if ($unmapped > 0) {
            CLI::error(
                "{$unmapped} question(s) carry a category the public site does not publish. "
                . 'They will be filed under its default category. '
                . 'Add the mapping to CommunityPublicRecordProvisioner::CATEGORY_MAP '
                . 'or correct the category on the questions.'
            );

            return 2;
        }

        CLI::write('Every category in use resolves to a public-site category.', 'green');

        return 0;
    }
}
