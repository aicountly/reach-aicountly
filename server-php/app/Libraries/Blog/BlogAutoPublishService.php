<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\AuditLogger;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Low/medium-risk auto-publish when BLOG_AUTO_PUBLISH_ENABLED +
 * BLOG_PUBLIC_PUBLISHER_ENABLED are both on.
 *
 * High/critical risk never auto-publishes. Same-provider / failed editorial
 * review should still prefer a human gate (caller decides); this service only
 * answers "may this item auto-approve and enqueue PUBLISH_BLOG?".
 */
class BlogAutoPublishService
{
    private BaseConnection $db;
    private BlogFeatureFlags $flags;

    public function __construct(?BlogFeatureFlags $flags = null)
    {
        $this->db    = Database::connect();
        $this->flags = $flags ?? new BlogFeatureFlags();
    }

    /**
     * @param array<string,mixed> $item reach_content_items row
     */
    public function isEligible(array $item): bool
    {
        if (! $this->flags->isEnabled('auto_publish') || ! $this->flags->isEnabled('public_publisher')) {
            return false;
        }

        if ($this->isHighRisk($item)) {
            return false;
        }

        $status = strtolower((string) ($item['workflow_status'] ?? ''));

        return in_array($status, [
            BlogStateMachine::INTERNAL_REVIEW,
            BlogStateMachine::SEO_REVIEW,
            BlogStateMachine::APPROVED,
            BlogStateMachine::CHANGES_REQUESTED,
        ], true);
    }

    /**
     * @param array<string,mixed> $item
     */
    public function isHighRisk(array $item): bool
    {
        $riskLevel = strtoupper((string) ($item['risk_level'] ?? 'LOW'));
        if (in_array($riskLevel, ['HIGH', 'CRITICAL'], true)) {
            return true;
        }

        $contentItemId = (int) ($item['id'] ?? 0);
        if ($contentItemId <= 0) {
            return false;
        }

        try {
            $details = $this->db->table('reach_content_blog_details')
                ->where('content_item_id', $contentItemId)
                ->get()
                ->getRowArray();
        } catch (\Throwable) {
            return false;
        }

        $riskClass = strtoupper((string) ($details['risk_class'] ?? $riskLevel));

        return $riskClass === 'HIGH' || $riskClass === 'CRITICAL';
    }

    /**
     * Auto-approve (system bot) + move to publish_queued so PUBLISH_BLOG is enqueued.
     *
     * @return array{
     *   attempted: bool,
     *   queued: bool,
     *   reason: string,
     *   content_item_id?: int,
     *   content_version_id?: int,
     *   work_block_id?: int|null,
     *   system_user_id?: int
     * }
     */
    public function tryAutoPublish(int $contentItemId, ?int $contentVersionId = null, ?int $triggerWorkBlockId = null): array
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->get()
            ->getRowArray();

        if (! $item) {
            return ['attempted' => false, 'queued' => false, 'reason' => 'item_not_found'];
        }

        if (! $this->flags->isEnabled('auto_publish') || ! $this->flags->isEnabled('public_publisher')) {
            return ['attempted' => false, 'queued' => false, 'reason' => 'publish_flags_disabled'];
        }

        if ($this->isHighRisk($item)) {
            return ['attempted' => true, 'queued' => false, 'reason' => 'high_risk_requires_human'];
        }

