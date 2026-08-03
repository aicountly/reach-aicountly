<?php

namespace App\Libraries\Blog;

use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Blog content lifecycle state machine backed by reach_content_items.workflow_status
 * and reach_content_blog_details.automation_state.
 */
class BlogStateMachine
{
    public const IDEA                 = 'idea';
    public const TOPIC_CANDIDATE      = 'topic_candidate';
    public const TOPIC_SCORED         = 'topic_scored';
    public const ROADMAP_PLANNED      = 'roadmap_planned';
    public const BRIEF_DRAFT          = 'brief_draft';
    public const BRIEF_READY          = 'brief_ready';
    public const OUTLINE_DRAFT        = 'outline_draft';
    public const OUTLINE_READY        = 'outline_ready';
    public const DRAFT_GENERATING     = 'draft_generating';
    public const DRAFT                = 'draft';
    public const FACT_VERIFYING       = 'fact_verifying';
    public const FACT_VERIFIED        = 'fact_verified';
    public const SEO_REVIEW           = 'seo_review';
    public const INTERNAL_REVIEW      = 'internal_review';
    public const CHANGES_REQUESTED    = 'changes_requested';
    public const APPROVED             = 'approved';
    public const SCHEDULED            = 'scheduled';
    public const PUBLISH_QUEUED       = 'publish_queued';
    public const PUBLISHING           = 'publishing';
    public const PUBLISHED            = 'published';
    public const VERIFICATION_PENDING = 'verification_pending';
    public const LIVE                 = 'live';
    public const REFRESH_PENDING      = 'refresh_pending';
    public const REFRESH_IN_PROGRESS  = 'refresh_in_progress';
    public const UNPUBLISH_QUEUED     = 'unpublish_queued';
    public const UNPUBLISHED          = 'unpublished';
    public const REJECTED             = 'rejected';
    public const ARCHIVED             = 'archived';
    public const FAILED               = 'failed';
    public const BLOCKED              = 'blocked';

    /** @var array<string, list<string>> */
    private const ADJACENCY = [
        self::IDEA                 => [self::TOPIC_CANDIDATE, self::BRIEF_DRAFT, self::DRAFT, self::ARCHIVED],
        self::TOPIC_CANDIDATE      => [self::TOPIC_SCORED, self::REJECTED, self::ARCHIVED],
        self::TOPIC_SCORED         => [self::ROADMAP_PLANNED, self::BRIEF_DRAFT, self::REJECTED],
        self::ROADMAP_PLANNED      => [self::BRIEF_DRAFT, self::ARCHIVED],
        self::BRIEF_DRAFT          => [self::BRIEF_READY, self::DRAFT, self::ARCHIVED],
        self::BRIEF_READY          => [self::OUTLINE_DRAFT, self::DRAFT_GENERATING],
        self::OUTLINE_DRAFT        => [self::OUTLINE_READY, self::CHANGES_REQUESTED],
        self::OUTLINE_READY        => [self::DRAFT_GENERATING, self::DRAFT],
        self::DRAFT_GENERATING     => [self::DRAFT, self::FAILED],
        self::DRAFT                => [self::FACT_VERIFYING, self::SEO_REVIEW, self::INTERNAL_REVIEW, self::ARCHIVED],
        self::FACT_VERIFYING       => [self::FACT_VERIFIED, self::FAILED, self::CHANGES_REQUESTED],
        self::FACT_VERIFIED        => [self::SEO_REVIEW, self::INTERNAL_REVIEW],
        self::SEO_REVIEW           => [self::INTERNAL_REVIEW, self::CHANGES_REQUESTED, self::REJECTED],
        self::INTERNAL_REVIEW      => [self::APPROVED, self::CHANGES_REQUESTED, self::REJECTED],
        self::CHANGES_REQUESTED    => [self::DRAFT, self::OUTLINE_DRAFT, self::ARCHIVED],
        self::APPROVED             => [self::SCHEDULED, self::PUBLISH_QUEUED, self::ARCHIVED],
        // Scheduled items may be force-published, returned to approved, or —
        // after a successful public verify of a due schedule — moved to
        // verification_pending / live without a separate publish work block.
        self::SCHEDULED            => [self::PUBLISH_QUEUED, self::APPROVED, self::ARCHIVED, self::VERIFICATION_PENDING, self::LIVE, self::PUBLISHED],
        self::PUBLISH_QUEUED       => [self::PUBLISHING, self::BLOCKED, self::FAILED],
        self::PUBLISHING           => [self::PUBLISHED, self::FAILED, self::BLOCKED],
        self::PUBLISHED            => [self::VERIFICATION_PENDING, self::LIVE, self::REFRESH_PENDING],
        self::VERIFICATION_PENDING => [self::LIVE, self::FAILED, self::BLOCKED],
        self::LIVE                 => [self::REFRESH_PENDING, self::UNPUBLISH_QUEUED, self::ARCHIVED],
        self::REFRESH_PENDING      => [self::REFRESH_IN_PROGRESS, self::ARCHIVED],
        self::REFRESH_IN_PROGRESS  => [self::LIVE, self::FAILED],
        self::UNPUBLISH_QUEUED     => [self::UNPUBLISHED, self::FAILED],
        self::UNPUBLISHED          => [self::ARCHIVED, self::DRAFT],
        self::REJECTED             => [self::ARCHIVED, self::DRAFT],
        self::ARCHIVED             => [self::DRAFT, self::IDEA],
        self::FAILED               => [self::DRAFT, self::BLOCKED, self::ARCHIVED],
        self::BLOCKED              => [self::APPROVED, self::ARCHIVED],
    ];

