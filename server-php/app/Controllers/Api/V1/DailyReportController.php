<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Reports\DailyReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Daily analytics reports: archive, view, preview, and "Send now".
 * Reading needs analytics.view; sending is super_admin only (it emails the
 * whole recipient list).
 */
class DailyReportController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if (! $this->migrated()) {
            return $this->ok(['reports' => [], 'recipients' => DailyReportService::recipients(), 'note' => 'reach_daily_reports is not migrated yet.']);
        }

        return $this->ok([
            'reports'      => (new DailyReportService())->list((int) ($this->request->getGet('limit') ?? 60)),
            'recipients'   => DailyReportService::recipients(),
            'can_send'     => $this->isSuperAdmin(),
            'today'        => DailyReportService::todayIst(),
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->migrated()) {
            return $this->notFound('Report not found.');
        }
        $row = (new DailyReportService())->find($id);

        return $row === null ? $this->notFound('Report not found.') : $this->ok($row);
    }

    /** Build today's (or ?date=) report without storing or sending it. */
    public function preview(): ResponseInterface
    {
        $date = (string) ($this->request->getGet('date') ?? '');
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->error('date must be YYYY-MM-DD', 422);
        }

        try {
            return $this->ok((new DailyReportService())->preview($date !== '' ? $date : null));
        } catch (\Throwable $e) {
            log_message('error', 'DailyReportController::preview: ' . $e->getMessage());

            return $this->error('Unable to build the report preview.', 500);
        }
    }

    /** Body: { date?: "YYYY-MM-DD", to?: ["a@b.com"] } */
    public function send(): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->error('Only a super admin can send the daily report.', 403);
        }
        if (! $this->migrated()) {
            return $this->error('reach_daily_reports is not migrated yet.', 503);
        }

        $body = $this->input();
        $date = (string) ($body['date'] ?? '');
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->error('date must be YYYY-MM-DD', 422);
        }
        $to = null;
        if (! empty($body['to'])) {
            $to = array_values(array_filter(array_map('trim', is_array($body['to']) ? $body['to'] : explode(',', (string) $body['to']))));
            foreach ($to as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->error("'{$email}' is not a valid email address.", 422);
                }
            }
        }

        try {
            $row = (new DailyReportService())->send($date !== '' ? $date : DailyReportService::todayIst(), $to, 'ui:' . ($this->userId() ?? 0));
        } catch (\Throwable $e) {
            log_message('error', 'DailyReportController::send: ' . $e->getMessage());

            return $this->error('Unable to build or send the report.', 500);
        }

        $this->audit('report.daily.sent', 'daily_report', (int) $row['id'], null, ['status' => $row['status'], 'sent_to' => $row['sent_to']]);

        // A report that was not sent is reported as such — never as success.
        if ($row['status'] === 'failed') {
            return $this->error('Report generated but not sent: ' . $row['error'], 502, ['report_id' => (int) $row['id']]);
        }

        return $this->ok($row);
    }

    private function isSuperAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'super_admin';
    }

    private function migrated(): bool
    {
        return SchemaGuard::hasTable(db_connect(), 'reach_daily_reports');
    }
}
