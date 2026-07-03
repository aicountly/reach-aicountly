<?php

namespace App\Libraries;

use App\Models\ConsoleSyncLogModel;

/**
 * Fire-and-forget audit/event fan-out to console.aicountly.org.
 *
 * Mirrors flow-react-app's ConsoleAuditClient pattern: a single POST
 * to `{CONSOLE_API_BASE_URL}/audit` with a typed `type` string.
 * Every attempt (success or failure) is recorded in reach_console_sync_logs
 * so the Console Sync Status page can report health without needing
 * Console to expose a read API.
 */
class ConsoleAuditClient
{
    private string $baseUrl;
    private string $token;
    private ConsoleSyncLogModel $log;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('CONSOLE_API_BASE_URL', ''), '/');
        $this->token   = (string) env('CONSOLE_API_TOKEN', '');
        $this->log     = new ConsoleSyncLogModel();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * Send a typed event to Console. Never throws.
     * `type` is a dotted event key, e.g. 'reach.bot.generate_blog_draft',
     * 'reach.approval.decided', 'reach.lead.pushed', 'reach.health.ping'.
     */
    public function event(string $type, array $payload): void
    {
        if (! $this->isConfigured()) {
            $this->recordAttempt($type, $payload, null, null, false, 'CONSOLE_API_BASE_URL / CONSOLE_API_TOKEN not configured');
            return;
        }

        $body = [
            'source'    => 'reach.aicountly.org',
            'type'      => $type,
            'payload'   => $payload,
            'timestamp' => date(DATE_ATOM),
        ];

        $ch = curl_init($this->baseUrl . '/audit');
        if ($ch === false) {
            $this->recordAttempt($type, $payload, null, null, false, 'curl_init failed');
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
                'X-Source: reach.aicountly.org',
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $ok       = $raw !== false && $status >= 200 && $status < 300;
        $response = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (! $ok) {
            log_message('error', sprintf(
                '[ConsoleAuditClient] type=%s status=%d err=%s',
                $type,
                $status,
                $err !== '' ? $err : (is_string($raw) ? substr($raw, 0, 200) : 'no body'),
            ));
        }
        $this->recordAttempt(
            $type,
            $payload,
            $status,
            is_array($response) ? $response : null,
            $ok,
            $ok ? null : ($err !== '' ? $err : (is_string($raw) ? substr($raw, 0, 500) : 'unknown error')),
        );
    }

    private function recordAttempt(
        string $type,
        array $payload,
        ?int $status,
        ?array $response,
        bool $ok,
        ?string $err,
    ): void {
        try {
            $this->log->insert([
                'event_type'      => $type,
                'payload'         => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'response_status' => $status,
                'response_body'   => $response !== null ? json_encode($response, JSON_UNESCAPED_SLASHES) : null,
                'ok'              => $ok,
                'error_message'   => $err,
                'attempted_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'ConsoleSyncLog write failed: ' . $e->getMessage());
        }
    }
}
