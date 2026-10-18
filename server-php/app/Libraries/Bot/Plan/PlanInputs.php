<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use App\Libraries\Automation\Routine\RoutineFlags;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Everything the plan is derived from, read once: the volume settings, the
 * routine timetable, what is scheduled (social posts, email sends, calendar,
 * content schedules, topic queue), the metrics' own history and any target
 * the self-tuner lowered. PlanGenerator turns this into numbers without I/O,
 * so a fixture array is all a test needs.
 */
class PlanInputs
{
    /**
     * Routine capacity per IST day, from docs/automation/CLAUDE_ROUTINES.md:
     * firings per day × items per firing. The daily_limit setting caps it.
     */
    public const ROUTINE_CAPACITY = [
        'blog'           => ['routine' => 'reach-blog', 'firings' => 12, 'per_run' => 1, 'cron' => '30 3-14 * * * (hourly 09:00–20:00 IST)'],
        'knowledge_base' => ['routine' => 'reach-kb', 'firings' => 2, 'per_run' => 5, 'cron' => '30 4,10 * * * (10:00 and 16:00 IST)'],
        'community'      => ['routine' => 'reach-community-qa', 'firings' => 7, 'per_run' => 3, 'cron' => '30 2-14/2 * * * (every 2 h 08:00–20:00 IST)'],
    ];

    /** Social channels the video pipeline posts every published video to (VideoPipelineRunner::SOCIAL_CHANNELS). */
    public const VIDEO_SOCIAL_CHANNELS = ['instagram', 'facebook', 'linkedin'];

