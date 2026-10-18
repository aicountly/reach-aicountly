<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

/**
 * Explains a lag from LagSignals. Pure: (metric, signals) → causes, ordered
 * by how much each one explains (weight). Every cause names its evidence;
 * owner_action marks the ones only the owner can fix (keys, switches,
 * routines), bot_action what the bot does about it on its own.
 */
final class RootCauseClassifier
{
    /** Integration → [label, where to fix it]. */
    public const INTEGRATIONS = [
        'gemini'         => ['Gemini API key', 'Settings → AI & Automation → AI providers → Gemini API key (vetting cannot run without it; unvetted content is never published).'],
        'openai'         => ['OpenAI API key', 'Settings → AI & Automation → AI providers → OpenAI API key (covers and the video voice-over).'],
        'shotstack'      => ['Shotstack API key', 'Settings → AI & Automation → Video → Shotstack API key.'],
        'media_signing'  => ['MEDIA_SIGNING_KEY', 'Set MEDIA_SIGNING_KEY in api/.env (the renderer fetches voice-overs through signed URLs).'],
        'social_api'     => ['social-api.ai API key', 'Settings → AI & Automation → Social → social-api.ai API key, then run its live check.'],
        'dataforseo'     => ['DataForSEO credentials', 'Settings → AI & Automation → SEO data → DataForSEO login + password.'],
        'search_console' => ['Google Search Console', 'Connect Search Console — docs/operations/SEARCH_CONSOLE_SETUP.md.'],
        'email'          => ['Email provider', 'Bind an email provider to Reach in Console → Email providers.'],
        'engage_push'    => ['Engage lead push', 'Set ENGAGE_API_BASE_URL and ENGAGE_INBOUND_TOKEN in api/.env.'],
        'engage_inbound' => ['Engage outcome feed', 'Set REACH_INBOUND_ENGAGE_TOKEN in api/.env and give Engage the same token (docs/integrations/ENGAGE_OUTCOMES.md).'],
    ];

    /** @return list<string> integrations a metric depends on */
    public static function requirements(string $metric, array $signals = []): array
    {
        $publisher = (string) ($signals['config']['video']['youtube_publisher'] ?? 'social_api');

        return match (true) {
            str_starts_with($metric, 'content.')     => ['gemini'],
            $metric === 'covers.generated'          => ['openai'],
            str_starts_with($metric, 'video.')       => array_merge(['gemini', 'openai', 'shotstack', 'media_signing'], $publisher === 'social_api' ? ['social_api'] : []),
            str_starts_with($metric, 'social.')      => ['social_api'],
            $metric === 'email.accepted'             => ['email'],
            $metric === 'seo.checks_run'             => ['dataforseo'],
            str_starts_with($metric, 'search.')      => ['search_console'],
            $metric === 'leads.pushed'               => ['engage_push'],
            str_starts_with($metric, 'engage.')      => ['engage_inbound'],
            default                                  => [],
        };
    }

