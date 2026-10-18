<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Gateways\Mailer;
use App\Libraries\Intelligence\Connectors\ConnectorProviderFactory;
use App\Libraries\Intelligence\Connectors\GoogleSearchConsoleConnector;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The real signals a lag can be explained by, for one window of IST days:
 * switches, integrations that are not configured, routine submissions and
 * where they stopped (vetting rejections and their reasons, stuck vetting or
 * covers, publish failures), failed jobs by type, social posts that failed or
 * wait in the manual queue, the topic queue. RootCauseClassifier reads this
 * array; it never queries anything itself.
 *
 * Integration checks are presence checks (a key is set, a provider is bound)
 * — no network call — and can be replaced by a closure in tests.
 */
class LagSignals
{
    private BaseConnection $db;

    /** @param (\Closure(): array<string, bool|null>)|null $integrations */
    public function __construct(?BaseConnection $db = null, private ?\Closure $integrations = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function gather(string $from, string $to, bool $withIntegrations = true): array
    {
        $start = PlanPeriods::istMidnightUtc($from);
        $end   = PlanPeriods::istMidnightUtc(PlanPeriods::addDays($to, 1));

        return [
            'window'       => ['from' => $from, 'to' => $to, 'days' => PlanPeriods::days($from, $to)],
            'config'       => PlanInputs::config($this->db),
            'integrations' => ! $withIntegrations ? [] : ($this->integrations !== null ? ($this->integrations)() : self::integrations($this->db)),
            'routine'      => $this->routine($start, $end, $from, $to),
            'community'    => $this->community($start, $end),
            'structured'   => $this->structured($start, $end),
            'video'        => $this->video($start, $end),
            'social'       => $this->social($start, $end),
            'vetting'      => $this->vetting($start, $end),
            'jobs'         => $this->jobs($start, $end),
            'topic_queue'  => $this->topicQueue(),
        ];
    }

    /** @return array<string, bool|null> */
    public static function integrations(?BaseConnection $db = null): array
    {
        $secret = static function (string $k): bool {
            try {
                return ReachConfig::secret($k) !== null;
            } catch (\Throwable) {
                return false;
            }
        };
        $email = null;
        try {
            $email = Mailer::providerSummary()['providers'] !== [];
        } catch (\Throwable) {
            $email = null;
        }
        $search = false;
        try {
            $connector = ConnectorProviderFactory::searchConsole();
            $search    = $connector->isEnabled() && $connector instanceof GoogleSearchConsoleConnector
                && $connector->configurationState()['status'] === 'configured';
        } catch (\Throwable) {
            $search = false;
        }

        return [
            'gemini'         => $secret('ai.gemini.api_key'),
            'openai'         => $secret('ai.openai.api_key'),
            'shotstack'      => $secret('video.shotstack.api_key'),
            'media_signing'  => (string) (env('MEDIA_SIGNING_KEY') ?? '') !== '',
            'social_api'     => $secret('social.socialapi.api_key'),
            'dataforseo'     => trim((string) ReachConfig::get('seo.dataforseo.login', '')) !== '' && $secret('seo.dataforseo.password'),
            'search_console' => $search,
            'email'          => $email,
            'engage_push'    => (string) env('ENGAGE_API_BASE_URL', '') !== '' && (string) env('ENGAGE_INBOUND_TOKEN', '') !== '',
            'engage_inbound' => (string) env('REACH_INBOUND_ENGAGE_TOKEN', '') !== '',
        ];
    }

    // ── per source ──────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function routine(string $start, string $end, string $from, string $to): array
    {
        $out = [];
        if (! SchemaGuard::hasTable($this->db, 'reach_routine_submissions')) {
            return $out;
        }
        foreach (['blog', 'knowledge_base'] as $type) {
            $row = $this->db->query(
                "SELECT COUNT(*) AS submitted,
                        COUNT(*) FILTER (WHERE status = 'rejected') AS rejected,
                        COUNT(*) FILTER (WHERE status = 'held_high_risk') AS held,
                        COUNT(*) FILTER (WHERE status = 'pending_vetting') AS pending_vetting,
                        COUNT(*) FILTER (WHERE status = 'awaiting_cover') AS awaiting_cover,
                        COUNT(*) FILTER (WHERE status = 'publish_failed') AS publish_failed,
                        COUNT(*) FILTER (WHERE status = 'publish_blocked') AS publish_blocked,
                        COUNT(*) FILTER (WHERE status = 'live') AS live
                 FROM reach_routine_submissions WHERE content_type = ? AND created_at >= ? AND created_at < ?",
                [$type, $start, $end]
            )->getRowArray() ?? [];
            $stuck = $this->db->query(
                "SELECT COUNT(*) FILTER (WHERE status = 'pending_vetting') AS vetting,
                        COUNT(*) FILTER (WHERE status = 'awaiting_cover') AS cover
                 FROM reach_routine_submissions WHERE content_type = ? AND status IN ('pending_vetting','awaiting_cover')
                   AND created_at < NOW() - INTERVAL '6 hours'",
                [$type]
            )->getRowArray() ?? [];
            $last = $this->db->query('SELECT MAX(created_at) AS t FROM reach_routine_submissions WHERE content_type = ?', [$type])->getRowArray();
            $err  = $this->db->query(
                "SELECT last_error FROM reach_routine_submissions WHERE content_type = ? AND last_error IS NOT NULL AND updated_at >= ? ORDER BY updated_at DESC LIMIT 1",
                [$type, $start]
            )->getRowArray();
            // Days in the window on which accepted submissions reached the daily limit.
            $limit     = max(0, (int) ReachConfig::get("automation.{$type}.daily_limit", 0));
            $quotaDays = $limit <= 0 ? 0 : (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM (
                    SELECT (created_at AT TIME ZONE 'Asia/Kolkata')::date AS d, COUNT(*) AS n FROM reach_routine_submissions
                    WHERE content_type = ? AND status <> 'rejected' AND created_at >= ? AND created_at < ? GROUP BY 1) q WHERE n >= ?",
                [$type, $start, $end, $limit]
            )->getRowArray()['c'] ?? 0);

            $out[$type] = array_map('intval', $row) + [
                'stuck_vetting'      => (int) ($stuck['vetting'] ?? 0),
                'stuck_cover'        => (int) ($stuck['cover'] ?? 0),
                'last_submission_at' => $last['t'] ?? null,
                'last_error'         => $err['last_error'] ?? null,
                'quota_days'         => $quotaDays,
                'daily_limit'        => $limit,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function community(string $start, string $end): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_community_automation_submissions')) {
            return [];
        }
        $row = $this->db->query(
            "SELECT COUNT(*) AS submitted,
                    COUNT(*) FILTER (WHERE decision = 'reject' OR status = 'withdrawn') AS rejected,
                    COUNT(*) FILTER (WHERE status IN ('failed','blocked')) AS publish_failed,
                    COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                    COUNT(*) FILTER (WHERE status = 'published') AS live
             FROM reach_community_automation_submissions WHERE created_at >= ? AND created_at < ?",
            [$start, $end]
        )->getRowArray() ?? [];
        $last = $this->db->query('SELECT MAX(created_at) AS t FROM reach_community_automation_submissions')->getRowArray();
        $err  = $this->db->query("SELECT last_error FROM reach_community_automation_submissions WHERE last_error IS NOT NULL AND updated_at >= ? ORDER BY updated_at DESC LIMIT 1", [$start])->getRowArray();

        return array_map('intval', $row) + ['last_submission_at' => $last['t'] ?? null, 'last_error' => $err['last_error'] ?? null];
    }

    /** @return array<string, array<string, mixed>> */
    private function structured(string $start, string $end): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_structured_content_submissions')) {
            return [];
        }
        $out = [];
        foreach (['release_note', 'workflow_guide'] as $type) {
            $row = $this->db->query(
                "SELECT COUNT(*) AS submitted,
                        COUNT(*) FILTER (WHERE status = 'rejected') AS rejected,
                        COUNT(*) FILTER (WHERE status = 'failed') AS publish_failed,
                        COUNT(*) FILTER (WHERE status = 'pending_vetting') AS pending_vetting,
                        COUNT(*) FILTER (WHERE status = 'published') AS live
                 FROM reach_structured_content_submissions WHERE content_type = ? AND created_at >= ? AND created_at < ?",
                [$type, $start, $end]
            )->getRowArray() ?? [];
            $last = $this->db->query('SELECT MAX(created_at) AS t FROM reach_structured_content_submissions WHERE content_type = ?', [$type])->getRowArray();
            $out[$type] = array_map('intval', $row) + ['last_submission_at' => $last['t'] ?? null];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function video(string $start, string $end): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_video_pipeline_items')) {
            return [];
        }
        $out = [];
        foreach (['vlog', 'short'] as $format) {
            $row = $this->db->query(
                "SELECT COUNT(*) AS submitted,
                        COUNT(*) FILTER (WHERE status = 'rejected') AS rejected,
                        COUNT(*) FILTER (WHERE status IN ('blocked','failed')) AS publish_failed,
                        COUNT(*) FILTER (WHERE status IN ('pending_vetting','in_progress')) AS in_progress,
                        COUNT(*) FILTER (WHERE status = 'published') AS live
                 FROM reach_video_pipeline_items WHERE format = ? AND created_at >= ? AND created_at < ?",
                [$format, $start, $end]
            )->getRowArray() ?? [];
            $last = $this->db->query('SELECT MAX(created_at) AS t FROM reach_video_pipeline_items WHERE format = ?', [$format])->getRowArray();
            $err  = $this->db->query("SELECT last_error FROM reach_video_pipeline_items WHERE format = ? AND last_error IS NOT NULL AND updated_at >= ? ORDER BY updated_at DESC LIMIT 1", [$format, $start])->getRowArray();
            $out[$format] = array_map('intval', $row) + ['last_submission_at' => $last['t'] ?? null, 'last_error' => $err['last_error'] ?? null];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function social(string $start, string $end): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_social_posts')) {
            return [];
        }
        $out = [];
        foreach ($this->db->query(
            "SELECT channel,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE status = 'manual_queue') AS manual_queue,
                    COUNT(*) FILTER (WHERE status = 'publishing') AS publishing
             FROM reach_social_posts WHERE updated_at >= ? AND updated_at < ? GROUP BY channel",
            [$start, $end]
        )->getResultArray() as $r) {
            $err = $this->db->query("SELECT error_message FROM reach_social_posts WHERE channel = ? AND status = 'failed' AND updated_at >= ? ORDER BY updated_at DESC LIMIT 1", [$r['channel'], $start])->getRowArray();
            $out[(string) $r['channel']] = ['failed' => (int) $r['failed'], 'manual_queue' => (int) $r['manual_queue'], 'publishing' => (int) $r['publishing'], 'last_error' => $err['error_message'] ?? null];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> content_type => counts + top reject/revise reasons */
    private function vetting(string $start, string $end): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_ai_vetting_runs')) {
            return [];
        }
        $out = [];
        foreach ($this->db->query(
            'SELECT content_type, decision, reasons_json::text AS reasons FROM reach_ai_vetting_runs WHERE created_at >= ? AND created_at < ?',
            [$start, $end]
        )->getResultArray() as $r) {
            $type = (string) $r['content_type'];
            $dec  = strtolower((string) $r['decision']);
            $out[$type] ??= ['total' => 0, 'approve' => 0, 'revise' => 0, 'reject' => 0, 'reasons' => []];
            $out[$type]['total']++;
            if (isset($out[$type][$dec])) {
                $out[$type][$dec]++;
            }
            if ($dec === 'reject' || $dec === 'revise') {
                $list = json_decode((string) $r['reasons'], true);
                foreach (is_array($list) ? $list : [] as $reason) {
                    $text = trim(is_string($reason) ? $reason : (string) ($reason['reason'] ?? $reason['message'] ?? json_encode($reason)));
                    if ($text !== '') {
                        $text = mb_substr($text, 0, 160);
                        $out[$type]['reasons'][$text] = ($out[$type]['reasons'][$text] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($out as $type => $v) {
            arsort($v['reasons']);
            $top = [];
            foreach (array_slice($v['reasons'], 0, 3, true) as $text => $n) {
                $top[] = ['reason' => $text, 'count' => $n];
            }
            $out[$type]['top_reasons'] = $top;
            unset($out[$type]['reasons']);
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> job_type => failures in the window */
    private function jobs(string $start, string $end): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_jobs')) {
            return [];
        }
        $out = [];
        foreach ($this->db->query(
            "SELECT job_type, COUNT(*) FILTER (WHERE status = 'failed') AS failed, COUNT(*) FILTER (WHERE status = 'dead_letter') AS dead_letter,
                    (ARRAY_AGG(error_message ORDER BY updated_at DESC) FILTER (WHERE error_message IS NOT NULL))[1] AS last_error
             FROM reach_jobs WHERE status IN ('failed','dead_letter') AND updated_at >= ? AND updated_at < ? GROUP BY job_type",
            [$start, $end]
        )->getResultArray() as $r) {
            $out[(string) $r['job_type']] = ['failed' => (int) $r['failed'], 'dead_letter' => (int) $r['dead_letter'], 'last_error' => $r['last_error'] !== null ? mb_substr((string) $r['last_error'], 0, 200) : null];
        }

        return $out;
    }

    /** @return array{available:int}|null */
    private function topicQueue(): ?array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_topic_candidates')) {
            return null;
        }
        $n = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM reach_topic_candidates WHERE content_item_id IS NULL AND status IN ('candidate','scored','roadmap_selected','queued')"
        )->getRowArray()['c'] ?? 0);

        return ['available' => $n];
    }
}