        $versionId = $contentVersionId ?? (int) ($item['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['attempted' => true, 'queued' => false, 'reason' => 'missing_content_version'];
        }

        $status = strtolower((string) ($item['workflow_status'] ?? ''));
        if (! in_array($status, [
            BlogStateMachine::INTERNAL_REVIEW,
            BlogStateMachine::SEO_REVIEW,
            BlogStateMachine::APPROVED,
            BlogStateMachine::CHANGES_REQUESTED,
            BlogStateMachine::PUBLISH_QUEUED,
        ], true)) {
            return [
                'attempted' => true,
                'queued'    => false,
                'reason'    => 'workflow_status_not_auto_publishable:' . $status,
            ];
        }

        // Already queued/publishing — do not double-approve.
        if (in_array($status, [BlogStateMachine::PUBLISH_QUEUED, BlogStateMachine::PUBLISHING], true)) {
            return [
                'attempted'          => true,
                'queued'             => true,
                'reason'             => 'already_publish_queued',
                'content_item_id'    => $contentItemId,
                'content_version_id' => $versionId,
            ];
        }

        $systemUserId = $this->ensureSystemBotUserId();
        $approvals    = new BlogContentApprovalService();

        if (strtolower((string) ($item['approval_status'] ?? '')) !== 'approved'
            || ! $approvals->verifyForPublication($contentItemId, $versionId)
        ) {
            $approvals->approve(
                $contentItemId,
                $versionId,
                $systemUserId,
                'standard',
                'Auto-approved for low/medium-risk publish (BLOG_AUTO_PUBLISH_ENABLED)',
            );
        }

        $this->completeHumanReviewGates($contentItemId, [
            'outcome'            => 'auto_published',
            'content_version_id' => $versionId,
            'system_user_id'     => $systemUserId,
        ]);

        $sm = new BlogStateMachine();
        $meta = [
            'work_block_id'      => $triggerWorkBlockId,
            'content_version_id' => $versionId,
            'reason'             => 'auto_publish_low_risk',
        ];

        try {
            if ($status === BlogStateMachine::CHANGES_REQUESTED) {
                $sm->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, $systemUserId, $meta);
                $status = BlogStateMachine::INTERNAL_REVIEW;
            }
            if ($status === BlogStateMachine::SEO_REVIEW) {
                $sm->transition($contentItemId, BlogStateMachine::INTERNAL_REVIEW, $systemUserId, $meta);
                $status = BlogStateMachine::INTERNAL_REVIEW;
            }
            if ($status === BlogStateMachine::INTERNAL_REVIEW) {
                $sm->transition($contentItemId, BlogStateMachine::APPROVED, $systemUserId, $meta);
                $status = BlogStateMachine::APPROVED;
            }
            if ($status === BlogStateMachine::APPROVED) {
                // Successor enqueue creates PUBLISH_BLOG via NEXT_WORK_BLOCK.
                $sm->transition($contentItemId, BlogStateMachine::PUBLISH_QUEUED, $systemUserId, $meta);
            }
        } catch (\Throwable $e) {
            return [
                'attempted'          => true,
                'queued'             => false,
                'reason'             => 'transition_failed:' . $e->getMessage(),
                'content_item_id'    => $contentItemId,
                'content_version_id' => $versionId,
                'system_user_id'     => $systemUserId,
            ];
        }

        AuditLogger::record('blog.auto_publish_queued', [
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId,
            'trigger_work_block' => $triggerWorkBlockId,
        ], $systemUserId);

        return [
            'attempted'          => true,
            'queued'             => true,
            'reason'             => 'auto_publish_queued',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId,
            'work_block_id'      => $triggerWorkBlockId,
            'system_user_id'     => $systemUserId,
        ];
    }

    public function ensureSystemBotUserId(): int
    {
        $email = 'system-bot@reach.local';
        $row   = $this->db->table('reach_users')->where('email', $email)->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        $roleId = (int) ($this->db->table('reach_roles')->where('slug', 'super_admin')->get()->getRowArray()['id'] ?? 0);
        if ($roleId <= 0) {
            $roleId = (int) ($this->db->table('reach_roles')->orderBy('id', 'ASC')->limit(1)->get()->getRowArray()['id'] ?? 0);
        }
        if ($roleId <= 0) {
            throw new \RuntimeException('Cannot auto-publish: no role available to seed system-bot user.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_users')->insert([
            'email'             => $email,
            'name'              => 'Reach System Bot',
            'password_hash'     => '',
            'role_id'           => $roleId,
            'is_active'         => false,
            'is_login_disabled' => true,
            'actor_type'        => 'system',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * @param array<string,mixed> $output
     */
    private function completeHumanReviewGates(int $contentItemId, array $output): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_work_blocks')
            ->where('content_item_id', $contentItemId)
            ->where('block_type', WorkBlockService::TYPE_HUMAN_REVIEW_GATE)
            ->whereIn('eligibility_status', ['blocked', 'eligible', 'leased', 'running', 'queued', 'pending'])
            ->update([
                'eligibility_status' => 'completed',
                'output_json'        => json_encode($output, JSON_UNESCAPED_SLASHES),
                'completed_at'       => $now,
                'updated_at'         => $now,
            ]);
    }
}
