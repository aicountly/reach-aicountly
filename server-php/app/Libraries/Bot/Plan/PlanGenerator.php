<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

/**
 * Turns PlanInputs into a plan for one period. Pure and deterministic: the
 * same inputs always give the same items, every number says where it came
 * from (source), how (method), how sure we are (confidence) and why
 * (rationale). No model call produces a number.
 *
 * Sources:
 *   config    a volume setting capped by the routine's capacity
 *             (blog/KB/community, videos, covers, SEO checks)
 *   schedule  what is already scheduled in Reach (social posts, email sends)
 *   mixed     scheduled + config-driven (social posts the video pipeline makes)
 *   trend     forecast from the metric's own history (PlanForecaster) for
 *             demand-driven outputs (release notes, guides) and outcomes
 *   self_tune a config/schedule target the self-tuner lowered (see overrides)
 */
final class PlanGenerator
{
    public const METHOD_VERSION = 'v1';

    /**
     * @param array<string, mixed> $inputs PlanInputs::gather()
     * @return array{items: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function generate(array $inputs, string $type, string $start, string $end): array
    {
        $dates   = PlanPeriods::dates($start, $end);
        $histEnd = PlanPeriods::addDays((string) $inputs['today'], -1);
        $items   = [];

        foreach (PlanMetrics::all() as $key => $meta) {
            $daily = self::dailyRule($key, $inputs);
            if ($daily === null) {
                $items[] = self::trendItem($key, $meta, $inputs, $histEnd, $start, $end);
                continue;
            }
            $items[] = self::summed($key, $meta, $daily, $dates, $inputs);
        }

        $sched = (array) ($inputs['scheduled'] ?? []);
        $inPeriod = static fn (array $byDate): array => array_intersect_key($byDate, array_flip($dates));

        return [
            'items'   => $items,
            'summary' => [
                'period'            => ['type' => $type, 'start' => $start, 'end' => $end, 'days' => count($dates)],
                'method_version'    => self::METHOD_VERSION,
                'self_tune_enabled' => (bool) ($inputs['self_tune_enabled'] ?? true),
                'routines'          => PlanInputs::ROUTINE_CAPACITY,
                'scheduled'         => [
                    'social_posts'      => array_sum(array_map('array_sum', $inPeriod((array) ($sched['social'] ?? [])))),
                    'email_dispatches'  => array_sum(array_column($inPeriod((array) ($sched['email'] ?? [])), 'dispatches')),
                    'email_recipients'  => array_sum(array_column($inPeriod((array) ($sched['email'] ?? [])), 'recipients')),
                    'calendar_entries'  => array_sum(array_map('array_sum', $inPeriod((array) ($sched['calendar'] ?? [])))),
                    'content_schedules' => array_sum($inPeriod((array) ($sched['content_schedules'] ?? []))),
                    'campaigns'         => array_values(array_filter((array) ($sched['campaigns'] ?? []), static fn ($c) => ($c['start'] ?? '') <= $end && (($c['end'] ?? null) === null || $c['end'] >= $start))),
                    'topic_queue'       => $sched['topic_queue'] ?? null,
                    'items'             => array_values(array_filter((array) ($sched['items'] ?? []), static fn ($i) => substr((string) $i['at'], 0, 10) >= $start && substr((string) $i['at'], 0, 10) <= $end)),
                ],
            ],
        ];
    }

    /** A stable fingerprint of a plan's numbers (what decides whether a regeneration is a new version). */
    public static function fingerprint(array $items): string
    {
        $rows = array_map(static fn (array $i): array => [
            $i['metric_key'], $i['status'], $i['target_value'], $i['base_target'], $i['low_value'], $i['high_value'], $i['source'], $i['confidence'], $i['rationale'],
        ], $items);

        return hash('sha256', (string) json_encode($rows));
    }

    // ── rules ───────────────────────────────────────────────────────────────

