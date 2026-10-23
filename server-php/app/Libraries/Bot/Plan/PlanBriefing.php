<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The compact "lessons" the Claude routines read before they write
 * (GET v1/automation/plan/briefing?type=blog). It is how the bot learns
 * from its lags: what today's plan expects, how yesterday went, the top 3
 * lags of the last 7 days with their cause and what the bot changed, the
 * vetting reasons to avoid, and which topics performed.
 *
 * Built from the stored daily review (no heavy queries on the routine's
 * path); computed live only before the first review exists.
 */
class PlanBriefing
{
    /** Routine content type → its metrics and vetting type. */
    public const TYPES = [
        'blog'           => ['metrics' => ['content.blog.published', 'covers.generated'], 'vetting' => 'blog'],
        'knowledge_base' => ['metrics' => ['content.knowledge_base.published'], 'vetting' => 'knowledge_base'],
        'community'      => ['metrics' => ['content.community.published'], 'vetting' => 'community'],
        'release_note'   => ['metrics' => ['content.release_note.published'], 'vetting' => 'release_note'],
        'workflow_guide' => ['metrics' => ['content.workflow_guide.published'], 'vetting' => 'workflow_guide'],
        'video'          => ['metrics' => ['video.vlog.published', 'video.short.published'], 'vetting' => 'video_script'],
        'growth'         => ['metrics' => ['search.clicks', 'search.impressions', 'leads.captured', 'engage.conversions'], 'vetting' => null],
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?LagSignals $signals = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function build(?string $type = null, ?\DateTimeImmutable $now = null): array
    {
        if ($type !== null && ! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('type must be one of: ' . implode(', ', array_keys(self::TYPES)));
        }
        $today     = PlanPeriods::today($now);
        $yesterday = PlanPeriods::addDays($today, -1);
        $perf      = new PerformanceService($this->db, null, $this->signals);
        $review    = $perf->latestReview();
        $live      = false;
        if ($review === null) {
            $review = $perf->review($yesterday);
            $live   = true;
        }

        $plan  = (new PlanService($this->db))->view('today', $now);
        $focus = $type !== null ? self::TYPES[$type]['metrics'] : null;
        $pick  = static fn (array $rows, string $key = 'metric_key') => $focus === null ? $rows : array_values(array_filter($rows, static fn ($r) => in_array($r[$key], $focus, true)));

        $todayRows = array_map(static fn ($i) => [
            'metric_key' => $i['metric_key'], 'label' => $i['label'], 'status' => $i['status'], 'target' => $i['target_value'],
            'achieved_so_far' => $i['achieved_to_date'], 'source' => $i['source'], 'why' => $i['rationale'],
        ], array_filter($plan['items'], static fn ($i) => $i['status'] !== 'off'));

        $recentActions = array_values(array_filter(SelfTuner::recentLog($this->db, 50, PlanPeriods::addDays($today, -2)), static fn ($a) => $a['applied']));
        $lessons = [];
        foreach ((array) $review['top_lags'] as $lag) {
            $changed = array_values(array_map(static fn ($a) => $a['reason'], array_filter($recentActions, static fn ($a) => $a['metric_key'] === $lag['metric_key'])));
            $lessons[] = [
                'metric_key'      => $lag['metric_key'],
                'label'           => $lag['label'],
                'target'          => $lag['target'],
                'achieved'        => $lag['achieved'],
                'achievement_pct' => $lag['achievement_pct'],
                'cause'           => $lag['cause']['label'] ?? null,
                'evidence'        => $lag['cause']['detail'] ?? null,
                'guidance'        => self::guidance($lag['cause'] ?? null),
                'bot_changed'     => $changed,
                'owner_action'    => ! empty($lag['cause']['owner_action']) ? ($lag['cause']['fix'] ?? null) : null,
            ];
        }

        $vetType = $type !== null ? self::TYPES[$type]['vetting'] : null;
        $avoid   = [];
        if ($vetType !== null || $type === null) {
            $signals = ($this->signals ?? new LagSignals($this->db))->gather(PlanPeriods::addDays($yesterday, -6), $yesterday, false);
            foreach ((array) $signals['vetting'] as $vt => $v) {
                if ($vetType === null || $vt === $vetType) {
                    $avoid[$vt] = $v['top_reasons'];
                }
            }
        }

        $mix  = (array) ($review['topic_mix'] ?? []);
        $blog = [
            // Canonical category slugs (BlogTaxonomy) — the values blog-drafts accepts.
            'prefer' => $mix['blog']['prefer'] ?? [],
            'avoid'  => $mix['blog']['avoid'] ?? [],
            // App-led vs topic against the owner's 50/50 target (about one
            // app-led post in five about a Beta app), from the review window;
            // blog-plan's `mix` has the same figures live.
            'steer'  => $mix['blog']['steer'] ?? null,
            'mix'    => isset($mix['blog']['mix']) ? array_intersect_key((array) $mix['blog']['mix'], array_flip(['posts', 'topic', 'app_led', 'app_led_beta', 'app_led_share', 'beta_share_of_app_led', 'by_group', 'target'])) : null,
        ];

        return [
            'date'              => $today,
            'timezone'          => PlanPeriods::TZ,
            'type'              => $type,
            'review_date'       => $review['review_date'] ?? $yesterday,
            'review_live'       => $live,
            'self_tune_enabled' => SelfTuner::enabled(),
            'read_me'           => 'Read lessons first. Avoid the listed vetting reasons in what you write today, prefer the categories in topic_mix.prefer (blog: topic_mix.steer says whether the next article should be app-led or a topic article), and stay inside today\'s plan targets. This briefing informs; Reach still enforces quotas and Gemini still decides.',
            'today'             => $pick(array_values($todayRows)),
            'yesterday'         => $pick(array_values((array) ($review['day'] ?? []))),
            'lessons'           => $type === null ? $lessons : array_values(array_merge(
                array_filter($lessons, static fn ($l) => in_array($l['metric_key'], $focus, true)),
                array_filter($lessons, static fn ($l) => ! in_array($l['metric_key'], $focus, true)),
            )),
            'avoid_reasons'     => $avoid,
            'topic_mix'         => match ($type) {
                'blog'           => $blog,
                'knowledge_base' => ['prefer' => $mix['knowledge_base']['prefer'] ?? []],
                null             => ['blog' => $blog, 'knowledge_base' => ['prefer' => $mix['knowledge_base']['prefer'] ?? []]],
                default          => [],
            },
            'bot_changes'       => array_map(static fn ($a) => ['action' => $a['action'], 'metric' => $a['label'], 'reason' => $a['reason'], 'run_date' => $a['run_date']], array_slice($recentActions, 0, 10)),
        ];
    }

    /** One sentence a routine can act on, per cause. */
    public static function guidance(?array $cause): ?string
    {
        if ($cause === null) {
            return null;
        }

        return match ($cause['code']) {
            'vetting_rejections' => 'Gemini rejected too many drafts — fix the reasons in avoid_reasons before submitting; one approved draft beats two rejected ones.',
            'routine_inactive'   => 'Reach received no drafts from this routine recently — submit as planned if the plan has quota left.',
            'pipeline_stuck'     => 'Earlier drafts are still waiting in vetting/cover; Reach retries them. Keep submitting new, distinct topics.',
            'publish_failures'   => 'Publishing failed for some approved items; Reach retries. Do not resubmit the same article.',
            'integration_missing' => 'An integration this needs is not configured; the owner has been told. Submissions may wait until it is.',
            'automation_off'     => 'This automation is switched off — stop.',
            'no_topics'          => 'The topic queue is empty — propose your own topic from the strategy, preferring topic_mix.prefer and the kind topic_mix.steer asks for (app-led or topic).',
            'job_failures'       => 'Background jobs failed; Reach re-queues the safe ones. No change needed in what you write.',
            'upstream_output'    => 'Outcomes follow output: the content lags above are what to fix.',
            'demand_driven'      => 'Volume follows product changes — only write when there is a real change.',
            default              => null,
        };
    }
}
