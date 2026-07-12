<?php

namespace App\Libraries;

use Config\Permissions;
use Config\Services;

/**
 * Phase 0 approval policy.
 *
 *   - Bot-created content: any user with `approval.decide` may approve.
 *   - Human-authored content: approver must have `approval.decide`; when the
 *     approver is the original creator, a soft warning is returned unless
 *     `override=true` AND the caller holds `approval.override` AND `reason`
 *     is non-empty.
 *   - Bulk self-approval never permitted through this policy — controllers
 *     must call this policy per subject and short-circuit on the first refusal.
 *
 * Extensibility: `risk` classification is a fixed `low` for Phase 0 but the
 * return object leaves room for future rules (score-based, category-based).
 */
class ApprovalPolicy
{
    /**
     * @param array{
     *     id: int,
     *     subject_type: string,
     *     subject_id: int,
     *     created_actor_type?: string|null,
     *     requested_by?: int|null,
     * } $approval
     * @param array{id:int} $user
     */
    public function canApprove(
        array $approval,
        array $user,
        bool $override = false,
        ?string $reason = null,
    ): ApprovalPolicyResult {
        $userId = (int) $user['id'];
        $perms  = Services::permissionService();

        if (! $perms->hasPermission($userId, Permissions::APPROVAL_DECIDE)) {
            return ApprovalPolicyResult::denied('missing_permission', 'approval.decide required.');
        }

        $isBotCreated = ($approval['created_actor_type'] ?? null) === 'bot';
        $isSelfAuthored = isset($approval['requested_by']) && (int) $approval['requested_by'] === $userId;

        if ($isBotCreated) {
            return ApprovalPolicyResult::allowed('bot_created', 'low');
        }

        if ($isSelfAuthored) {
            if (! $override) {
                return ApprovalPolicyResult::denied(
                    'self_approval_requires_override',
                    'Self-approval requires an explicit override with a reason.',
                );
            }
            if (! $perms->hasPermission($userId, Permissions::APPROVAL_OVERRIDE)) {
                return ApprovalPolicyResult::denied(
                    'missing_override_permission',
                    'approval.override permission required to self-approve.',
                );
            }
            $trimReason = trim((string) $reason);
            if ($trimReason === '' || strlen($trimReason) < 8) {
                return ApprovalPolicyResult::denied(
                    'override_reason_required',
                    'A non-empty reason (>= 8 characters) is required when overriding self-approval.',
                );
            }
            return ApprovalPolicyResult::allowed('override_self_approval', 'low', $trimReason);
        }

        return ApprovalPolicyResult::allowed('normal_approval', 'low');
    }
}
