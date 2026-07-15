<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Enums\RefreshWorkflowStatus;

/**
 * Defines the permitted state transitions for the refresh workflow state machine.
 *
 * The transition table is the single source of truth for what moves are legal.
 * RefreshWorkflowService uses this to enforce all state changes without
 * scattered conditional logic.
 */
final class RefreshWorkflowTransitions
{
    /**
     * Each key is the FROM state; each value is the list of TO states allowed.
     *
     * @return array<string, string[]>
     */
    public static function allowed(): array
    {
        return [
            RefreshWorkflowStatus::Accepted->value => [
                RefreshWorkflowStatus::BriefPrepared->value,
                RefreshWorkflowStatus::Deferred->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            RefreshWorkflowStatus::BriefPrepared->value => [
                RefreshWorkflowStatus::DraftGenerating->value,
                RefreshWorkflowStatus::ChangesRequested->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            RefreshWorkflowStatus::DraftGenerating->value => [
                RefreshWorkflowStatus::DraftReady->value,
                RefreshWorkflowStatus::Failed->value,
                RefreshWorkflowStatus::Blocked->value,
            ],
            RefreshWorkflowStatus::DraftReady->value => [
                RefreshWorkflowStatus::InReview->value,
                RefreshWorkflowStatus::ChangesRequested->value,
                RefreshWorkflowStatus::Rejected->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            RefreshWorkflowStatus::InReview->value => [
                RefreshWorkflowStatus::Approved->value,
                RefreshWorkflowStatus::ChangesRequested->value,
                RefreshWorkflowStatus::Rejected->value,
            ],
            RefreshWorkflowStatus::ChangesRequested->value => [
                RefreshWorkflowStatus::DraftGenerating->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            RefreshWorkflowStatus::Approved->value => [
                RefreshWorkflowStatus::PublishQueued->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            RefreshWorkflowStatus::PublishQueued->value => [
                RefreshWorkflowStatus::Published->value,
                RefreshWorkflowStatus::Failed->value,
            ],
            RefreshWorkflowStatus::Published->value => [
                RefreshWorkflowStatus::Monitoring->value,
                RefreshWorkflowStatus::Withdrawn->value,
            ],
            RefreshWorkflowStatus::Monitoring->value => [
                RefreshWorkflowStatus::OutcomeRecorded->value,
                RefreshWorkflowStatus::Withdrawn->value,
            ],
            RefreshWorkflowStatus::Blocked->value => [
                RefreshWorkflowStatus::DraftGenerating->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            RefreshWorkflowStatus::Failed->value => [
                RefreshWorkflowStatus::DraftGenerating->value,
                RefreshWorkflowStatus::Cancelled->value,
            ],
            // Terminal states — no transitions
            RefreshWorkflowStatus::OutcomeRecorded->value => [],
            RefreshWorkflowStatus::Rejected->value        => [],
            RefreshWorkflowStatus::Deferred->value        => [],
            RefreshWorkflowStatus::Cancelled->value       => [],
            RefreshWorkflowStatus::Superseded->value      => [],
            RefreshWorkflowStatus::Withdrawn->value       => [],
        ];
    }

    public static function isAllowed(string $from, string $to): bool
    {
        return in_array($to, self::allowed()[$from] ?? [], true);
    }

    public static function isTerminal(string $status): bool
    {
        return empty(self::allowed()[$status] ?? []);
    }
}