    /** @var array<string, string> */
    private const NEXT_WORK_BLOCK = [
        // Content production chain (automation must not stall after brief).
        self::BRIEF_READY      => WorkBlockService::TYPE_GENERATE_OUTLINE,
        self::OUTLINE_READY    => WorkBlockService::TYPE_GENERATE_DRAFT,
        self::DRAFT            => WorkBlockService::TYPE_FACT_VERIFY,
        self::FACT_VERIFIED    => WorkBlockService::TYPE_SEO_OPTIMIZE,
        self::SEO_REVIEW       => WorkBlockService::TYPE_CROSS_REVIEW,
        self::PUBLISH_QUEUED   => WorkBlockService::TYPE_PUBLISH_BLOG,
        self::PUBLISHING       => WorkBlockService::TYPE_VERIFY_PUBLICATION,
        self::FACT_VERIFYING   => WorkBlockService::TYPE_FACT_VERIFY,
        self::DRAFT_GENERATING => WorkBlockService::TYPE_GENERATE_DRAFT,
        self::ROADMAP_PLANNED  => WorkBlockService::TYPE_OPTIMIZE_ROADMAP,
        self::REFRESH_PENDING  => WorkBlockService::TYPE_REFRESH_CONTENT,
        // Going live must be followed by a real sitemap-presence check, not
        // an assumption that "published" implies "indexed" or even "in the
        // sitemap yet". WorkBlockService::executeUpdateSitemap() chains a
        // CHECK_INDEXING block itself once the sitemap check completes.
        self::LIVE             => WorkBlockService::TYPE_UPDATE_SITEMAP,
    ];

    private BaseConnection $db;
    private WorkBlockService $workBlocks;
    private BlogFeatureFlags $flags;

    public function __construct(
        ?WorkBlockService $workBlocks = null,
        ?BlogFeatureFlags $flags = null,
    ) {
        $this->db         = Database::connect();
        $this->workBlocks = $workBlocks ?? new WorkBlockService();
        $this->flags      = $flags ?? new BlogFeatureFlags();
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{from:string,to:string,work_block_id:?int}
     */
    public function transition(int $contentItemId, string $toState, ?int $actorId = null, array $meta = []): array
    {
        $toState = strtolower(trim($toState));
        $item    = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->where('content_type', 'blog')
            ->get()
            ->getRowArray();

        if (! $item) {
            throw new \RuntimeException("Blog content item {$contentItemId} not found");
        }

        $fromState = strtolower((string) ($item['workflow_status'] ?? self::IDEA));
        if (! $this->canTransition($fromState, $toState)) {
            throw new \RuntimeException("Illegal blog state transition: {$fromState} -> {$toState}");
        }

        if ($toState === self::PUBLISHED || $toState === self::LIVE) {
            $this->assertPublishAllowed($contentItemId, $item);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_content_items')->where('id', $contentItemId)->update([
            'workflow_status' => $toState,
            'updated_at'      => $now,
            'published_at'    => in_array($toState, [self::PUBLISHED, self::LIVE], true)
                ? ($item['published_at'] ?? $now)
                : $item['published_at'],
        ]);

        $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)
            ->update(['automation_state' => $toState, 'updated_at' => $now]);