    /**
     * @param array<string, mixed> $perf     the metric's PerformanceService row (target, achieved, …)
     * @param array<string, mixed> $signals  LagSignals::gather()
     * @param list<string>         $laggingOutputs other output metrics lagging in the same window
     * @return list<array<string, mixed>>
     */
    public static function classify(string $metric, array $perf, array $signals, array $laggingOutputs = []): array
    {
        $meta   = PlanMetrics::get($metric);
        $causes = [];
        $add    = static function (string $code, int $weight, string $label, string $detail, bool $owner, ?string $fix = null, ?string $bot = null) use (&$causes): void {
            $causes[] = ['code' => $code, 'weight' => $weight, 'label' => $label, 'detail' => $detail, 'owner_action' => $owner, 'fix' => $fix, 'bot_action' => $bot];
        };

        // 1. Switched off.
        $type = (string) ($meta['content_type'] ?? '');
        $cfgKey = match (true) {
            str_starts_with($metric, 'video.')  => 'video',
            $metric === 'seo.checks_run'        => 'seo',
            $metric === 'covers.generated'      => 'blog',
            default                             => $type,
        };
        $cfg = (array) ($signals['config'][$cfgKey] ?? []);
        if ($cfg !== [] && array_key_exists('enabled', $cfg) && ! $cfg['enabled']) {
            $add('automation_off', 100, 'Automation switched off', "The {$cfgKey} automation switch is off.", true, 'Turn it back on in Settings → AI & Automation if the volume is still wanted.');
        }
        if ($cfg !== [] && array_key_exists('auto_publish', $cfg) && ! $cfg['auto_publish'] && $metric !== 'covers.generated') {
            $add('automation_off', 100, 'Auto-publish off', "Auto-publish is off for {$cfgKey} (setting or .env kill-switch): approved items wait for a person.", true, "Turn on automation.{$cfgKey}.auto_publish and remove any *_AUTO_PUBLISH_ENABLED=false line from api/.env.");
        }
        if ($metric === 'covers.generated' && array_key_exists('ai_cover', $cfg) && ! $cfg['ai_cover']) {
            $add('automation_off', 100, 'AI covers off', 'automation.blog.ai_cover (or BLOG_IMAGE_GENERATION_ENABLED=false) is off.', true, 'Turn on automation.blog.ai_cover.');
        }

        // 2. Integration not configured.
        $integ = (array) ($signals['integrations'] ?? []);
        foreach (self::requirements($metric, $signals) as $req) {
            if (array_key_exists($req, $integ) && $integ[$req] === false) {
                [$label, $fix] = self::INTEGRATIONS[$req];
                $add('integration_missing', 90, "{$label} not configured", "{$label} is not set, so this cannot happen.", true, $fix,
                    'Target lowered to 0 until it is configured (restored automatically once it is).');
            }
        }

        // 3–7. Pipeline signals for the source that feeds the metric.
        $src = self::source($metric, $signals);
        $days = (int) ($signals['window']['days'] ?? 1);
        if ($src !== null) {
            $routineName = (string) ($meta['routine'] ?? '');
            $submitted   = (int) ($src['submitted'] ?? 0);
            if ($routineName !== '' && $submitted === 0) {
                $last   = $src['last_submission_at'] ?? null;
                $demand = in_array($metric, ['content.release_note.published', 'content.workflow_guide.published'], true);
                $add('routine_inactive', $demand ? 25 : 80, 'Routine not submitting', sprintf('No drafts arrived from the %s routine in the last %d day(s)%s.', $routineName, $days,
                    $last ? ' (last one ' . substr((string) $last, 0, 16) . ')' : ' (none ever)'), ! $demand,
                    "Check the Claude routine `{$routineName}` is installed and firing (docs/automation/routines/{$routineName}.md) and that its environment can reach reach.aicountly.org.");
            }
            $rejected = (int) ($src['rejected'] ?? 0);
            if ($submitted > 0 && $rejected > 0 && $rejected / $submitted >= 0.25) {
                $vetType = $type === 'vlog' || $type === 'short' ? 'video_script' : $type;
                $reasons = array_map(static fn ($r) => $r['reason'] . ' (×' . $r['count'] . ')', (array) ($signals['vetting'][$vetType]['top_reasons'] ?? []));
                $add('vetting_rejections', 60, 'Vetting rejections', sprintf('Gemini rejected %d of %d submission(s)%s', $rejected, $submitted,
                    $reasons === [] ? '.' : ': ' . implode('; ', $reasons) . '.'), false, null,
                    'The routine briefing lists these reasons first so the next drafts avoid them.');
            }
            $stuck = (int) ($src['stuck_vetting'] ?? 0) + (int) ($src['stuck_cover'] ?? 0) + (int) ($src['pending_vetting'] ?? 0) + (int) ($src['in_progress'] ?? 0) + (int) ($src['pending'] ?? 0);
            if ($stuck > 0) {
                $add('pipeline_stuck', 50, 'Waiting in the pipeline', sprintf('%d item(s) are still waiting (vetting, cover or render)%s.', $stuck,
                    ! empty($src['last_error']) ? ' — last error: ' . mb_substr((string) $src['last_error'], 0, 140) : ''), false, null,
                    'Stuck items are brought forward for an immediate retry (bounded, once a day each).');
            }
            $failed = (int) ($src['publish_failed'] ?? 0) + (int) ($src['publish_blocked'] ?? 0) + (int) ($src['failed'] ?? 0);
            if ($failed > 0) {
                $add('publish_failures', 55, 'Publish failures', sprintf('%d item(s) failed or were blocked at publishing%s.', $failed,
                    ! empty($src['last_error']) ? ' — last error: ' . mb_substr((string) $src['last_error'], 0, 140) : ''), $failed >= 3,
                    $failed >= 3 ? 'Repeated publish failures: check the publisher connection (Publishing → Deployments / Job Monitor).' : null,
                    'The sweeps retry failed publishes on their own.');
            }
            if (str_starts_with($metric, 'social.') && (int) ($src['manual_queue'] ?? 0) > 0) {
                $add('integration_missing', 85, 'No publisher for this network', sprintf('%d post(s) wait in the manual queue because no social publisher can post to this network.', (int) $src['manual_queue']), true,
                    self::INTEGRATIONS['social_api'][1]);
            }
        }

        // Job failures attributed to this metric.
        $jobFail = 0;
        $jobErr  = null;
        foreach ((array) ($signals['jobs'] ?? []) as $jobType => $j) {
            foreach ((array) $meta['jobs'] as $prefix) {
                if (str_starts_with((string) $jobType, (string) $prefix)) {
                    $jobFail += (int) $j['failed'] + (int) $j['dead_letter'];
                    $jobErr ??= $j['last_error'] ? "{$jobType}: {$j['last_error']}" : null;
                    break;
                }
            }
        }
        if ($jobFail > 0) {
            $add('job_failures', 40, 'Failed jobs', sprintf('%d related job(s) failed or dead-lettered%s.', $jobFail, $jobErr ? ' — ' . mb_substr($jobErr, 0, 160) : ''), $jobFail >= 5,
                $jobFail >= 5 ? 'Inspect them in Admin → Job Monitor.' : null, 'Idempotent ingest/sync jobs are re-queued automatically.');
        }

        // Topic supply (blog).
        if ($metric === 'content.blog.published' && isset($signals['topic_queue']['available']) && (int) $signals['topic_queue']['available'] === 0) {
            $add('no_topics', 30, 'Topic queue empty', 'No unused topic candidates are queued (the routine still proposes its own topics).', false, null,
                'The briefing points the routine at the best-performing categories.');
        }

        // Search: Google's reporting lag is not a lag of ours.
        if ($meta['kind'] === 'outcome' && ($perf['days_without_data'] ?? 0) > 0) {
            $add('data_lag', 5, 'Source has not reported yet', sprintf('%d day(s) in the range have no data yet%s.', (int) $perf['days_without_data'],
                ($meta['lagged_days'] ?? 0) > 0 ? ' (Search Console publishes with a ~3 day lag)' : ''), false);
        }
        $videoLags = array_values(array_filter($laggingOutputs, static fn ($m) => str_starts_with($m, 'video.')));
        if ((str_starts_with($metric, 'social.') || $metric === 'covers.generated') && ($videoLags !== [] || in_array('content.blog.published', $laggingOutputs, true))) {
            $up = $metric === 'covers.generated' ? array_intersect(['content.blog.published'], $laggingOutputs) : $videoLags;
            if ($up !== []) {
                $add('upstream_output', 45, 'Fewer items upstream', 'Planned from items that also lagged: ' . implode(', ', array_map([PlanMetrics::class, 'label'], $up)) . '.', false, null,
                    'Closes when the upstream lag does.');
            }
        }
        if ($meta['kind'] === 'outcome' && $laggingOutputs !== []) {
            $add('upstream_output', 30, 'Less output upstream', 'Planned output was also behind: ' . implode(', ', array_map([PlanMetrics::class, 'label'], array_slice($laggingOutputs, 0, 4))) . '.', false, null,
                'Fixing those output lags is what moves this outcome.');
        }
        if (in_array($metric, ['content.release_note.published', 'content.workflow_guide.published'], true) && $causes === []) {
            $add('demand_driven', 10, 'Fewer product changes', 'These follow shipped product changes; fewer were shipped than the trend expected.', false);
        }

        if ($causes === []) {
            $add('unexplained', 0, 'No pipeline signal explains it',
                $meta['kind'] === 'outcome' ? 'Below its own recent trend with every source configured — see the daily report\'s declined queries and pages.' : 'Every switch and integration is on and nothing failed; the volume simply fell short.', false);
        }

        usort($causes, static fn ($a, $b) => $b['weight'] <=> $a['weight']);

        // One row per code (the strongest), so the table stays readable.
        $seen = [];
        $out  = [];
        foreach ($causes as $c) {
            $k = $c['code'] . '|' . $c['label'];
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $out[]    = $c;
            }
        }

