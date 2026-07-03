<?php

namespace App\Libraries;

use App\Models\MarketingBotReportModel;
use Config\Services;

/**
 * Writes structured Marketing Bot reports.
 *
 * Every bot action MUST produce a report row with the full spec:
 *   understanding, data_accessed, content_generated, recommended_action,
 *   action_taken, approval_status, publishing_status, next_recommended_action,
 *   mode, evidence, errors, timestamp.
 *
 * Reports are also fanned out to Console via ConsoleAuditClient.
 */
class MarketingBotReporter
{
    private MarketingBotReportModel $reports;

    public function __construct()
    {
        $this->reports = new MarketingBotReportModel();
    }

    /**
     * @param array{
     *   queue_id?:int|null,
     *   action:string,
     *   understanding?:string,
     *   data_accessed?:array,
     *   content_generated?:array,
     *   recommended_action?:string,
     *   action_taken?:string,
     *   approval_status?:string,
     *   publishing_status?:string,
     *   next_recommended_action?:string,
     *   mode:string,
     *   evidence?:array,
     *   errors?:array,
     *   created_by?:int|null,
     * } $report
     *
     * @return int Report id.
     */
    public function record(array $report): int
    {
        $row = [
            'queue_id'                => $report['queue_id']                ?? null,
            'action'                  => (string) $report['action'],
            'understanding'           => $report['understanding']           ?? null,
            'data_accessed'           => isset($report['data_accessed'])    ? json_encode($report['data_accessed'], JSON_UNESCAPED_SLASHES) : null,
            'content_generated'       => isset($report['content_generated'])? json_encode($report['content_generated'], JSON_UNESCAPED_SLASHES) : null,
            'recommended_action'      => $report['recommended_action']      ?? null,
            'action_taken'            => $report['action_taken']            ?? null,
            'approval_status'         => $report['approval_status']         ?? 'not_required',
            'publishing_status'       => $report['publishing_status']       ?? 'none',
            'next_recommended_action' => $report['next_recommended_action'] ?? null,
            'mode'                    => in_array($report['mode'] ?? 'confirm', ['auto', 'confirm'], true) ? $report['mode'] : 'confirm',
            'evidence'                => isset($report['evidence'])         ? json_encode($report['evidence'], JSON_UNESCAPED_SLASHES) : null,
            'errors'                  => isset($report['errors'])           ? json_encode($report['errors'], JSON_UNESCAPED_SLASHES) : null,
            'created_by'              => $report['created_by']              ?? null,
        ];
        $this->reports->insert($row);
        $id = (int) $this->reports->db->insertID();

        // Fan out to Console — no PII in payload; just summary keys.
        try {
            Services::consoleAudit()->event('reach.bot.' . $row['action'], [
                'report_id'         => $id,
                'queue_id'          => $row['queue_id'],
                'approval_status'   => $row['approval_status'],
                'publishing_status' => $row['publishing_status'],
                'mode'              => $row['mode'],
                'action_taken'      => $row['action_taken'],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'ConsoleAudit event failed: ' . $e->getMessage());
        }
        return $id;
    }

    public function markApproved(int $reportId, int $approverId, string $publishingStatus = 'queued'): void
    {
        $this->reports->update($reportId, [
            'approval_status'   => 'approved',
            'publishing_status' => $publishingStatus,
            'approved_by'       => $approverId,
            'approved_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    public function markRejected(int $reportId, int $approverId, ?string $note = null): void
    {
        $update = [
            'approval_status' => 'rejected',
            'approved_by'     => $approverId,
            'approved_at'     => date('Y-m-d H:i:s'),
        ];
        if ($note !== null) {
            $update['next_recommended_action'] = $note;
        }
        $this->reports->update($reportId, $update);
    }
}