    /**
     * The per-day rule for a config/schedule-driven metric, or null for a
     * trend metric. Returns fn(date): [target, status, rationale, source, detail].
     *
     * @return (callable(string): array{0: float, 1: string, 2: string, 3: string, 4: array<string, mixed>})|null
     */
    private static function dailyRule(string $key, array $in): ?callable
    {
        $c     = (array) $in['config'];
        $sched = (array) ($in['scheduled'] ?? []);

        $routine = static function (string $type, string $label) use ($c): callable {
            return static function (string $date) use ($c, $type, $label): array {
                $cfg = (array) ($c[$type] ?? []);
                if (empty($cfg['enabled'])) {
                    return [0.0, 'off', "{$label} automation is switched off (automation.{$type}.enabled).", 'config', ['enabled' => false]];
                }
                if (array_key_exists('auto_publish', $cfg) && ! $cfg['auto_publish']) {
                    return [0.0, 'off', "Auto-publish is off for {$label} (automation.{$type}.auto_publish or its .env kill-switch), so nothing publishes without a person.", 'config', ['auto_publish' => false]];
                }
                $cap   = PlanInputs::ROUTINE_CAPACITY[$type];
                $capN  = $cap['firings'] * $cap['per_run'];
                $limit = (int) ($cfg['daily_limit'] ?? 0);
                $n     = min($limit, $capN);
                $why   = sprintf('automation.%s.daily_limit = %d; the %s routine fires %d× a day, up to %d per run (capacity %d).',
                    $type, $limit, $cap['routine'], $cap['firings'], $cap['per_run'], $capN);
                $detail = ['daily_limit' => $limit, 'routine_capacity' => $capN, 'routine' => $cap['routine']];
                if ($type === 'knowledge_base' && isset($cfg['cadence_total'])) {
                    $n   = min($n, (int) $cfg['cadence_total']);
                    $why .= sprintf(' Product cadence tiers allow %d a day across %d products.', (int) $cfg['cadence_total'], (int) ($cfg['cadence_products'] ?? 0));
                    $detail['cadence_total'] = (int) $cfg['cadence_total'];
                }

                return [(float) $n, 'target', $why, 'config', $detail];
            };
        };

        $videosPerDay = static function () use ($c): array {
            $v = (array) ($c['video'] ?? []);
            if (empty($v['enabled'])) {
                return [0, 'Video automation is off'];
            }

            return [(int) ($v['vlogs'] ?? 0) + (int) ($v['shorts'] ?? 0), sprintf('%d vlog + %d Short a day', (int) ($v['vlogs'] ?? 0), (int) ($v['shorts'] ?? 0))];
        };

        return match (true) {
            $key === 'content.blog.published'           => $routine('blog', 'Blog'),
            $key === 'content.knowledge_base.published' => $routine('knowledge_base', 'Knowledge Base'),
            $key === 'content.community.published'      => $routine('community', 'Community Q&A'),
            $key === 'video.vlog.published', $key === 'video.short.published' => static function (string $date) use ($c, $key): array {
                $v      = (array) ($c['video'] ?? []);
                $format = $key === 'video.vlog.published' ? 'vlogs' : 'shorts';
                if (empty($v['enabled'])) {
                    return [0.0, 'off', 'Video automation is switched off (automation.video.enabled).', 'config', ['enabled' => false]];
                }

                return [(float) ($v[$format] ?? 0), 'target', sprintf('video.daily.%s = %d a day (the reach-video routine submits the scripts).', $format, (int) ($v[$format] ?? 0)), 'config', ['daily' => (int) ($v[$format] ?? 0)]];
            },
            str_starts_with($key, 'social.') => static function (string $date) use ($key, $sched, $c, $videosPerDay): array {
                $channel   = explode('.', $key)[1];
                $scheduled = (int) ($sched['social'][$date][$channel] ?? 0);
                [$perDay, $videoWhy] = $videosPerDay();
                $fromVideo = 0;
                if ($perDay > 0 && in_array($channel, PlanInputs::VIDEO_SOCIAL_CHANNELS, true)) {
                    $fromVideo = $perDay;
                }
                if ($perDay > 0 && $channel === 'youtube' && (($c['video']['youtube_publisher'] ?? 'social_api') === 'social_api')) {
                    $fromVideo = $perDay;
                }
                $parts = [];
                if ($scheduled > 0) {
                    $parts[] = "{$scheduled} post(s) scheduled in Reach";
                }
                if ($fromVideo > 0) {
                    $parts[] = $channel === 'youtube'
                        ? "{$fromVideo} video upload(s) a day through social-api.ai ({$videoWhy})"
                        : "one share of each published video: {$fromVideo} a day ({$videoWhy})";
                }
                $why = $parts === [] ? 'Nothing is scheduled for this network and no pipeline posts to it automatically.' : implode('; ', $parts) . '.';

                return [(float) ($scheduled + $fromVideo), 'target', $why, $fromVideo > 0 ? ($scheduled > 0 ? 'mixed' : 'config') : 'schedule',
                    ['scheduled' => $scheduled, 'from_video' => $fromVideo]];
            },
            $key === 'email.accepted' => static function (string $date) use ($sched): array {
                $e = (array) ($sched['email'][$date] ?? []);
                $n = (int) ($e['recipients'] ?? 0);

                return [(float) $n, 'target', $n > 0
                    ? sprintf('%d email send(s) scheduled to %d recipient(s).', (int) ($e['dispatches'] ?? 0), $n)
                    : 'No campaign email is scheduled.', 'schedule', ['dispatches' => (int) ($e['dispatches'] ?? 0), 'recipients' => $n]];
            },
            $key === 'covers.generated' => static function (string $date) use ($c, $routine): array {
                $blog = $routine('blog', 'Blog')($date);
                if ($blog[1] === 'off') {
                    return [0.0, 'off', 'No routine blogs are planned, so no covers. ' . $blog[2], 'config', $blog[4]];
                }
                if (empty($c['blog']['ai_cover'])) {
                    return [0.0, 'off', 'AI covers are switched off (automation.blog.ai_cover or BLOG_IMAGE_GENERATION_ENABLED).', 'config', ['ai_cover' => false]];
                }

                return [$blog[0], 'target', sprintf('One GPT Image cover per routine blog (%d blog(s) planned a day).', (int) $blog[0]), 'config', ['blogs' => (int) $blog[0]]];
            },
            $key === 'seo.checks_run' => static function (string $date) use ($c): array {
                $s = (array) ($c['seo'] ?? []);
                if (empty($s['enabled'])) {
                    return [0.0, 'off', 'SEO Tracker daily checks are switched off (seo.tracker.enabled).', 'config', ['enabled' => false]];
                }
                $max = (int) ($s['max_per_day'] ?? 0);
                $kw  = $s['active_keywords'];
                $n   = $kw === null ? $max : min($max, (int) $kw);

                return [(float) $n, 'target', sprintf('seo.tracker.max_keywords_per_day = %d; %s tracked keyword(s) enabled.', $max, $kw === null ? 'unknown' : (string) $kw), 'config',
                    ['max_per_day' => $max, 'active_keywords' => $kw]];
            },
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string>         $dates
     */
    private static function summed(string $key, array $meta, callable $daily, array $dates, array $in): array
    {
        $total    = 0.0;
        $statuses = [];
        $sources  = [];
        $whys     = [];
        $detail   = [];
        foreach ($dates as $d) {
            [$n, $status, $why, $source, $det] = $daily($d);
            $total += $n;
            $statuses[$status] = true;
            $sources[$source]  = true;
            $whys[$why]        = ($whys[$why] ?? 0) + 1;
            $detail            = $det;
        }
        $status = isset($statuses['target']) ? 'target' : 'off';
        $source = count($sources) === 1 ? (string) array_key_first($sources) : 'mixed';
        $days   = count($dates);
        arsort($whys);
        $rationale = count($whys) === 1 ? (string) array_key_first($whys) : implode(' ', array_map(static fn ($w, $n) => "{$w} ({$n} day" . ($n === 1 ? '' : 's') . ')', array_keys($whys), $whys));

        $item = self::item($key, $meta, [
            'status'        => $status,
            'target_value'  => $total,
            'base_target'   => $total,
            'source'        => $source,
            'method'        => $days === 1
                ? ($source === 'schedule' ? 'Count of what is scheduled for the day.' : 'Daily volume setting, capped by routine capacity.')
                : sprintf('Sum of the %d daily targets in the period (%s).', $days, $source === 'schedule' ? 'scheduled items per day' : 'daily setting × days, plus anything scheduled'),
            'confidence'    => $status === 'off' ? 'none' : 'high',
            'rationale'     => $rationale,
            'source_detail' => $detail + ['days' => $days],
        ]);

        // A target the self-tuner lowered (only while bot.self_tune.enabled).
        $ov = $in['overrides'][$key] ?? null;
        if ($ov !== null && ! empty($in['self_tune_enabled']) && $status === 'target') {
            $perDay = $total / max(1, $days);
            $lowered = min($perDay, (float) $ov['target_value']);
            if ($lowered < $perDay) {
                $item['target_value'] = round($lowered * $days, 2);
                $item['source']       = 'self_tune';
                $item['rationale']    = sprintf('Lowered by the self-tuner from %s to %s a day: %s Base plan: %s', self::n($perDay), self::n($lowered), $ov['reason'], $rationale);
                $item['source_detail'] += ['override' => $ov];
            }
        }

        return $item;
    }

    /** @param array<string, mixed> $meta */
    private static function trendItem(string $key, array $meta, array $in, string $histEnd, string $start, string $end): array
    {
        $series = (array) ($in['history']['series'][$key] ?? []);
        $first  = $in['history']['first_dates'][$key] ?? null;
        $f      = PlanForecaster::forecast($series, $first, $histEnd, $start, $end, (string) $meta['unit']);

        $extra = '';
        if ($meta['kind'] === 'output') {
            $type = (string) $meta['content_type'];
            $cfg  = (array) ($in['config'][$type] ?? []);
            if (array_key_exists('enabled', $cfg) && ! $cfg['enabled']) {
                return self::item($key, $meta, [
                    'status' => 'off', 'target_value' => 0.0, 'base_target' => 0.0, 'source' => 'config', 'confidence' => 'none',
                    'method' => 'Automation switched off.', 'rationale' => "automation.{$type}.enabled is off.", 'source_detail' => ['enabled' => false],
                ]);
            }
            $extra = sprintf(' Demand-driven: the routine writes one per product change, so volume follows shipping; automation.%s.daily_limit (%d) is a safety cap, not a target.', $type, (int) ($cfg['daily_limit'] ?? 0));
            if ($f['target'] !== null && isset($cfg['daily_limit'])) {
                $cap = (float) $cfg['daily_limit'] * PlanPeriods::days($start, $end);
                $f['target'] = min($f['target'], $cap);
                $f['high']   = $f['high'] === null ? null : min($f['high'], $cap);
            }
        }
        $note = (string) ($in['history']['notes'][$key] ?? '');

        return self::item($key, $meta, [
            'status'        => $f['status'],
            'target_value'  => $f['target'],
            'base_target'   => $f['target'],
            'low_value'     => $f['low'],
            'high_value'    => $f['high'],
            'source'        => 'trend',
            'method'        => $f['method'],
            'confidence'    => $f['confidence'],
            'rationale'     => ($f['status'] === 'baseline'
                ? 'Baseline being established — not enough history to set an honest expectation yet.'
                : 'Expected from the metric\'s own recent history.') . $extra . ($note !== '' ? ' ' . $note : ''),
            'source_detail' => ['days_used' => $f['days_used'], 'level_per_day' => $f['level'], 'slope_per_day' => $f['slope'], 'first_data_date' => $first],
        ]);
    }

    /** @param array<string, mixed> $meta */
    private static function item(string $key, array $meta, array $v): array
    {
        return [
            'metric_key'    => $key,
            'kind'          => $meta['kind'],
            'metric_group'  => $meta['group'],
            'channel'       => $meta['channel'],
            'content_type'  => $meta['content_type'],
            'unit'          => $meta['unit'],
            'status'        => $v['status'],
            'target_value'  => $v['target_value'] === null ? null : round((float) $v['target_value'], 2),
            'base_target'   => $v['base_target'] === null ? null : round((float) $v['base_target'], 2),
            'low_value'     => $v['low_value'] ?? null,
            'high_value'    => $v['high_value'] ?? null,
            'source'        => $v['source'],
            'method'        => $v['method'],
            'confidence'    => $v['confidence'],
            'rationale'     => $v['rationale'],
            'source_detail' => $v['source_detail'] ?? [],
        ];
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
