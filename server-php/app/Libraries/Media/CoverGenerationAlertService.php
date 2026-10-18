<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Ai\Images\OpenAiImageProvider;
use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Gateways\Mailer;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What cover automation did, and the one email it may send.
 *
 * Replaces the old 09:00 "Cover-image gallery deficit: N image(s) needed …
 * Prompts to generate … Upload at: Reach console → Quality Centre → Cover
 * Gallery" email, which asked a person to make images. Reach makes them now
 * (CoverGalleryAutofillService), so:
 *
 *   - the numbers go into the daily analytics report ("Covers: N generated
 *     automatically, gallery has M active, deficit D") — status();
 *   - an email goes out only when generation keeps failing — at least
 *     covers.alerts.failure_threshold failures in 24 h with no recovery, or no
 *     API key while covers are needed — and says what failed. It is
 *     informational: Reach keeps retrying on its own. covers.alerts.enabled
 *     turns it off. Recipients: covers.alerts.recipients (env
 *     BLOG_GALLERY_ALERT_EMAIL, the old email's address), else the daily
 *     report recipients.
 */
class CoverGenerationAlertService
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null,
        private readonly ?\Closure $mailer = null,
        private readonly ?\Closure $providerConfigured = null,
        private readonly ?MediaGalleryDeficitService $deficit = null,
    ) {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{generated:int, failed:int, last_error:?string, last_status:?string, provider_configured:bool,
     *               auto_enabled:bool, gallery_active:int, deficit:?int, needed:?int, by_purpose:array<string,int>}
     */
    public function status(?string $since = null, ?string $until = null): array
    {
        $since ??= gmdate('Y-m-d H:i:s', time() - 86400) . '+00';
        $until ??= gmdate('Y-m-d H:i:s', time() + 60) . '+00';

        $generated = 0;
        $failed    = 0;
        $lastError = null;
        $lastStatus = null;
        $byPurpose = [];
        if (SchemaGuard::hasTable($this->db, 'reach_cover_generation_attempts')) {
            foreach ($this->db->query(
                'SELECT purpose, status, COUNT(*) AS c FROM reach_cover_generation_attempts
                 WHERE created_at >= ? AND created_at <= ? GROUP BY purpose, status',
                [$since, $until]
            )->getResultArray() as $row) {
                if ($row['status'] === 'generated') {
                    $generated += (int) $row['c'];
                    $byPurpose[(string) $row['purpose']] = ($byPurpose[(string) $row['purpose']] ?? 0) + (int) $row['c'];
                } else {
                    $failed += (int) $row['c'];
                }
            }
            $last = $this->db->query(
                'SELECT status, error FROM reach_cover_generation_attempts WHERE created_at >= ? AND created_at <= ? ORDER BY id DESC LIMIT 1',
                [$since, $until]
            )->getRowArray();
            $lastStatus = $last['status'] ?? null;
            $lastErr    = $this->db->query(
                "SELECT error FROM reach_cover_generation_attempts WHERE status = 'failed' AND created_at >= ? AND created_at <= ? ORDER BY id DESC LIMIT 1",
                [$since, $until]
            )->getRowArray();
            $lastError = $lastErr['error'] ?? null;
        }

        $active = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM reach_media_gallery_assets WHERE status = 'active' AND kind IN ('gallery_upload','ai_gallery')"
        )->getRowArray()['c'] ?? 0);

        $deficit = null;
        $needed  = null;
        try {
            $report  = ($this->deficit ?? new MediaGalleryDeficitService($this->db))->report();
            $deficit = (int) $report['deficit'];
            $needed  = (int) $report['needed'];
        } catch (\Throwable) {
        }

        return [
            'generated'           => $generated,
            'failed'              => $failed,
            'last_error'          => $lastError,
            'last_status'         => $lastStatus,
            'provider_configured' => $this->providerConfigured !== null ? (bool) ($this->providerConfigured)() : (new OpenAiImageProvider())->isConfigured(),
            'auto_enabled'        => RoutineFlags::enabled('covers.auto'),
            'gallery_active'      => $active,
            'deficit'             => $deficit,
            'needed'              => $needed,
            'by_purpose'          => $byPurpose,
        ];
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (! (bool) ReachConfig::get('covers.alerts.enabled', true)) {
            return ['sent' => false, 'reason' => 'alerts_disabled'];
        }

        $s         = $this->status();
        $threshold = max(1, (int) ReachConfig::get('covers.alerts.failure_threshold', 3));
        $failing   = $s['failed'] >= $threshold && ($s['generated'] === 0 || $s['last_status'] === 'failed');
        $noKey     = ! $s['provider_configured'] && $s['auto_enabled'] && (int) $s['deficit'] > 0;

        if (! $failing && ! $noKey) {
            return ['sent' => false, 'reason' => 'no_repeated_failures'] + $s;
        }

        $to = $this->recipients();
        if ($to === []) {
            return ['sent' => false, 'reason' => 'no_recipients'] + $s;
        }

        $what = $noKey && ! $failing
            ? 'Reach cannot generate cover images: the OpenAI API key is not set (Settings → AI & Automation → AI providers).'
            : sprintf('Reach could not generate cover images: %d attempt(s) failed in the last 24 hours (%d succeeded).', $s['failed'], $s['generated']);

        $lines = [
            $what,
            $s['last_error'] !== null ? 'Last error: ' . $s['last_error'] : null,
            sprintf('Gallery: %d active cover(s); deficit for the next %s days: %s.', $s['gallery_active'], (string) env('MEDIA_GALLERY_LOOKAHEAD_DAYS', 14), $s['deficit'] === null ? 'unknown' : (string) $s['deficit']),
            '',
            'For information. Reach keeps retrying every hour on its own and nothing in this email needs a reply.',
            'Articles that have no suitable cover stay off aicountly.com until one is generated; no article is marked as having a cover it does not have.',
            'Turn this email off with covers.alerts.enabled (Settings → AI & Automation → Images).',
        ];
        $body = implode("\n", array_values(array_filter($lines, static fn ($l) => $l !== null)));
        $subject = '[Reach] Cover generation is failing (informational)';

        $sent = $this->mailer !== null
            ? (bool) ($this->mailer)($to, $subject, $body)
            : Mailer::send($to, $subject, nl2br(htmlspecialchars($body)), $body);

        return ['sent' => $sent, 'to' => $to, 'subject' => $subject, 'body' => $body] + $s;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $raw = trim((string) ReachConfig::get('covers.alerts.recipients', ''));
        if ($raw === '') {
            $raw = trim((string) ReachConfig::get('reports.daily.recipients', ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