        Services::auditLogger()->log(
            userId:     $actorId,
            action:     AuditLogger::CONTENT_STATUS_CHANGED,
            entityType: 'content_item',
            entityId:   $contentItemId,
            oldValue:   ['workflow_status' => $fromState],
            newValue:   ['workflow_status' => $toState],
            extra:      $meta,
            actorType:  $actorId ? 'human' : 'system',
            actorService: 'reach:blog_state_machine',
        );

        $workBlockId = $this->enqueueSuccessorWorkBlock($contentItemId, $toState, $item, $meta);

        return [
            'from'          => $fromState,
            'to'            => $toState,
            'work_block_id' => $workBlockId,
        ];
    }

    public function canTransition(string $fromState, string $toState): bool
    {
        $fromState = strtolower(trim($fromState));
        $toState   = strtolower(trim($toState));

        if ($fromState === $toState) {
            return true;
        }

        return in_array($toState, self::ADJACENCY[$fromState] ?? [], true);
    }

    /**
     * Guards the transition INTO published/live. This only enforces the
     * "high-risk requires human approval" rule. It must NOT also apply the
     * auto-publish hard ban here: by the time the state machine records a
     * PUBLISHED/LIVE transition, the actual publish call has already been
     * made (either by a human via ContentPublishController, or by the
     * automated PUBLISH_BLOG work block, which applies
     * BlogFeatureFlags::assertHighRiskAutoPublishForbidden() itself before
     * ever calling the publisher). Re-applying the ban here would make
     * legitimately approved high-risk content permanently unable to be
     * recorded as published even though it is already live — a false
     * negative that previously blocked correct, approved publications.
     *
     * @param array<string,mixed> $item
     */
    private function assertPublishAllowed(int $contentItemId, array $item): void
    {
        $riskLevel = strtoupper((string) ($item['risk_level'] ?? 'LOW'));
        $details   = $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)
            ->get()
            ->getRowArray();
        $riskClass = strtoupper((string) ($details['risk_class'] ?? 'LOW'));

        if (in_array($riskLevel, ['HIGH', 'CRITICAL'], true) || $riskClass === 'HIGH') {
            $approval = strtolower((string) ($item['approval_status'] ?? 'not_required'));
            if ($approval !== 'approved') {
                throw new \RuntimeException('High-risk blog content requires human approval before publish.');
            }

            $approvals = new BlogContentApprovalService();
            $versionId = (int) ($item['current_version_id'] ?? 0);
            if ($versionId > 0 && ! $approvals->verifyForPublication($contentItemId, $versionId)) {
                throw new \RuntimeException(
                    'High-risk blog content requires a valid, checksum-matching approval for the exact version being published.'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $meta
     */
    private function enqueueSuccessorWorkBlock(
        int $contentItemId,
        string $toState,
        array $item,
        array $meta,
    ): ?int {
        if (! $this->flags->isEnabled('automation')) {
            return null;
        }

        $blockType = self::NEXT_WORK_BLOCK[$toState] ?? null;
        if ($blockType === null) {
            return null;
        }

        $versionId = (int) ($meta['content_version_id'] ?? $item['current_version_id'] ?? 0);
        $id        = $this->workBlocks->create([
            'block_type'         => $blockType,
            'scope'              => 'blog',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId > 0 ? $versionId : null,
            'idempotency_key'    => "blog-{$contentItemId}-{$toState}-{$blockType}",
            'input_json'         => ['transition_meta' => $meta],
        ]);
        $this->workBlocks->markEligible($id);

        return $id;
    }
}
