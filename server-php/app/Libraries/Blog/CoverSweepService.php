<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Releases articles parked for want of a cover.
 *
 * An article with no suitable cover is parked in the review queue and its
 * cover work block completes — deliberately, so a coverless article cannot
 * walk to publication. But nothing re-attempted it afterwards: the only thing
 * that creates a cover block is the state machine's fact_verified successor,
 * and a parked article is past that. Uploading covers therefore fixed the
 * gallery and left the queue exactly where it was.
 *
 * This sweep closes that loop. It re-enqueues the cover block for every parked
 * article, and the block itself decides the outcome — if a matching cover now
 * exists it is assigned, the cover_image validation flips to passed, and the
 * pipeline resumes; if not, the article simply stays parked.
 *
 * Idempotent per article per day: a retry key of blog-<id>-cover-retry-<Ymd>
 * means an hourly sweep re-attempts a stubborn article once a day rather than
 * accumulating twenty-four dead work blocks against it.
 */
class CoverSweepService
{
    /** States where a cover no longer matters — the article is done or abandoned. */
    private const TERMINAL = ['published', 'live', 'archived', 'rejected', 'unpublished', 'unpublish_queued'];

    private BaseConnection $db;
    private WorkBlockService $workBlocks;

    public function __construct(?BaseConnection $db = null, ?WorkBlockService $workBlocks = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->workBlocks = $workBlocks ?? new WorkBlockService();
    }

    /**
     * @return array{scanned:int, requeued:int, skipped:int, content_item_ids:list<int>}
     */
    public function sweep(int $limit = 25): array
    {
        $parked   = $this->parkedItems(max(1, $limit));
        $requeued = 0;
        $skipped  = 0;
        $ids      = [];

        foreach ($parked as $item) {
            $contentItemId = (int) $item['id'];
            $key           = sprintf('blog-%d-cover-retry-%s', $contentItemId, date('Ymd'));

            $existing = $this->db->table('reach_work_blocks')
                ->where('idempotency_key', $key)
                ->get()->getRowArray();

            if ($existing) {
                // Already re-attempted today. Re-arm it if the earlier attempt
                // died mid-flight, or if it finished without a cover and a new
                // gallery cover has landed since (the hourly autofill stocks
                // covers through the day — a morning miss must not wait for
                // tomorrow's key). Leave a pending/queued one alone.
                $status = (string) $existing['eligibility_status'];
                $rearm  = in_array($status, ['failed', 'blocked'], true)
                    || ($status === 'completed' && $this->galleryChangedSince((string) ($existing['updated_at'] ?? '')));
                if ($rearm) {
                    $this->workBlocks->markEligible((int) $existing['id']);
                    $requeued++;
                    $ids[] = $contentItemId;
                } else {
                    $skipped++;
                }

                continue;
            }

            $this->workBlocks->create([
                'block_type'         => WorkBlockService::TYPE_GENERATE_IMAGE,
                'scope'              => 'blog',
                'content_item_id'    => $contentItemId,
                'content_version_id' => $item['current_version_id'] !== null ? (int) $item['current_version_id'] : null,
                'idempotency_key'    => $key,
                'eligibility_status' => 'eligible',
            ]);

            $requeued++;
            $ids[] = $contentItemId;
        }

        return [
            'scanned'          => count($parked),
            'requeued'         => $requeued,
            'skipped'          => $skipped,
            'content_item_ids' => $ids,
        ];
    }

    /** True when an active gallery cover was added after $since. */
    private function galleryChangedSince(string $since): bool
    {
        if ($since === '') {
            return false;
        }

        return $this->db->table('reach_media_gallery_assets')
            ->where('status', 'active')
            ->whereIn('kind', \App\Libraries\Media\MediaGalleryAssignmentService::GALLERY_KINDS)
            ->where('created_at >', $since)
            ->countAllResults() > 0;
    }

    /**
     * Blog items carrying an unwaived cover_image failure and still without a
     * featured image.
     *
     * The waiver is honoured here too: an article a superadmin decided may go
     * out without a hero must not be dragged back through the cover step.
     *
     * @return list<array<string,mixed>>
     */
    private function parkedItems(int $limit): array
    {
        $terminal = "'" . implode("','", self::TERMINAL) . "'";
        // Vetted Claude-routine blogs retry their own cover hourly through
        // RoutineContentPipeline (relevant gallery cover first, then a fresh
        // GPT Image cover), and a
        // GENERATE_IMAGE block would walk them back into the review chain.
        $routine = \App\Libraries\Database\SchemaGuard::hasTable($this->db, 'reach_routine_submissions')
            ? 'AND NOT EXISTS (SELECT 1 FROM reach_routine_submissions rs WHERE rs.content_item_id = i.id)'
            : '';

        return $this->db->query(
            "SELECT i.id, i.current_version_id
             FROM reach_content_items i
             JOIN reach_content_validations v
               ON v.content_item_id = i.id
              AND v.validation_type = 'cover_image'
              AND v.validation_status = 'failed'
              AND v.waived_at IS NULL
             LEFT JOIN reach_blog_publication_profiles p ON p.content_item_id = i.id
             WHERE i.content_type = 'blog'
               AND i.deleted_at IS NULL
               AND i.workflow_status NOT IN ({$terminal})
               AND COALESCE(p.featured_image_reference, '') = ''
               {$routine}
             ORDER BY i.id ASC
             LIMIT ?",
            [$limit]
        )->getResultArray();
    }
}
