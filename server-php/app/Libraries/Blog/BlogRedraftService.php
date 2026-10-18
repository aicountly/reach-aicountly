<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\Ai\Generation\StructuredOutputCoercer;
use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Ops path: discard stub / placeholder draft bodies and re-queue GENERATE_DRAFT
 * so the AI stack produces a genuine article.
 */
class BlogRedraftService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findStubItems(int $limit = 50): array
    {
        $items = $this->db->table('reach_content_items')
            ->select('id, title, workflow_status, current_version_id, approval_status')
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($items as $item) {
            if ($this->itemHasStubBody($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $item
     */
    public function itemHasStubBody(array $item): bool
    {
        $versionId = (int) ($item['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            return true;
        }

        $version = $this->db->table('reach_content_versions')
            ->where('id', $versionId)
            ->get()
            ->getRowArray();

        if (! $version) {
            return true;
        }

        return StructuredOutputCoercer::isStubBody(
            $version['body_html'] ?? null,
            $version['body_markdown'] ?? null,
            $version['body_plain_text'] ?? null,
            $version['title'] ?? ($item['title'] ?? null),
        );
    }

    /**
     * Queue a same-slug rewrite by the Claude blog routine
     * (RegenerationQueue → GET v1/automation/regenerate/next). Reach's own
     * GENERATE_DRAFT step is retired: it wrote the off-topic posts this
     * replaces. The approval is revoked so nothing publishes the stub
     * meanwhile; a live post stays where it is until its rewrite lands
     * (take it down first with reach:content-revet or the listing audit).
     *
     * @return array{content_item_id:int, regeneration_id:?int, work_block_id:null, from_status:string}
     */
    public function redraft(int $contentItemId, ?int $actorId = null): array
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (! $item) {
            throw new \RuntimeException("Blog content item {$contentItemId} not found.");
        }

        $from = (string) ($item['workflow_status'] ?? '');
        $now  = date('Y-m-d H:i:s');

        // Retire the old pipeline's open blocks so nothing re-parks or re-sends it.
        $this->db->table('reach_work_blocks')
            ->where('content_item_id', $contentItemId)
            ->whereIn('block_type', [
                WorkBlockService::TYPE_HUMAN_REVIEW_GATE,
                WorkBlockService::TYPE_GENERATE_DRAFT,
                WorkBlockService::TYPE_FACT_VERIFY,
                WorkBlockService::TYPE_SEO_OPTIMIZE,
                WorkBlockService::TYPE_CROSS_REVIEW,
                WorkBlockService::TYPE_PUBLISH_BLOG,
            ])
            ->whereIn('eligibility_status', ['pending', 'eligible', 'queued', 'running', 'blocked', 'leased'])
            ->update([
                'eligibility_status' => 'cancelled',
                'output_json'        => json_encode(['outcome' => 'superseded_by_routine_rewrite'], JSON_UNESCAPED_SLASHES),
                'updated_at'         => $now,
            ]);

        $live = in_array($from, [BlogStateMachine::LIVE, BlogStateMachine::PUBLISHED, BlogStateMachine::VERIFICATION_PENDING], true);
        if (strtolower((string) ($item['approval_status'] ?? '')) === 'approved' && ! $live) {
            (new BlogContentApprovalService())->revoke($contentItemId, 'Stub body: queued for a routine rewrite', $actorId);
        }
        if (! $live) {
            $this->db->table('reach_content_items')->where('id', $contentItemId)->update([
                'workflow_status' => BlogStateMachine::REJECTED,
                'approval_status' => 'rejected',
                'updated_at'      => $now,
            ]);
            $this->db->table('reach_content_blog_details')->where('content_item_id', $contentItemId)
                ->update(['automation_state' => BlogStateMachine::REJECTED, 'updated_at' => $now]);
        }

        $regenerationId = (new \App\Libraries\Content\Quality\RegenerationQueue($this->db))
            ->enqueue($contentItemId, ['The saved body is a placeholder, not an article.'], $live);

        AuditLogger::record('blog.content_redraft_queued', [
            'content_item_id' => $contentItemId,
            'from_status'     => $from,
            'regeneration_id' => $regenerationId,
        ], $actorId);

        return [
            'content_item_id' => $contentItemId,
            'regeneration_id' => $regenerationId,
            'work_block_id'   => null,
            'from_status'     => $from,
        ];
    }
}
