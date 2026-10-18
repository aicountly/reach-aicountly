<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\AuditLogger;
use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\ContentValidationService;
use App\Libraries\Publishing\Blog\CoverAltTextBuilder;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * reach:covers-refresh — replace the covers of live posts that cannot be
 * shown to be AICOUNTLY's own, topic-drawn, branded image.
 *
 * A live post needs a new cover when its featured image is
 *   - missing (aicountly.com then shows a random picsum.photos stock photo —
 *     the cyclist / raindrops / pineapple covers on /blogs),
 *   - not a Reach media URL (provenance unknown),
 *   - a Reach media URL whose asset is gone, retired or has no file,
 *   - an operator upload or a legacy generation (provenance is not
 *     aicountly_generated), or
 *   - not branded with the AICOUNTLY mark.
 *
 * Each one gets a fresh GPT Image cover from its own title, summary and
 * headings (never a gallery reuse — the point is a cover drawn for that post),
 * branded, and the post is re-published through the normal publisher with the
 * same content version: only the cover changes. Bounded by
 * covers.auto.daily_limit; whatever does not fit today is reported as deferred
 * and picked up by the next run.
 */
class CoverRefreshService
{
    public const LIVE_STATES = ['published', 'verification_pending', 'live'];

    private BaseConnection $db;
    private CoverGenerator $generator;

    public function __construct(?CoverGenerator $generator = null, ?BaseConnection $db = null, private readonly ?\Closure $republish = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->generator = $generator ?? new CoverGenerator(null, $this->db);
    }