    public const HISTORY_DAYS = 56;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?ActualsCollector $collector = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param string $today    IST date
     * @param string $horizon  last date any plan covers (end of next month)
     * @return array<string, mixed>
     */
    public function gather(string $today, string $horizon): array
    {
        $yesterday = PlanPeriods::addDays($today, -1);
        $history   = ($this->collector ?? new ActualsCollector($this->db))
            ->collect(PlanPeriods::addDays($today, -self::HISTORY_DAYS), $yesterday);

        return [
            'today'             => $today,
            'config'            => self::config($this->db),
            'scheduled'         => $this->scheduled($today, $horizon),
            'history'           => $history,
            'overrides'         => self::overrides($this->db),
            'self_tune_enabled' => (bool) ReachConfig::get('bot.self_tune.enabled', true),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function config(?BaseConnection $db = null): array
    {
        $db ??= Database::connect();
        $flag  = static fn (string $k): bool => (bool) ReachConfig::get($k, true);
        $limit = static fn (string $k): int => max(0, (int) ReachConfig::get($k, 0));

        $cadenceTotal = null;
        $cadenceCount = 0;
        if (SchemaGuard::hasTable($db, 'reach_kb_product_cadence')) {
            $row          = $db->query('SELECT COALESCE(SUM(daily_quota), 0) AS q, COUNT(*) AS c FROM reach_kb_product_cadence WHERE enabled = TRUE')->getRowArray();
            $cadenceTotal = (int) ($row['q'] ?? 0);
            $cadenceCount = (int) ($row['c'] ?? 0);
        }
        $keywords = null;
        if (SchemaGuard::hasTable($db, 'reach_seo_tracked_keywords')) {
            $keywords = (int) ($db->query('SELECT COUNT(*) AS c FROM reach_seo_tracked_keywords WHERE enabled = TRUE')->getRowArray()['c'] ?? 0);
        }

        return [
            'blog'           => ['enabled' => RoutineFlags::enabled('blog.enabled'), 'daily_limit' => $limit('automation.blog.daily_limit'),
                'auto_publish' => RoutineFlags::enabled('blog.auto_publish'), 'ai_cover' => RoutineFlags::enabled('blog.ai_cover')],
            'knowledge_base' => ['enabled' => RoutineFlags::enabled('knowledge_base.enabled'), 'daily_limit' => $limit('automation.knowledge_base.daily_limit'),
                'auto_publish' => RoutineFlags::enabled('knowledge_base.auto_publish'),
                'cadence_total' => $cadenceTotal, 'cadence_products' => $cadenceCount],
            'community'      => ['enabled' => $flag('automation.community.enabled'), 'daily_limit' => $limit('automation.community.daily_limit')],
            'release_note'   => ['enabled' => $flag('automation.release_note.enabled'), 'daily_limit' => $limit('automation.release_note.daily_limit')],
            'workflow_guide' => ['enabled' => $flag('automation.workflow_guide.enabled'), 'daily_limit' => $limit('automation.workflow_guide.daily_limit')],
            'video'          => ['enabled' => $flag('automation.video.enabled'), 'vlogs' => $limit('video.daily.vlogs'), 'shorts' => $limit('video.daily.shorts'),
                'youtube_publisher' => (string) ReachConfig::get('video.youtube.publisher', 'social_api')],
            'seo'            => ['enabled' => $flag('seo.tracker.enabled'), 'max_per_day' => $limit('seo.tracker.max_keywords_per_day'), 'active_keywords' => $keywords],
        ];
    }

    /** @return array<string, array<string, mixed>> metric => override row (active only) */
    public static function overrides(?BaseConnection $db = null): array
    {
        $db ??= Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_bot_target_overrides')) {
            return [];
        }
        $out = [];
        foreach ($db->table('reach_bot_target_overrides')->where('active', true)->get()->getResultArray() as $row) {
            $out[(string) $row['metric_key']] = [
                'target_value' => (float) $row['target_value'],
                'base_value'   => (float) $row['base_value'],
                'cause'        => (string) $row['cause'],
                'reason'       => (string) $row['reason'],
                'since'        => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Scheduled work per IST date: social posts per channel, email recipients,
     * calendar entries, content schedules; plus the topic queue (not dated).
     *
     * @return array<string, mixed>
     */
    private function scheduled(string $from, string $to): array
    {
        $start = PlanPeriods::istMidnightUtc($from);
        $end   = PlanPeriods::istMidnightUtc(PlanPeriods::addDays($to, 1));
        $ist   = static fn (string $col): string => "({$col} AT TIME ZONE 'Asia/Kolkata')::date::text";
        $out   = ['social' => [], 'email' => [], 'calendar' => [], 'content_schedules' => [], 'campaigns' => [], 'topic_queue' => null, 'items' => []];

        if (SchemaGuard::hasTable($this->db, 'reach_social_posts')) {
            foreach ($this->db->query(
                "SELECT {$ist('scheduled_at')} AS d, channel, COUNT(*) AS c FROM reach_social_posts
                 WHERE status IN ('approved','scheduled','pending_approval') AND scheduled_at >= ? AND scheduled_at < ?
                 GROUP BY 1, 2",
                [$start, $end]
            )->getResultArray() as $r) {
                $out['social'][$r['d']][$r['channel']] = (int) $r['c'];
            }
            foreach ($this->db->query(
                "SELECT id, channel, title, scheduled_at FROM reach_social_posts
                 WHERE status IN ('approved','scheduled','pending_approval') AND scheduled_at >= ? AND scheduled_at < ?
                 ORDER BY scheduled_at LIMIT 25",
                [$start, $end]
            )->getResultArray() as $r) {
                $out['items'][] = ['kind' => 'social', 'id' => (int) $r['id'], 'title' => (string) ($r['title'] ?: ucfirst((string) $r['channel']) . ' post'), 'channel' => $r['channel'], 'at' => $r['scheduled_at']];
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_campaign_dispatches')) {
            foreach ($this->db->query(
                "SELECT {$ist('scheduled_at')} AS d, COALESCE(SUM(NULLIF(total_recipients, 0)), 0) AS r, COUNT(*) AS c FROM reach_campaign_dispatches
                 WHERE channel = 'email' AND status IN ('queued','paused') AND scheduled_at >= ? AND scheduled_at < ?
                 GROUP BY 1",
                [$start, $end]
            )->getResultArray() as $r) {
                $out['email'][$r['d']] = ['recipients' => (int) $r['r'], 'dispatches' => (int) $r['c']];
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_content_calendar_items')) {
            foreach ($this->db->query(
                'SELECT date::text AS d, item_kind, title FROM reach_content_calendar_items WHERE date BETWEEN ? AND ? ORDER BY date, id LIMIT 200',
                [$from, $to]
            )->getResultArray() as $r) {
                $out['calendar'][$r['d']][$r['item_kind']] = ($out['calendar'][$r['d']][$r['item_kind']] ?? 0) + 1;
                if (count($out['items']) < 60) {
                    $out['items'][] = ['kind' => 'calendar', 'item_kind' => $r['item_kind'], 'title' => (string) $r['title'], 'at' => $r['d']];
                }
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_content_schedules')) {
            foreach ($this->db->query(
                "SELECT {$ist('scheduled_at')} AS d, COUNT(*) AS c FROM reach_content_schedules
                 WHERE schedule_status IN ('pending','approved','ready') AND scheduled_at >= ? AND scheduled_at < ? GROUP BY 1",
                [$start, $end]
            )->getResultArray() as $r) {
                $out['content_schedules'][$r['d']] = (int) $r['c'];
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_campaigns')) {
            foreach ($this->db->query(
                "SELECT id, name, start_date::text AS s, end_date::text AS e, status FROM reach_campaigns
                 WHERE status IN ('approved','scheduled','active','dispatching') AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)
                 ORDER BY start_date LIMIT 20",
                [$to, $from]
            )->getResultArray() as $r) {
                $out['campaigns'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'start' => $r['s'], 'end' => $r['e'], 'status' => $r['status']];
            }
        }
        if (SchemaGuard::hasTable($this->db, 'reach_topic_candidates')) {
            $row = $this->db->query(
                "SELECT COUNT(*) FILTER (WHERE status IN ('candidate','scored','roadmap_selected','queued')) AS available,
                        COUNT(*) FILTER (WHERE status = 'roadmap_selected') AS selected
                 FROM reach_topic_candidates WHERE content_item_id IS NULL AND (cooldown_until IS NULL OR cooldown_until < NOW())"
            )->getRowArray();
            $out['topic_queue'] = ['available' => (int) ($row['available'] ?? 0), 'roadmap_selected' => (int) ($row['selected'] ?? 0)];
        }

        return $out;
    }
}