        return $out;
    }

    /** Structural = nothing the pipeline can do will close the gap until the owner acts. */
    public static function isStructural(array $causes): bool
    {
        foreach ($causes as $c) {
            if (in_array($c['code'], ['automation_off', 'integration_missing'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private static function source(string $metric, array $s): ?array
    {
        return match (true) {
            $metric === 'content.blog.published'           => $s['routine']['blog'] ?? null,
            $metric === 'content.knowledge_base.published' => $s['routine']['knowledge_base'] ?? null,
            $metric === 'covers.generated'                 => isset($s['routine']['blog']) ? ['stuck_cover' => $s['routine']['blog']['stuck_cover'] ?? 0, 'submitted' => $s['routine']['blog']['submitted'] ?? 0] : null,
            $metric === 'content.community.published'      => $s['community'] ?: null,
            $metric === 'content.release_note.published'   => $s['structured']['release_note'] ?? null,
            $metric === 'content.workflow_guide.published' => $s['structured']['workflow_guide'] ?? null,
            $metric === 'video.vlog.published'             => $s['video']['vlog'] ?? null,
            $metric === 'video.short.published'            => $s['video']['short'] ?? null,
            str_starts_with($metric, 'social.')            => $s['social'][explode('.', $metric)[1]] ?? null,
            default                                        => null,
        };
    }
}
