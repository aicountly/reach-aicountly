<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Blog\BlogRedraftService;
use App\Libraries\Blog\WorkBlockService;
use App\Libraries\JobService;
use App\Libraries\Publishing\Blog\BlogMetadataService;
use App\Libraries\Publishing\Blog\PublishableContentGuard;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Publishing\Jobs\PublicationRollbackService;
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
 * that is not real prose, or a missing cover image.
 *
 * Two remediations, usually run together:
 *   --unpublish  takes the fake article down from aicountly.com now, so
 *                readers stop seeing it while the rewrite is generated.
 *   --fix        re-queues a genuine draft via BlogRedraftService. The
 *                rewritten article still needs human approval before it
 *                republishes — that gate is not bypassed here.
 *
 *   php spark reach:blog-listing-audit
 *   php spark reach:blog-listing-audit --unpublish --fix
 */
class ReachBlogListingAudit extends BaseCommand
{
    use ParsesSparkOptions;

    /** Item states whose content is either already public or about to be. */
    private const PUBLIC_STATES = [
        'approved', 'scheduled', 'publish_queued', 'publishing',
        'published', 'verification_pending', 'live',
    ];

    /**
     * Deployment statuses that mean "this copy is on the public site now".
     * 'rolled_back' counts: a rollback reverts to a prior version but the
     * article stays live.
     */
    private const LIVE_DEPLOYMENT_STATUSES = ['published', 'verified', 'rolled_back'];

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-listing-audit';
    protected $description = 'Audit published blogs for placeholder bodies, unusable excerpts and missing covers.';
    protected $usage       = 'reach:blog-listing-audit [--fix] [--unpublish] [--republish] [--covers] [--limit=50] [--all]';
    protected $options     = [
        '--fix'       => 'Re-queue a genuine draft for every placeholder body found.',
        '--unpublish' => 'Take placeholder articles down from the public site now.',
        '--republish' => 'Push the current approved version for articles whose live copy is stale.',
        '--covers'    => 'Queue cover generation for articles with no featured image.',
        '--limit'     => 'Max items to scan (default 50).',
        '--all'       => 'Scan every blog item, not just published/queued ones.',
    ];

    public function run(array $params): int
    {
        $fix       = $this->sparkFlag('fix', $params);
        $unpublish = $this->sparkFlag('unpublish', $params);
        $republish = $this->sparkFlag('republish', $params);
        $covers    = $this->sparkFlag('covers', $params);
        $all       = $this->sparkFlag('all', $params);
        $limit     = max(1, (int) ($this->sparkOption('limit', $params, '50') ?? '50'));

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

            $noCover = trim((string) ($profile['featured_image_reference'] ?? '')) === '';
            if ($noCover) {
                $problems[] = 'No featured image — the listing card would render without a cover';
            } elseif (trim((string) ($profile['featured_image_alt'] ?? '')) === '') {
                $problems[] = 'Featured image has no alt text';
            }

            // What the public site is actually serving. A healthy row in Reach
            // says nothing about the live copy: if the article was deployed
            // while its body was still a placeholder and never republished
            // after the rewrite, the listing keeps showing the old text.
            $liveCopy = $this->liveCopyState($db, (int) $item['id'], (int) ($item['current_version_id'] ?? 0));
            if ($liveCopy['stale']) {
                $problems[] = sprintf(
                    'Live copy is an older version (deployed version %d, current %d) — the public site still shows the old text',
                    $liveCopy['deployed_version_id'],
                    $liveCopy['current_version_id'],
                );
            } elseif ($liveCopy['deployment_id'] === null && in_array((string) $item['workflow_status'], ['published', 'live', 'verification_pending'], true)) {
                $problems[] = 'Marked published in Reach but has no deployment — the live copy did not come from this connector';
            }

            $mangledSlug = self::mangledSlug((string) ($item['title'] ?? ''), (string) ($item['slug'] ?? ''));
            if ($mangledSlug !== null) {
                $problems[] = sprintf(
                    'Slug lost its capitalised words to a slugify bug (expected "%s") — see "Slug Changes" before rewriting a live URL',
                    $mangledSlug,
                );
            }

            if ($problems === []) {
                continue;
            }

            $finding = [
                'content_item_id' => (int) $item['id'],
                'title'           => $item['title'] ?? null,
                'slug'            => $item['slug'] ?? null,
                'expected_slug'   => $mangledSlug,
                'workflow_status' => $item['workflow_status'] ?? null,
                'words'           => $verdict['words'],
                'live_copy'       => $liveCopy,
                'problems'        => $problems,
            ];

            // Takedown first: a reader looking at the listing right now sees a
            // fake article, and the rewrite takes minutes. Only placeholder
            // bodies are taken down — a real article with a missing cover is
            // not worth pulling off the site.
            if ($unpublish && $stubBody) {
                $finding['takedown'] = $this->unpublishItem($db, (int) $item['id']);
            }

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

            // Push the approved current version to the public site. Only for
            // items whose body is already publishable — a stub gets redrafted,
            // not republished — and enqueuePublication still enforces approval
            // and readiness, so this cannot shortcut either gate.
            if ($republish && ! $stubBody && $liveCopy['stale']) {
                $finding['republish'] = $this->republishItem(
                    (int) $item['id'],
                    (int) ($item['current_version_id'] ?? 0),
                );
            }

            // A cover is generated from the article itself when the gallery has
            // nothing relevant, so this is safe to queue for any real article.
            if ($covers && ! $stubBody && $noCover) {
                $finding['cover'] = $this->queueCover(
                    (int) $item['id'],
                    (int) ($item['current_version_id'] ?? 0),
                );
            }

            $findings[] = $finding;
        }

