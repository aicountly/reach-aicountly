<?php

namespace App\Commands;

use App\Libraries\Content\SlugBuilder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;
use App\Libraries\Database\SchemaGuard;

/**
 * `php spark reach:blog-repair-slugs` — repair slugs mangled by the
 * case-sensitive slug filter.
 *
 * The old builders ran `preg_replace('/[^a-z0-9]+/', '-', $title)` before
 * lowercasing, so every capital letter became a separator: "TDS Compliance
 * Basics for Growing Companies" was stored as
 * "ompliance-asics-for-rowing-ompanies". This walks reach_content_items,
 * rewrites only the slugs that are exactly what the broken builder would have
 * produced (never a hand-edited one), keeps them unique, and records a
 * redirect row for anything already published.
 *
 * Dry run by default. Nothing is written without --apply.
 */
class ReachBlogRepairSlugs extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-repair-slugs';
    protected $description = 'Repair content slugs corrupted by the case-sensitive slug filter.';
    protected $usage       = 'reach:blog-repair-slugs [--apply] [--type blog] [--limit 0]';
    protected $options     = [
        '--apply' => 'Write the repaired slugs. Without it the command only reports.',
        '--type'  => 'Restrict to one content_type (default: all).',
        '--limit' => 'Stop after N repairs (default: 0 = no limit).',
    ];

    /** Workflow states where the slug is already part of a public URL. */
    private const PUBLISHED_STATES = ['published', 'live', 'verification_pending'];

    public function run(array $params): int
    {
        // CLI::getOption() only sees real argv; the command() helper hands
        // flags over in $params, so both have to be consulted or the write
        // flag silently reads as "dry run".
        $apply = $this->sparkFlag('apply', $params);
        $type  = (string) ($this->sparkOption('type', $params, '') ?? '');
        $limit = max(0, (int) ($this->sparkOption('limit', $params, '0') ?? '0'));

        $db = Database::connect();

        try {
            if (! SchemaGuard::hasTable($db, 'reach_content_items')) {
                CLI::error('reach_content_items does not exist.');

                return EXIT_ERROR;
            }

            $builder = $db->table('reach_content_items')
                ->select('id, title, slug, content_type, workflow_status')
                ->where('slug IS NOT NULL', null, false)
                ->orderBy('id', 'ASC');

            if ($type !== '') {
                $builder->where('content_type', $type);
            }

            $rows = $builder->get()->getResultArray();

            // Every slug currently in use, so repairs cannot collide with an
            // untouched row or with each other.
            $taken = [];
            foreach ($db->table('reach_content_items')->select('slug')->get()->getResultArray() as $row) {
                if (($row['slug'] ?? '') !== '') {
                    $taken[$row['slug']] = true;
                }
            }

            $repairs = [];
            foreach ($rows as $row) {
                $title = (string) ($row['title'] ?? '');
                $slug  = (string) ($row['slug'] ?? '');

                if (! SlugBuilder::isCorrupted($slug, $title)) {
                    continue;
                }

                $target = $this->uniqueSlug(SlugBuilder::slug($title, 'untitled'), $slug, $taken);
                unset($taken[$slug]);
                $taken[$target] = true;

                $repairs[] = [
                    'id'          => (int) $row['id'],
                    'type'        => (string) ($row['content_type'] ?? ''),
                    'status'      => (string) ($row['workflow_status'] ?? ''),
                    'title'       => $title,
                    'from'        => $slug,
                    'to'          => $target,
                    'is_public'   => in_array((string) ($row['workflow_status'] ?? ''), self::PUBLISHED_STATES, true),
                ];

                if ($limit > 0 && count($repairs) >= $limit) {
                    break;
                }
            }

            if ($repairs === []) {
                CLI::write('No corrupted slugs found.', 'green');
            } else {
                $this->report($repairs, $apply);
            }

            if (! $apply) {
                // The reconciliation pass reads current slugs, so it reports
                // truthfully whether or not repairs happen in this run.
                $this->reportLinkFallout($db, false);
                CLI::newLine();
                CLI::write('Dry run — nothing written. Re-run with --apply to commit.', 'yellow');

                return EXIT_SUCCESS;
            }

            $written   = 0;
            $redirects = 0;

            foreach ($repairs as $repair) {
                $db->transStart();

                $db->table('reach_content_items')
                    ->where('id', $repair['id'])
                    ->update(['slug' => $repair['to'], 'updated_at' => date('Y-m-d H:i:s')]);

                // The SEO profile carries its own slug and wins in the publish
                // payload, so a repair that skips it changes nothing publicly.
                if (SchemaGuard::hasTable($db, 'reach_content_seo_profiles')) {
                    $db->table('reach_content_seo_profiles')
                        ->where('content_item_id', $repair['id'])
                        ->where('slug', $repair['from'])
                        ->update(['slug' => $repair['to']]);
                }

                if ($repair['is_public'] && SchemaGuard::hasTable($db, 'reach_publication_redirects')) {
                    $db->table('reach_publication_redirects')->insert([
                        'content_item_id' => $repair['id'],
                        'from_slug'       => $repair['from'],
                        'to_slug'         => $repair['to'],
                        'redirect_type'   => 301,
                        'reason'          => 'slug_repair_case_sensitive_filter',
                        'status'          => 'pending',
                    ]);
                    $redirects++;
                }

                $db->transComplete();

                if ($db->transStatus() === false) {
                    CLI::error(sprintf('Failed to repair content item #%d — rolled back.', $repair['id']));

                    return EXIT_ERROR;
                }

                $written++;
            }

            CLI::newLine();
            CLI::write(sprintf('Repaired %d slug(s); recorded %d redirect(s).', $written, $redirects), 'green');

            // Must run even when this pass repaired nothing: an earlier --apply
            // moved the slugs on, leaving the registry pointing at URLs that no
            // longer belong to anything.
            $this->reportLinkFallout($db, true);

            if ($redirects > 0) {
                CLI::write(
                    'Redirect rows are recorded as "pending". Nothing in this repo serves '
                    . 'reach_publication_redirects yet, so the public site still needs to be '
                    . 'told about them before the old URLs will resolve.',
                    'yellow',
                );
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('reach:blog-repair-slugs failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * Reconcile everything else that stores a slug-derived URL.
     *
     * Repairing reach_content_items.slug is not the whole job. The internal
     * link registry holds `/blogs/<slug>` per published item, and
     * BlogPublicationPayloadBuilder feeds those paths straight into the
     * "Related reading" block of every article it publishes next — so a stale
     * registry means new posts ship with links to URLs that no longer belong
     * to anything.
     *
     * Reconciliation is by content_item_id against the item's current slug, so
     * this is re-runnable and also repairs a database where the slugs were
     * moved by an earlier --apply.
     */
    private function reportLinkFallout($db, bool $apply): void
    {
        if (! SchemaGuard::hasTable($db, 'reach_link_registry')) {
            return;
        }

        $rows = $db->table('reach_link_registry')
            ->select(
                'reach_link_registry.id, reach_link_registry.url_path, reach_link_registry.link_type, '
                . 'reach_content_items.slug, reach_content_items.content_type'
            )
            ->join(
                'reach_content_items',
                'reach_content_items.id = reach_link_registry.content_item_id',
                'inner',
            )
            ->whereIn('reach_link_registry.link_type', ['blog', 'knowledge_base'])
            ->get()
            ->getResultArray();

        $stale = [];
        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $prefix   = ($row['content_type'] ?? 'blog') === 'knowledge_base' ? '/help/' : '/blogs/';
            $expected = $prefix . rawurlencode($slug);

            if ($expected !== ($row['url_path'] ?? '')) {
                $stale[] = ['id' => (int) $row['id'], 'from' => (string) $row['url_path'], 'to' => $expected];
            }
        }

        if ($stale === []) {
            return;
        }

        CLI::newLine();
        CLI::write(sprintf(
            '%s %d internal-link registry path(s) left pointing at the old URLs:',
            $apply ? 'Reconciling' : 'Found',
            count($stale),
        ), $apply ? 'green' : 'yellow');
        CLI::table(
            array_map(static fn ($s) => [$s['id'], $s['from'], $s['to']], $stale),
            ['#', 'from', 'to'],
        );

        if (! $apply) {
            return;
        }

        foreach ($stale as $row) {
            // url_path is UNIQUE. If a republish already inserted the correct
            // path, the stale row is a duplicate — retire it rather than
            // collide, so the registry keeps exactly one live path per item.
            $clash = $db->table('reach_link_registry')
                ->where('url_path', $row['to'])
                ->where('id !=', $row['id'])
                ->countAllResults() > 0;

            if ($clash) {
                $db->table('reach_link_registry')
                    ->where('id', $row['id'])
                    ->update(['status' => 'retired', 'updated_at' => date('Y-m-d H:i:s')]);

                continue;
            }

            $db->table('reach_link_registry')
                ->where('id', $row['id'])
                ->update(['url_path' => $row['to'], 'updated_at' => date('Y-m-d H:i:s')]);
        }

        CLI::write('Registry reconciled — newly published posts will link to the repaired URLs.', 'green');
        CLI::write(
            'Bodies already published still contain the old links; those resolve only once '
            . 'the redirects are live on the public site.',
            'yellow',
        );
    }

    /**
     * @param array<string, bool> $taken
     */
    private function uniqueSlug(string $base, string $current, array $taken): string
    {
        if (! isset($taken[$base]) || $base === $current) {
            return $base;
        }

        $i = 1;
        while (isset($taken[$base . '-' . $i])) {
            $i++;
        }

        return $base . '-' . $i;
    }

    /**
     * @param list<array<string, mixed>> $repairs
     */
    private function report(array $repairs, bool $apply): void
    {
        CLI::write(sprintf('%s %d corrupted slug(s):', $apply ? 'Repairing' : 'Found', count($repairs)));
        CLI::newLine();

        $rows = [];
        foreach ($repairs as $repair) {
            $rows[] = [
                $repair['id'],
                $repair['type'],
                $repair['status'],
                $repair['from'],
                $repair['to'],
                $repair['is_public'] ? 'YES' : '',
            ];
        }

        CLI::table($rows, ['#', 'type', 'state', 'from', 'to', 'live URL']);

        $public = array_filter($repairs, static fn ($r) => $r['is_public']);
        if ($public !== []) {
            CLI::newLine();
            CLI::write(
                sprintf('%d of these are already published — their public URLs change.', count($public)),
                'yellow',
            );
        }
    }
}
