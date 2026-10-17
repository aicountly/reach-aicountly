<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

/**
 * Shapes reach_routine_submissions rows for the routine (API response) and
 * for the Command Centre / KB publishing screens.
 */
final class RoutineSubmissionView
{
    private const LABELS = [
        RoutineSubmissions::PENDING_VETTING => 'Waiting for vetting',
        RoutineSubmissions::REJECTED        => 'Rejected by vetting',
        RoutineSubmissions::HELD_HIGH_RISK  => 'Held: high risk (needs a person)',
        RoutineSubmissions::AWAITING_COVER  => 'Approved, waiting for a cover',
        RoutineSubmissions::APPROVED        => 'Approved',
        RoutineSubmissions::PUBLISH_BLOCKED => 'Approved, publish blocked',
        RoutineSubmissions::PUBLISHING      => 'Publishing',
        RoutineSubmissions::LIVE            => 'Live',
        RoutineSubmissions::PUBLISH_FAILED  => 'Publish failed',
    ];

    /** Statuses an operator may push forward with "Retry". */
    public const RETRYABLE = [
        RoutineSubmissions::PENDING_VETTING, RoutineSubmissions::AWAITING_COVER, RoutineSubmissions::APPROVED,
        RoutineSubmissions::PUBLISH_BLOCKED, RoutineSubmissions::PUBLISHING, RoutineSubmissions::PUBLISH_FAILED,
    ];

    /**
     * What the routine is told after it submits.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function summary(array $row): array
    {
        return array_filter([
            'status'          => $row['status'],
            'status_label'    => self::LABELS[$row['status']] ?? $row['status'],
            'decision'        => $row['decision'] ?? null,
            'reasons'         => $row['reasons_json'] ?? [],
            'scores'          => $row['scores_json'] ?? [],
            'vetting_model'   => $row['vetting_model'] ?? null,
            'vetting_run_id'  => isset($row['vetting_run_id']) ? (int) $row['vetting_run_id'] : null,
            'cover'           => $row['cover_status'] ?? null,
            'deployment_id'   => isset($row['deployment_id']) ? (int) $row['deployment_id'] : null,
            'note'            => $row['last_error'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param array<string, mixed> $row a RoutineSubmissions::listForUi() row
     * @return array<string, mixed>
     */
    public static function forUi(array $row): array
    {
        $siteBase = rtrim((string) env('AICOUNTLY_PUBLIC_SITE_BASE_URL', 'https://aicountly.com'), '/');
        $path     = $row['content_type'] === 'knowledge_base' ? '/help/' : '/blogs/';

        return [
            'id'              => (int) $row['id'],
            'content_type'    => $row['content_type'],
            'content_item_id' => (int) $row['content_item_id'],
            'content_key'     => $row['content_key'],
            'title'           => $row['title'] ?? null,
            'slug'            => $row['slug'] ?? null,
            'public_url'      => $row['status'] === RoutineSubmissions::LIVE && ! empty($row['slug']) ? $siteBase . $path . rawurlencode((string) $row['slug']) : null,
            'status'          => $row['status'],
            'status_label'    => self::LABELS[$row['status']] ?? $row['status'],
            'retryable'       => in_array($row['status'], self::RETRYABLE, true),
            'decision'        => $row['decision'],
            'reasons'         => $row['reasons_json'] ?? [],
            'scores'          => $row['scores_json'] ?? [],
            'vetting_model'   => $row['vetting_model'],
            'vetting_run_id'  => $row['vetting_run_id'] !== null ? (int) $row['vetting_run_id'] : null,
            'vetting_attempts' => (int) $row['vetting_attempts'],
            'next_attempt_at' => $row['next_attempt_at'],
            'risk_flag'       => $row['risk_flag'],
            'approved_at'     => $row['approved_at'],
            'cover_status'    => $row['cover_status'],
            'cover_url'       => $row['cover_url'],
            'cover_alt'       => $row['cover_alt'],
            'deployment_id'   => $row['deployment_id'] !== null ? (int) $row['deployment_id'] : null,
            'workflow_status' => $row['workflow_status'] ?? null,
            'last_error'      => $row['last_error'],
            'published_at'    => $row['published_at'],
            'verified_at'     => $row['verified_at'],
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
        ];
    }
}
