<?php

namespace App\Libraries;

use App\Libraries\Publishing\Jobs\PublicationRollbackService;
use App\Models\Content\ContentItemModel;

/**
 * Permanent deletion of a content item — blog, knowledge base article, or the
 * body behind a community answer — together with everything hanging off it.
 *
 * This is deliberately separate from ContentItemService::archive(), which only
 * flips workflow_status and keeps the row for audit. Purging is what the Reach
 * panel offers for content that must genuinely disappear (abandoned drafts,
 * mis-generated articles), including from AICOUNTLY.com.
 *
 * Order of operations matters:
 *   1. Take the item down from the public site. An item is never removed from
 *      Reach while its article is still live, otherwise nothing is left to
 *      unpublish it with. `$force` skips this only for operators who accept
 *      that the public copy has to be removed by hand.
 *   2. Delete the child rows whose foreign keys are RESTRICT/NO ACTION, since
 *      Postgres will not cascade those for us.
 *   3. Delete the item, letting the CASCADE keys take the rest (versions,
 *      briefs, comments, validations, SEO/AEO profiles, type details, ...).
 */
class ContentPurgeService
{
    /** Deployment states that still render on the public site. */
    private const LIVE_DEPLOYMENT_STATUSES = ['published', 'verified', 'accepted', 'rolled_back'];

    /**
     * Tables that point at a content item through a plain column (no FK), so a
     * delete would leave them pointing at a row that no longer exists.
     */
    private const DANGLING_POINTER_TABLES = [
        'reach_ai_usage_ledger',
        'reach_work_blocks',
        'reach_topic_candidates',
        'reach_roadmap_decisions',
        'reach_link_registry',
    ];

    private \CodeIgniter\Database\BaseConnection $db;
    private ContentItemModel $items;
    private AuditLogger $audit;
    private PublicationRollbackService $rollback;

    public function __construct(?PublicationRollbackService $rollback = null)
    {
        $this->db       = \Config\Database::connect();
        $this->items    = new ContentItemModel();
        $this->audit    = new AuditLogger();
        $this->rollback = $rollback ?? new PublicationRollbackService();
    }

    /**
     * @param  array $actor ['id' => int|null, ...]
     * @return array{deleted: bool, unpublished_deployments: int}
     * @throws \RuntimeException when the item is missing, still live, or the delete fails
     */
    public function purge(int $id, string $reason, array $actor = [], bool $force = false): array
    {
        $item = $this->items->withDeleted()->find($id);
        if (!$item) {
            throw new \RuntimeException("Content item {$id} not found.");
        }

        $unpublished = $this->takeDownPublicCopies($id, $reason, $actor, $force);

        $this->db->transStart();

        // reach_ai_validation_runs.content_item_id is NOT NULL with no cascade,
        // and its findings hang off the run with no cascade either.
        $this->db->query(
            'DELETE FROM reach_ai_validation_findings WHERE validation_run_id IN '
            . '(SELECT id FROM reach_ai_validation_runs WHERE content_item_id = ?)',
            [$id]
        );
        $this->db->table('reach_ai_validation_runs')->where('content_item_id', $id)->delete();

        // Similarity is a pair: drop the rows about this item, unlink the rest.
        $this->db->table('reach_content_similarity_records')->where('content_item_id', $id)->delete();
        $this->db->table('reach_content_similarity_records')
            ->where('compared_item_id', $id)
            ->update(['compared_item_id' => null]);

        // ON DELETE RESTRICT, and it also pins the versions we are about to
        // cascade away through content_version_id.
        $this->db->table('reach_publication_deployments')->where('content_item_id', $id)->delete();

        // The AI spend ledger outlives the content it was spent on — keep the
        // request rows (their runs and artifacts reference them) and unlink.
        $this->db->table('reach_ai_generation_requests')
            ->where('content_item_id', $id)
            ->update(['content_item_id' => null]);

        foreach (self::DANGLING_POINTER_TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->where('content_item_id', $id)->update(['content_item_id' => null]);
            }
        }

        // Legacy migration bookkeeping has a NOT NULL pointer and no value
        // once the item is gone.
        if ($this->db->tableExists('reach_blog_legacy_migration_map')) {
            $this->db->table('reach_blog_legacy_migration_map')->where('content_item_id', $id)->delete();
        }

        $this->items->delete($id, true);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Could not delete the content item — the database rejected the change.');
        }

        $this->audit->log(
            userId:     $actor['id'] ?? null,
            action:     AuditLogger::CONTENT_DELETED,
            entityType: 'content',
            entityId:   $id,
            oldValue:   [
                'content_type'    => $item['content_type'] ?? null,
                'title'           => $item['title'] ?? null,
                'slug'            => $item['slug'] ?? null,
                'workflow_status' => $item['workflow_status'] ?? null,
            ],
            extra:      ['unpublished_deployments' => $unpublished, 'forced' => $force],
            actorType:  $actor['type'] ?? 'human',
            actorService: $actor['service'] ?? 'reach:api',
            reason:     $reason,
        );

        return ['deleted' => true, 'unpublished_deployments' => $unpublished];
    }

    /**
     * Unpublish every deployment that is still live for this item.
     *
     * @return int number of deployments taken down
     * @throws \RuntimeException when a takedown fails and $force is false
     */
    private function takeDownPublicCopies(int $id, string $reason, array $actor, bool $force): int
    {
        $deployments = $this->db->table('reach_publication_deployments')
            ->where('content_item_id', $id)
            ->whereIn('status', self::LIVE_DEPLOYMENT_STATUSES)
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $count = 0;
        foreach ($deployments as $deployment) {
            try {
                $ok = $this->rollback->unpublish(
                    (int) $deployment['id'],
                    $reason !== '' ? $reason : 'Deleted from Reach',
                    $actor['id'] ?? null
                );
            } catch (\Throwable $e) {
                $ok = false;
                log_message('error', 'ContentPurgeService takedown failed: ' . $e->getMessage());
            }

            if ($ok) {
                $count++;
                continue;
            }

            if (!$force) {
                throw new \RuntimeException(
                    'This item is still live on AICOUNTLY.com and the takedown failed. '
                    . 'Unpublish it first, or delete with force to remove it from Reach only.'
                );
            }
        }

        return $count;
    }
}