    /**
     * Live blog posts whose cover needs replacing, oldest first.
     *
     * @return list<array{id:int, title:string, workflow_status:string, content_version_id:int, cover_url:string, reason:string}>
     */
    public function candidates(int $limit = 0): array
    {
        $rows = $this->db->query(
            "SELECT i.id, i.title, i.workflow_status, i.current_version_id, COALESCE(p.featured_image_reference, '') AS cover_url
             FROM reach_content_items i
             LEFT JOIN reach_blog_publication_profiles p ON p.content_item_id = i.id
             WHERE i.content_type = 'blog' AND i.deleted_at IS NULL
               AND i.workflow_status IN ('" . implode("','", self::LIVE_STATES) . "')
             ORDER BY i.id ASC"
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $reason = $this->reason((string) $row['cover_url']);
            if ($reason === null) {
                continue;
            }
            $out[] = [
                'id'                 => (int) $row['id'],
                'title'              => (string) $row['title'],
                'workflow_status'    => (string) $row['workflow_status'],
                'content_version_id' => (int) ($row['current_version_id'] ?? 0),
                'cover_url'          => (string) $row['cover_url'],
                'reason'             => $reason,
            ];
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Why this cover cannot stay, or null when it is a branded AICOUNTLY-generated cover. */
    public function reason(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'no_cover';
        }
        if (! preg_match('#/api/v1/public/media/([a-f0-9-]{16,64})\.(?:webp|img|png|jpg)#i', $url, $m)) {
            return 'not_a_reach_cover';
        }
        $asset = $this->db->table('reach_media_gallery_assets')->where('asset_uuid', $m[1])->get()->getRowArray();
        if (! $asset || ($asset['status'] ?? '') !== 'active' || ! is_file((string) ($asset['file_path'] ?? ''))) {
            return 'cover_file_missing';
        }
        if (($asset['provenance'] ?? null) !== 'aicountly_generated') {
            return ($asset['provenance'] ?? null) === 'operator_upload' ? 'operator_upload' : 'unknown_provenance';
        }
        if (! MediaAssetStore::isBranded($asset)) {
            return 'no_logo';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(bool $dryRun, int $limit = 10): array
    {
        $limit      = max(1, $limit);
        $candidates = $this->candidates();
        $remaining  = $this->generator->remainingToday();
        $plan       = array_slice($candidates, 0, $limit);
        $report     = [
            'dry_run'        => $dryRun,
            'found'          => count($candidates),
            'daily_limit'    => CoverGenerator::dailyLimit(),
            'remaining_today'=> $remaining,
            'items'          => [],
        ];

        if (! $dryRun) {
            $flag = RoutineFlags::explain('covers.auto');
            if (! $flag['enabled']) {
                $report['error'] = 'Cover generation is switched off: ' . ($flag['source'] === 'env_kill_switch'
                    ? "{$flag['kill_switch']}=false in the server .env" : 'covers.auto.enabled is off') . '. Nothing was changed.';

                return $report;
            }
            if (! $this->generator->isConfigured()) {
                $report['error'] = 'The image provider is not configured (OpenAI API key). Nothing was changed.';

                return $report;
            }
        }

        $budget = $remaining;
        foreach ($plan as $item) {
            $row = $item + ['action' => null, 'new_cover_url' => null, 'deployment_id' => null, 'error' => null];
            if ($budget <= 0) {
                $row['action'] = 'deferred_daily_cap';
                $report['items'][] = $row;
                continue;
            }
            if ($dryRun) {
                $row['action'] = 'would_regenerate_and_republish';
                $report['items'][] = $row;
                $budget--;
                continue;
            }

            $selection = (new CoverSelectionService($this->generator, $this->db))->coverFor($item['id'], [
                'prefer_gallery' => false,
                'allow_generate' => true,
                'purpose'        => CoverGenerator::PURPOSE_REFRESH,
            ]);
            $budget--;
            if (! $selection['ok']) {
                $row['action'] = 'failed';
                $row['error']  = (string) $selection['reason'];
                $report['items'][] = $row;
                if (str_contains((string) $selection['reason'], 'not configured')) {
                    break;
                }
                continue;
            }

            $this->applyCover($item['id'], $item['content_version_id'], (string) $selection['url']);
            $row['new_cover_url'] = $selection['url'];

            try {
                $row['deployment_id'] = $this->republish !== null
                    ? ($this->republish)($item['id'], $item['content_version_id'])
                    : (new PublicationDeploymentService())->enqueuePublication($item['id'], $item['content_version_id'], 'aicountly_com', 'publish');
                $row['action'] = 'regenerated_and_republish_queued';
            } catch (\Throwable $e) {
                // Honest: the cover is replaced in Reach, but the live post
                // keeps its old image until a publish succeeds.
                $row['action'] = 'regenerated_republish_refused';
                $row['error']  = mb_substr($e->getMessage(), 0, 300);
            }

            try {
                AuditLogger::record('blog.cover_refreshed', [
                    'content_item_id' => $item['id'], 'previous' => $item['cover_url'], 'reason' => $item['reason'],
                    'new_cover_url'   => $selection['url'], 'deployment_id' => $row['deployment_id'], 'error' => $row['error'],
                ]);
            } catch (\Throwable) {
            }
            $report['items'][] = $row;
        }

        $report['deferred'] = max(0, count($candidates) - count($plan)) + count(array_filter($report['items'], static fn ($r) => $r['action'] === 'deferred_daily_cap'));

        return $report;
    }

    private function applyCover(int $contentItemId, int $versionId, string $url): void
    {
        $item    = $this->db->table('reach_content_items')->select('title')->where('id', $contentItemId)->get()->getRowArray() ?? [];
        $profile = $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];
        $alt     = CoverAltTextBuilder::build((string) ($item['title'] ?? ''), (string) ($profile['category'] ?? ''));
        $now     = date('Y-m-d H:i:s');

        if ($profile === []) {
            (new \App\Libraries\Blog\WorkBlockService())->ensurePublicationProfilesForItem($contentItemId);
        }
        $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $contentItemId)->update([
            'featured_image_reference' => mb_substr($url, 0, 512),
            'featured_image_alt'       => mb_substr(trim((string) ($profile['featured_image_alt'] ?? '')) !== '' ? (string) $profile['featured_image_alt'] : $alt, 0, 512),
            'updated_at'               => $now,
        ]);

        try {
            (new ContentValidationService())->storeResult($contentItemId, 'cover_image', 'passed', [
                'version_id' => $versionId > 0 ? $versionId : null,
                'message'    => 'Cover replaced by reach:covers-refresh (GPT Image, AICOUNTLY-branded)',
            ]);
        } catch (\Throwable) {
        }
    }
}
