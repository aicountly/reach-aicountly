<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Blog\BlogRedraftService;
use App\Libraries\Publishing\Blog\BlogMetadataService;
use App\Libraries\Publishing\Blog\PublishableContentGuard;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-listing-audit` — audit what aicountly.com/blogs is
 * actually showing.
 *
 * Reports every blog item that is published (or queued to publish) but would
 * render as a fake card on the public listing: a placeholder body, an excerpt
 * that is not real prose, or a missing cover image. With --fix, placeholder
 * bodies are re-queued for a genuine draft via BlogRedraftService.
 *
 *   php spark reach:blog-listing-audit
 *   php spark reach:blog-listing-audit --fix --limit=20
 */
class ReachBlogListingAudit extends BaseCommand
{
    use ParsesSparkOptions;

    /** Item states whose content is either already public or about to be. */
    private const PUBLIC_STATES = [
        'approved', 'scheduled', 'publish_queued', 'publishing',
        'published', 'verification_pending', 'live',
    ];

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-listing-audit';
    protected $description = 'Audit published blogs for placeholder bodies, unusable excerpts and missing covers.';
    protected $usage       = 'reach:blog-listing-audit [--fix] [--limit=50] [--all]';
    protected $options     = [
        '--fix'   => 'Re-queue a genuine draft for every placeholder body found.',
        '--limit' => 'Max items to scan (default 50).',
        '--all'   => 'Scan every blog item, not just published/queued ones.',
    ];

    public function run(array $params): int
    {
        $fix   = $this->sparkFlag('fix', $params);
        $all   = $this->sparkFlag('all', $params);
        $limit = max(1, (int) ($this->sparkOption('limit', $params, '50') ?? '50'));

        $db       = Database::connect();
        $guard    = new PublishableContentGuard();
        $metadata = new BlogMetadataService();

        $builder = $db->table('reach_content_items i')
            ->select('i.id, i.title, i.slug, i.workflow_status, i.current_version_id')
            ->where('i.content_type', 'blog')
            ->where('i.deleted_at IS NULL', null, false)
            ->orderBy('i.id', 'DESC')
            ->limit($limit);

        if (! $all) {
            $builder->whereIn('i.workflow_status', self::PUBLIC_STATES);
        }

        $items    = $builder->get()->getResultArray();
        $findings = [];

        foreach ($items as $item) {
            $problems = [];

            $version = ((int) ($item['current_version_id'] ?? 0)) > 0
                ? $db->table('reach_content_versions')->where('id', (int) $item['current_version_id'])->get()->getRowArray()
                : null;

            $bodyHtml = (string) ($version['body_html'] ?? '');
            $verdict  = $guard->assessBody($bodyHtml, (string) ($item['title'] ?? ''));
            $stubBody = ! $verdict['publishable'];

            foreach ($verdict['reasons'] as $reason) {
                $problems[] = $reason;
            }

            $profile = $db->table('reach_blog_publication_profiles')
                ->where('content_item_id', (int) $item['id'])
                ->get()->getRowArray() ?? [];

            $excerpt = $guard->firstUsableExcerpt([
                $profile['excerpt'] ?? null,
                $version['summary'] ?? null,
                $metadata->deriveExcerpt($bodyHtml),
            ]);
            if ($excerpt === '') {
                $problems[] = 'No usable excerpt — the listing card would show placeholder text';
            }

            if (trim((string) ($profile['featured_image_reference'] ?? '')) === '') {
                $problems[] = 'No featured image — the listing card would render without a cover';
            } elseif (trim((string) ($profile['featured_image_alt'] ?? '')) === '') {
                $problems[] = 'Featured image has no alt text';
            }

            if ($problems === []) {
                continue;
            }

            $finding = [
                'content_item_id' => (int) $item['id'],
                'title'           => $item['title'] ?? null,
                'slug'            => $item['slug'] ?? null,
                'workflow_status' => $item['workflow_status'] ?? null,
                'words'           => $verdict['words'],
                'problems'        => $problems,
            ];

            if ($fix && $stubBody) {
                try {
                    $result            = (new BlogRedraftService())->redraft((int) $item['id']);
                    $finding['action'] = 'redraft_queued';
                    $finding['work_block_id'] = $result['work_block_id'];
                } catch (Throwable $e) {
                    $finding['action'] = 'redraft_failed';
                    $finding['error']  = $e->getMessage();
                }
            }

            $findings[] = $finding;
        }

        CLI::write(json_encode([
            'event'    => 'blog_listing_audit.completed',
            'ts'       => gmdate('c'),
            'scanned'  => count($items),
            'flagged'  => count($findings),
            'fixed'    => count(array_filter($findings, static fn (array $f): bool => ($f['action'] ?? '') === 'redraft_queued')),
            'findings' => $findings,
            'next'     => $findings === [] ? [] : [
                'php spark reach:blog-listing-audit --fix',
                'php spark reach:blog-dispatch --force',
                'php spark reach:work --queue blog,publishing --limit 40',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
