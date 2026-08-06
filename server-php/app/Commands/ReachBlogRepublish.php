<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\ContentIdentitySyncService;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-republish --id=N` — re-deploy one already-published
 * post, typically to move it onto a repaired slug.
 *
 * Publishing is otherwise only reachable through an authenticated API call
 * (POST /publishing/content/{id}/publish), which is awkward from a shell and
 * offers no preview. This shows exactly what would happen — which URL the post
 * is on now, which one it would move to, and whether a redirect covering that
 * move exists — and refuses to act without --apply.
 *
 * It deliberately handles one item at a time. Moving a live URL is not
 * reversible by re-running the command, so a bulk flag would be a footgun.
 */
class ReachBlogRepublish extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:blog-republish';
    protected $description = 'Re-publish one blog post (e.g. onto a repaired slug). Dry run without --apply.';
    protected $usage       = 'reach:blog-republish --id=N [--apply]';
    protected $options     = [
        '--id'    => 'Content item id to re-publish. Required.',
        '--apply' => 'Actually enqueue the publication. Without it, nothing is written.',
    ];

    private const LIVE_STATES = ['published', 'verification_pending', 'verified'];

    public function run(array $params): int
    {
        $id    = (int) ($this->sparkOption('id', $params, '0') ?? '0');
        $apply = $this->sparkFlag('apply', $params);

        if ($id <= 0) {
            CLI::error('--id is required, e.g. reach:blog-republish --id=7');

            return EXIT_ERROR;
        }

        try {
            $plan = $this->plan($id);
            if (isset($plan['error'])) {
                CLI::error($plan['error']);

                return EXIT_ERROR;
            }

            $result = ['action' => 'blog-republish', 'ts' => gmdate('c'), 'plan' => $plan];

            if (! $apply) {
                $result['applied'] = false;
                $result['next']    = 'Re-run with --apply to enqueue, then run the publishing worker: '
                    . 'php spark reach:work --queue=publishing --once --limit=5';
                CLI::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return EXIT_SUCCESS;
            }

            $deploymentId = (new PublicationDeploymentService())->enqueuePublication(
                $id,
                (int) $plan['content_version_id'],
                'aicountly_com',
                'publish',
                null,
                null,
            );

            $result['applied']       = true;
            $result['deployment_id'] = $deploymentId;
            $result['next']          = 'php spark reach:work --queue=publishing --once --limit=5, then '
                . 'php spark reach:blog-url-drift --probe --id=' . $id;

            CLI::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('reach:blog-republish failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(int $id): array
    {
        $db = Database::connect();

        $item = $db->table('reach_content_items')
            ->select('id, title, slug, content_type, workflow_status, approval_status, current_version_id')
            ->where('id', $id)
            ->get()->getRowArray();

        if (! $item) {
            return ['error' => "Content item {$id} not found."];
        }
        if (($item['content_type'] ?? '') !== 'blog') {
            return ['error' => "Content item {$id} is not a blog."];
        }
        // enqueuePublication rejects this too, but failing here says why before
        // anything is queued.
        if (($item['approval_status'] ?? '') !== 'approved') {
            return ['error' => "Content item {$id} is not approved (approval_status="
                . ($item['approval_status'] ?? 'null') . '); publishing would be refused.'];
        }

        $versionId = (int) ($item['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['error' => "Content item {$id} has no current version to publish."];
        }

        $deployment = $db->table('reach_publication_deployments')
            ->select('canonical_url')
            ->where('content_item_id', $id)
            ->whereIn('status', self::LIVE_STATES)
            ->where('canonical_url IS NOT NULL', null, false)
            ->orderBy('completed_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        $liveUrl     = trim((string) ($deployment['canonical_url'] ?? ''));
        $publishSlug = $this->publishSlug($id, (string) $item['slug']);
        $targetUrl   = $liveUrl !== ''
            ? ContentIdentitySyncService::withLastSegment($liveUrl, $publishSlug)
            : null;

        $redirects = [];
        if (SchemaGuard::hasTable($db, 'reach_publication_redirects')) {
            $redirects = $db->table('reach_publication_redirects')
                ->select('from_slug, to_slug, status')
                ->where('content_item_id', $id)
                ->whereIn('status', ['pending', 'active'])
                ->get()->getResultArray();
        }

        $emitting = filter_var(env('BLOG_PUBLISH_REDIRECTS_ENABLED', false), FILTER_VALIDATE_BOOL);

        return [
            'recent_deployments' => $this->recentDeployments($id),
            'content_item_id'    => $id,
            'title'              => $item['title'],
            'workflow_status'    => $item['workflow_status'],
            'content_version_id' => $versionId,
            'live_url'           => $liveUrl ?: null,
            'publish_slug'       => $publishSlug,
            'target_url'         => $targetUrl,
            'url_changes'        => $targetUrl !== null && $targetUrl !== $liveUrl,
            'redirects_on_file'  => $redirects,
            'redirect_emission_enabled' => $emitting,
            'warning' => match (true) {
                $targetUrl === null || $targetUrl === $liveUrl => null,
                $redirects === [] => 'The URL will change and NO redirect is recorded — the current URL '
                    . 'will 404. Run reach:blog-url-drift --record-redirects first.',
                ! $emitting => 'The URL will change and redirects are recorded but NOT sent: '
                    . 'BLOG_PUBLISH_REDIRECTS_ENABLED is false, so the public site will not be told about '
                    . 'them and the current URL will 404.',
                default => 'The URL will change. Redirects are recorded and will be sent, but whether the '
                    . 'public site honours them is unverified — probe the old URL immediately afterwards.',
            },
        ];
    }

    /**
     * The item's last few deployments, with why any of them failed.
     *
     * A deployment that fails leaves the previous successful one as the live
     * record, so the drift report keeps showing the old URL and the re-publish
     * looks like it silently did nothing. The failure reason is the only thing
     * that distinguishes "never ran" from "ran and was rejected".
     *
     * @return list<array<string, mixed>>
     */
    private function recentDeployments(int $contentItemId): array
    {
        $db = Database::connect();

        $rows = $db->table('reach_publication_deployments')
            ->select('id, operation, status, canonical_url, error_category, redacted_error, attempt_count, created_at, completed_at')
            ->where('content_item_id', $contentItemId)
            ->orderBy('id', 'DESC')
            ->limit(5)
            ->get()->getResultArray();

        if ($rows === [] || ! SchemaGuard::hasTable($db, 'reach_jobs')) {
            return $rows;
        }

        // A deployment stuck at "queued" with no job row was never dispatched;
        // one with a failed job row was. They need different fixes.
        foreach ($rows as &$row) {
            // payload_json is JSONB: `LIKE` against it errors in Postgres
            // ("operator does not exist: jsonb ~~ unknown"), so match the
            // extracted field rather than the document.
            $job = $db->query(
                "SELECT id, status, attempts, max_attempts, error_message, available_at, reserved_at
                 FROM reach_jobs
                 WHERE (payload_json->>'deployment_id') = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [(string) $row['id']]
            )->getRowArray();

            $row['job'] = $job ?: null;
        }

        return $rows;
    }

    private function publishSlug(int $contentItemId, string $fallback): string
    {
        $db = Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_content_seo_profiles')) {
            return $fallback;
        }

        $seo = $db->table('reach_content_seo_profiles')
            ->select('slug')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        $slug = trim((string) ($seo['slug'] ?? ''));

        return $slug !== '' ? $slug : $fallback;
    }
}
