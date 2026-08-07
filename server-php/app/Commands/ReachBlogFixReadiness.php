<?php

namespace App\Commands;

use App\Libraries\Blog\WorkBlockService;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Publishing\Blog\BlogReadinessService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-fix-readiness` — create the publication data that
 * blog items past approval are missing.
 *
 * Items can reach publish_queued without ever running SEO_OPTIMIZE, which is
 * the block that creates their SEO and publication profiles. The publish
 * gate then refuses them for the rest of time:
 *
 *   Content is not ready for publication: SEO profile is missing;
 *   Blog publication profile is missing; [SEO] Meta description is missing;
 *   [SEO] Canonical preference is not defined
 *
 * Re-queuing cannot fix that — the missing rows are the problem, not the
 * queue. This fills them in via the same routine the pipeline uses, which
 * only ever writes fields that are absent and never overwrites authored SEO.
 *
 * Dry run by default.
 */
class ReachBlogFixReadiness extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-fix-readiness';
    protected $description = 'Create missing SEO/publication profiles for blog items past approval.';
    protected $usage       = 'reach:blog-fix-readiness [--apply] [--limit 0]';
    protected $options     = [
        '--apply' => 'Write the missing profiles. Without it the command only reports.',
        '--limit' => 'Stop after N items (default: 0 = no limit).',
    ];

    /** States where an item is expected to be publishable. */
    private const PUBLISHABLE_STATES = [
        'approved', 'scheduled', 'ready_for_publication', 'publish_queued', 'publishing',
    ];

    /** A derived description shorter than this is worth a human's attention. */
    private const SHORT_DESCRIPTION = 100;

    public function run(array $params): int
    {
        // CLI::getOption() only sees real argv; command() passes flags in $params.
        $apply = $this->sparkFlag('apply', $params);
        $limit = max(0, (int) ($this->sparkOption('limit', $params, '0') ?? '0'));

        $db = Database::connect();

        try {
            if (! SchemaGuard::hasTable($db, 'reach_content_items')) {
                CLI::error('reach_content_items does not exist.');

                return EXIT_ERROR;
            }

            $candidates = $this->itemsMissingProfiles($db, $limit);

            if ($candidates === []) {
                CLI::write('Every blog item past approval already has its publication data.', 'green');

                return EXIT_SUCCESS;
            }

            CLI::write(sprintf(
                '%s %d blog item(s) missing publication data:',
                $apply ? 'Repairing' : 'Found',
                count($candidates),
            ), $apply ? 'green' : 'yellow');
            CLI::newLine();
            CLI::table(
                array_map(static fn ($c) => [
                    $c['id'],
                    mb_substr($c['title'], 0, 46),
                    $c['workflow_status'],
                    $c['has_seo'] ? '' : 'SEO',
                    $c['has_profile'] ? '' : 'publication',
                ], $candidates),
                ['#', 'title', 'state', 'missing', 'missing'],
            );

            if (! $apply) {
                CLI::newLine();
                CLI::write('Dry run — nothing written. Re-run with --apply to create these.', 'yellow');

                return EXIT_SUCCESS;
            }

            $workBlocks = new WorkBlockService();
            $readiness  = new BlogReadinessService();
            $repaired   = 0;
            $stillBlocked = [];
            $shortCopy    = [];

            foreach ($candidates as $candidate) {
                $workBlocks->ensurePublicationProfilesForItem($candidate['id']);
                $repaired++;

                // Say plainly whether this actually cleared the gate, rather
                // than reporting a write and leaving the item still stuck.
                $result = $readiness->evaluate($candidate['id']);
                if (! ($result['ready'] ?? false)) {
                    $stillBlocked[] = [
                        'id'       => $candidate['id'],
                        'title'    => $candidate['title'],
                        'blocking' => implode('; ', $result['blocking'] ?? []),
                    ];
                }

                $seo = $db->table('reach_content_seo_profiles')
                    ->where('content_item_id', $candidate['id'])->get()->getRowArray() ?? [];
                if (mb_strlen((string) ($seo['meta_description'] ?? '')) < self::SHORT_DESCRIPTION) {
                    $shortCopy[] = [$candidate['id'], mb_substr($candidate['title'], 0, 50)];
                }
            }

            CLI::newLine();
            CLI::write(sprintf('Created publication data for %d item(s).', $repaired), 'green');

            if ($shortCopy !== []) {
                CLI::newLine();
                CLI::write(
                    'These have a derived meta description under ' . self::SHORT_DESCRIPTION
                    . ' characters. It is generated from the summary, not written for search — '
                    . 'worth editing before these go live:',
                    'yellow',
                );
                CLI::table($shortCopy, ['#', 'title']);
            }

            if ($stillBlocked !== []) {
                CLI::newLine();
                CLI::write('Still not publishable — these need more than profile data:', 'yellow');
                CLI::table(
                    array_map(static fn ($b) => [$b['id'], mb_substr($b['title'], 0, 34), mb_substr($b['blocking'], 0, 80)], $stillBlocked),
                    ['#', 'title', 'blocking'],
                );

                return EXIT_SUCCESS;
            }

            CLI::newLine();
            CLI::write('All repaired items now pass the publication gate. Dispatch them with:', 'green');
            CLI::write('  php spark reach:blog-advance --dispatch');
            CLI::write('  php spark reach:work --queue blog,publishing,community,default --limit 20');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('reach:blog-fix-readiness failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * Blog items past approval with no SEO profile or no publication profile.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsMissingProfiles($db, int $limit): array
    {
        $rows = $db->table('reach_content_items')
            ->select('id, title, workflow_status')
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->whereIn('workflow_status', self::PUBLISHABLE_STATES)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $hasSeo     = SchemaGuard::hasTable($db, 'reach_content_seo_profiles');
        $hasProfile = SchemaGuard::hasTable($db, 'reach_blog_publication_profiles');

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $seo = $hasSeo && $db->table('reach_content_seo_profiles')
                ->where('content_item_id', $id)->countAllResults() > 0;
            $profile = $hasProfile && $db->table('reach_blog_publication_profiles')
                ->where('content_item_id', $id)->countAllResults() > 0;

            if ($seo && $profile) {
                continue;
            }

            $out[] = [
                'id'              => $id,
                'title'           => (string) $row['title'],
                'workflow_status' => (string) $row['workflow_status'],
                'has_seo'         => $seo,
                'has_profile'     => $profile,
            ];

            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
