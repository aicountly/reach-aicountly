<?php

declare(strict_types=1);

namespace App\Libraries\Reports;

use App\Libraries\Gateways\Mailer;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Builds, stores and emails the daily analytics report (reach_daily_reports).
 *
 * Production honesty: a report is `sent` only when an email provider accepted
 * it (Console's providers for reach via Mailer/EmailDispatcher, primary then
 * fallback; .env EMAIL_API_KEY as the last resort). With no provider at all
 * nothing is attempted and the row is `failed` with that reason; when every
 * provider refuses it is `failed` (or `partial` when some recipients were
 * accepted) with each provider's reason.
 *
 * Idempotency: the scheduled run never re-sends a date already `sent` or
 * `partial`; only an explicit send (UI "Send now", `reach:daily-report --send`)
 * does. An explicit send of a date that was already attempted is a person
 * asking for the mail again, so it goes out as a resend (EmailDispatcher tries
 * the fallback provider first, then the primary).
 */
class DailyReportService
{
    private BaseConnection $db;

    private DailyReportBuilder $builder;

    /** @var \Closure(string, string, string, string, array<string,mixed>): array{ok:bool, error:?string} */
    private \Closure $mailer;

    /** @var \Closure(): bool */
    private \Closure $mailConfigured;

    /**
     * @param (callable(string, string, string, string, array<string,mixed>): array{ok:bool, error:?string})|null $mailer (to, subject, html, text, opts{resend})
     * @param (callable(): bool)|null $mailConfigured
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?DailyReportBuilder $builder = null,
        ?callable $mailer = null,
        ?callable $mailConfigured = null,
    ) {
        $this->db      = $db ?? Database::connect();
        $this->builder = $builder ?? new DailyReportBuilder($this->db);
        $this->mailer  = $mailer !== null ? \Closure::fromCallable($mailer) : static function (string $to, string $subject, string $html, string $text, array $opts = []): array {
            $ok = Mailer::send($to, $subject, $html, $text, $opts);

            return ['ok' => $ok, 'error' => $ok ? null : (Mailer::getLastError() ?? 'Mail gateway did not accept the message.')];
        };
        $this->mailConfigured = $mailConfigured !== null ? \Closure::fromCallable($mailConfigured) : static fn (): bool => Mailer::isConfigured();
    }

    public static function todayIst(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(DailyReportBuilder::TIMEZONE)))->format('Y-m-d');
    }

    /** @return list<string> */
    public static function recipients(): array
    {
        $raw = (string) ReachConfig::get('reports.daily.recipients', 'pno@aicountly.com');
        $out = [];
        foreach (array_map('trim', explode(',', $raw)) as $email) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = strtolower($email);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Build without storing (the "Preview today" button).
     *
     * @return array{report_date:string, subject:string, html:string, text:string, summary:array<string, mixed>}
     */
    public function preview(?string $date = null): array
    {
        $date    = $date ?: self::todayIst();
        $summary = $this->builder->build($date);

        return [
            'report_date' => $date,
            'subject'     => DailyReportRenderer::subject($summary),
            'html'        => DailyReportRenderer::html($summary),
            'text'        => DailyReportRenderer::text($summary),
            'summary'     => $summary,
        ];
    }

    /**
     * Build and store; never sends. A row already sent keeps its status.
     *
     * @return array<string, mixed> the stored row
     */
    public function generate(string $date, string $triggeredBy = 'manual'): array
    {
        $p = $this->preview($date);

        $row = $this->db->query(
            "INSERT INTO reach_daily_reports (report_date, status, subject, html, text_body, summary_json, triggered_by, created_at, updated_at)
             VALUES (?, 'generated', ?, ?, ?, ?::jsonb, ?, NOW(), NOW())
             ON CONFLICT (report_date) DO UPDATE SET
                subject      = EXCLUDED.subject,
                html         = EXCLUDED.html,
                text_body    = EXCLUDED.text_body,
                summary_json = EXCLUDED.summary_json,
                triggered_by = EXCLUDED.triggered_by,
                status       = CASE WHEN reach_daily_reports.status IN ('sent','partial') THEN reach_daily_reports.status ELSE 'generated' END,
                updated_at   = NOW()
             RETURNING id",
            [$date, $p['subject'], $p['html'], $p['text'], json_encode($p['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $triggeredBy]
        )->getRowArray();

        return $this->find((int) $row['id']) ?? [];
    }

    /**
     * Build, store and email.
     *
     * @param list<string>|null $to override recipients (null = reports.daily.recipients)
     * @return array<string, mixed> the stored row
     */
    public function send(string $date, ?array $to = null, string $triggeredBy = 'manual'): array
    {
        $before     = $this->findByDate($date);
        $resend     = $triggeredBy !== 'schedule' && $before !== null && (int) ($before['send_attempts'] ?? 0) > 0;
        $report     = $this->generate($date, $triggeredBy);
        $recipients = $to ?? self::recipients();
        $recipients = array_values(array_filter(array_map('trim', $recipients), static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

        if ($recipients === []) {
            return $this->markResult((int) $report['id'], 'failed', [], 'No valid recipients (reports.daily.recipients is empty).');
        }
        if (! ($this->mailConfigured)()) {
            return $this->markResult((int) $report['id'], 'failed', [], 'No email provider is configured (Console → Email providers for reach, or EMAIL_API_KEY as the last resort) — the report was generated but not sent.');
        }

        $accepted = [];
        $errors   = [];
        foreach ($recipients as $email) {
            $result = ($this->mailer)($email, (string) $report['subject'], (string) $report['html'], (string) $report['text_body'], ['resend' => $resend]);
            if ($result['ok']) {
                $accepted[] = $email;
            } else {
                $errors[] = $email . ': ' . ($result['error'] ?? 'not accepted');
            }
        }

        $status = match (true) {
            $errors === []   => 'sent',
            $accepted === [] => 'failed',
            default          => 'partial',
        };

        return $this->markResult((int) $report['id'], $status, $accepted, $errors === [] ? null : implode('; ', $errors));
    }

    /**
     * The scheduler's entry point: idempotent per date.
     *
     * @return array<string, mixed>
     */
    public function runScheduled(?string $date = null): array
    {
        $date     = $date ?: self::todayIst();
        $existing = $this->findByDate($date);
        if ($existing !== null && in_array($existing['status'], ['sent', 'partial'], true)) {
            return ['status' => 'skipped', 'reason' => 'already_' . $existing['status'], 'report_id' => (int) $existing['id'], 'date' => $date];
        }

        if (! (bool) ReachConfig::get('reports.daily.enabled', true)) {
            $row = $this->generate($date, 'schedule');

            return ['status' => 'generated', 'reason' => 'reports.daily.enabled is off', 'report_id' => (int) $row['id'], 'date' => $date];
        }

        $row = $this->send($date, null, 'schedule');

        return ['status' => $row['status'], 'report_id' => (int) $row['id'], 'date' => $date, 'sent_to' => $row['sent_to'], 'error' => $row['error']];
    }

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 60): array
    {
        $limit = max(1, min(366, $limit));

        return $this->db->query(
            "SELECT id, report_date::text AS report_date, status, subject, sent_to, error, send_attempts, sent_at, triggered_by, created_at, updated_at
             FROM reach_daily_reports ORDER BY report_date DESC LIMIT {$limit}"
        )->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->query('SELECT *, report_date::text AS report_date FROM reach_daily_reports WHERE id = ?', [$id])->getRowArray();

        return $row ? self::decode($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByDate(string $date): ?array
    {
        $row = $this->db->query('SELECT *, report_date::text AS report_date FROM reach_daily_reports WHERE report_date = ?', [$date])->getRowArray();

        return $row ? self::decode($row) : null;
    }

    /**
     * @param list<string> $accepted
     * @return array<string, mixed>
     */
    private function markResult(int $id, string $status, array $accepted, ?string $error): array
    {
        $this->db->query(
            'UPDATE reach_daily_reports SET status = ?, sent_to = ?, error = ?, send_attempts = send_attempts + 1,
                sent_at = CASE WHEN ? = 1 THEN NOW() ELSE sent_at END, updated_at = NOW() WHERE id = ?',
            [$status, $accepted === [] ? null : implode(', ', $accepted), $error, $accepted !== [] ? 1 : 0, $id]
        );
        if ($status === 'failed') {
            log_message('error', 'Daily report #' . $id . ' not sent: ' . $error);
        }

        return $this->find($id) ?? [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        if (isset($row['summary_json']) && is_string($row['summary_json'])) {
            $row['summary_json'] = json_decode($row['summary_json'], true) ?? [];
        }

        return $row;
    }
}
