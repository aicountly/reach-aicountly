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
     * Reset item to outline_ready and enqueue a fresh GENERATE_DRAFT block.
     *
     * @return array{content_item_id:int,work_block_id:int,from_status:string}
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

        // Complete open human-review gates so they do not keep the item parked.
        $this->db->table('reach_work_blocks')
            ->where('content_item_id', $contentItemId)
            ->where('block_type', WorkBlockService::TYPE_HUMAN_REVIEW_GATE)
            ->whereIn('eligibility_status', ['blocked', 'eligible', 'leased', 'running', 'queued', 'pending'])
            ->update([
                'eligibility_status' => 'completed',
                'output_json'        => json_encode(['outcome' => 'superseded_by_redraft'], JSON_UNESCAPED_SLASHES),
                'completed_at'       => $now,
                'updated_at'         => $now,
            ]);

        // Cancel any in-flight generate/fact/seo/cross blocks so the new draft wins.
        $this->db->table('reach_work_blocks')
            ->where('content_item_id', $contentItemId)
            ->whereIn('block_type', [
                WorkBlockService::TYPE_GENERATE_DRAFT,
                WorkBlockService::TYPE_FACT_VERIFY,
                WorkBlockService::TYPE_SEO_OPTIMIZE,
                WorkBlockService::TYPE_CROSS_REVIEW,
            ])
            ->whereIn('eligibility_status', ['pending', 'eligible', 'queued', 'running', 'blocked', 'leased'])
            ->update([
                'eligibility_status' => 'cancelled',
                'updated_at'         => $now,
            ]);

        $this->db->table('reach_content_items')->where('id', $contentItemId)->update([
            'workflow_status' => BlogStateMachine::OUTLINE_READY,
            'approval_status' => 'pending',
            'updated_at'      => $now,
        ]);

        $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)
            ->update([
                'automation_state' => BlogStateMachine::OUTLINE_READY,
                'updated_at'       => $now,
            ]);

        $workBlocks = new WorkBlockService();
        $blockId    = $workBlocks->create([
            'block_type'         => WorkBlockService::TYPE_GENERATE_DRAFT,
            'scope'              => 'blog',
            'content_item_id'    => $contentItemId,
            'content_version_id' => null,
            'eligibility_status' => 'eligible',
            'priority'           => 5,
            'idempotency_key'    => "blog-{$contentItemId}-redraft-draft-" . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'input_json'         => ['reason' => 'redraft_stub_body', 'from_status' => $from],
        ]);

        AuditLogger::record('blog.content_redraft_queued', [
            'content_item_id' => $contentItemId,
            'from_status'     => $from,
            'work_block_id'   => $blockId,
        ], $actorId);

        return [
            'content_item_id' => $contentItemId,
            'work_block_id'   => $blockId,
            'from_status'     => $from,
        ];
    }
}