        $tallied = static fn (string $key, string $value): int => count(array_filter(
            $findings,
            static fn (array $f): bool => ($f[$key] ?? '') === $value,
        ));

        CLI::write(json_encode([
            'event'       => 'blog_listing_audit.completed',
            'ts'          => gmdate('c'),
            'scanned'     => count($items),
            'flagged'     => count($findings),
            'unpublished' => $tallied('takedown', 'unpublished'),
            'fixed'       => $tallied('action', 'redraft_queued'),
            'republished' => $tallied('republish', 'queued'),
            'covers'      => $tallied('cover', 'queued'),
            'findings'    => $findings,
            'next'        => $findings === [] ? [] : [
                'php spark reach:blog-listing-audit --republish --covers   # stale live copies + missing covers',
                'php spark reach:blog-listing-audit --unpublish --fix      # placeholder bodies only',
                'php spark reach:work --queue blog,publishing --limit 40',
                'Approve the rewritten drafts in Reach — republish of a redrafted article stays human-gated.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    /**
     * What the public site is serving for this item, and whether it lags the
     * current version in Reach.
     *
     * @return array{deployment_id:?int, status:?string, public_content_id:?int, deployed_version_id:int, current_version_id:int, stale:bool}
     */
    private function liveCopyState(\CodeIgniter\Database\BaseConnection $db, int $contentItemId, int $currentVersionId): array
    {
        $deployment = $db->table('reach_publication_deployments')
            ->where('content_item_id', $contentItemId)
            ->whereIn('status', self::LIVE_DEPLOYMENT_STATUSES)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        $deployedVersionId = (int) ($deployment['content_version_id'] ?? 0);

        return [
            'deployment_id'       => isset($deployment['id']) ? (int) $deployment['id'] : null,
            'status'              => $deployment['status'] ?? null,
            'public_content_id'   => isset($deployment['public_content_id']) ? (int) $deployment['public_content_id'] : null,
            'deployed_version_id' => $deployedVersionId,
            'current_version_id'  => $currentVersionId,
            'stale'               => $deployedVersionId > 0 && $currentVersionId > 0 && $deployedVersionId !== $currentVersionId,
        ];
    }

    /**
     * The slug this title should have produced, returned only when the stored
     * slug is exactly what the old lowercase-after-strip bug would emit. Being
     * that specific keeps deliberately customised slugs out of the report.
     */
    private static function mangledSlug(string $title, string $slug): ?string
    {
        $title = trim($title);
        if ($title === '' || $slug === '') {
            return null;
        }

        $correct = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? '', '-');
        $buggy   = trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', $title) ?? ''), '-');

        return ($slug === $buggy && $buggy !== $correct) ? $correct : null;
    }

    /**
     * Re-deploy the current version so the public copy matches Reach.
     * enqueuePublication enforces approval and readiness itself.
     */
    private function republishItem(int $contentItemId, int $contentVersionId): string
    {
        if ($contentVersionId <= 0) {
            return 'skipped_no_current_version';
        }

        try {
            (new PublicationDeploymentService(new JobService()))
                ->enqueuePublication($contentItemId, $contentVersionId, 'aicountly_com', 'publish');

            return 'queued';
        } catch (Throwable $e) {
            return 'failed: ' . mb_substr($e->getMessage(), 0, 160);
        }
    }

    /** Queue cover generation for an article that has none. */
    private function queueCover(int $contentItemId, int $contentVersionId): string
    {
        try {
            $workBlocks = new WorkBlockService();
            $blockId    = $workBlocks->create([
                'block_type'         => WorkBlockService::TYPE_GENERATE_IMAGE,
                'scope'              => 'blog',
                'content_item_id'    => $contentItemId,
                'content_version_id' => $contentVersionId > 0 ? $contentVersionId : null,
                'eligibility_status' => 'eligible',
                'priority'           => 5,
                'idempotency_key'    => "blog-{$contentItemId}-listing-audit-cover-" . gmdate('YmdHis'),
                'input_json'         => ['reason' => 'listing_audit_missing_cover'],
            ]);

            return $blockId > 0 ? 'queued' : 'failed: work block not created';
        } catch (Throwable $e) {
            return 'failed: ' . mb_substr($e->getMessage(), 0, 160);
        }
    }

    /**
     * Take one placeholder article off the public site via the deployment
     * connector. Mirrors ContentPublishController::unpublish, including which
     * deployment statuses still count as live.
     */
    private function unpublishItem(\CodeIgniter\Database\BaseConnection $db, int $contentItemId): string
    {
        $deployment = $db->table('reach_publication_deployments')
            ->where('content_item_id', $contentItemId)
            ->whereIn('status', self::LIVE_DEPLOYMENT_STATUSES)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if (! $deployment) {
            // Either never published through Reach, or pushed by the legacy
            // publisher — in both cases the takedown has to happen on the
            // public site itself, so say so rather than reporting success.
            return 'no_active_deployment';
        }

        try {
            $done = (new PublicationRollbackService())->unpublish(
                (int) $deployment['id'],
                'Placeholder body detected by reach:blog-listing-audit',
            );

            return $done ? 'unpublished' : 'unpublish_rejected_by_public_site';
        } catch (Throwable $e) {
            return 'unpublish_failed: ' . mb_substr($e->getMessage(), 0, 120);
        }
    }
}
