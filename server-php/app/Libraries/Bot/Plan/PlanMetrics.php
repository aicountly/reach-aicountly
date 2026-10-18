<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

/**
 * The metrics the bot plans and measures. One catalogue so the plan, the
 * daily actuals snapshot, the variance and the page all speak the same keys.
 *
 * kind:    output  — something the bot produces (it controls the volume)
 *          outcome — a market response (search, leads, revenue); forecast only
 * routine: the Claude routine that feeds the metric (docs/automation/routines/)
 * jobs:    job_type prefixes whose failures count against the metric
 */
final class PlanMetrics
{
    /** Social networks Reach posts to (reach_social_posts.channel; X is stored as "twitter"). */
    public const SOCIAL_CHANNELS = ['linkedin' => 'LinkedIn', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'X', 'youtube' => 'YouTube'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $m = [
            'content.blog.published' => ['label' => 'Blog posts published', 'group' => 'content', 'kind' => 'output', 'content_type' => 'blog',
                'routine' => 'reach-blog', 'jobs' => ['reach.routine_content_sweep', 'reach.publication', 'reach.blog_']],
            'content.knowledge_base.published' => ['label' => 'Help articles published', 'group' => 'content', 'kind' => 'output', 'content_type' => 'knowledge_base',
                'routine' => 'reach-kb', 'jobs' => ['reach.routine_content_sweep', 'reach.publication']],
            'content.community.published' => ['label' => 'Community answers published', 'group' => 'content', 'kind' => 'output', 'content_type' => 'community',
                'routine' => 'reach-community-qa', 'jobs' => ['reach.community_']],
            'content.release_note.published' => ['label' => 'Release notes published', 'group' => 'content', 'kind' => 'output', 'content_type' => 'release_note',
                'routine' => 'reach-release-notes', 'jobs' => ['reach.structured_content_sweep']],
            'content.workflow_guide.published' => ['label' => 'Workflow guides published', 'group' => 'content', 'kind' => 'output', 'content_type' => 'workflow_guide',
                'routine' => 'reach-workflow-guides', 'jobs' => ['reach.structured_content_sweep']],
            'video.vlog.published' => ['label' => 'Vlogs published', 'group' => 'video', 'kind' => 'output', 'content_type' => 'vlog',
                'routine' => 'reach-video', 'jobs' => ['reach.video_pipeline']],
            'video.short.published' => ['label' => 'Shorts published', 'group' => 'video', 'kind' => 'output', 'content_type' => 'short',
                'routine' => 'reach-video', 'jobs' => ['reach.video_pipeline']],
        ];
        foreach (self::SOCIAL_CHANNELS as $channel => $name) {
            $m["social.{$channel}.posted"] = ['label' => "{$name} posts", 'group' => 'social', 'kind' => 'output', 'channel' => $channel,
                'jobs' => ['distribution.dispatch.social', 'reach.social_post_poll']];
        }
        $m += [
            'email.accepted' => ['label' => 'Campaign emails accepted', 'group' => 'email', 'kind' => 'output', 'channel' => 'email', 'unit' => 'emails',
                'jobs' => ['distribution.dispatch.email', 'distribution.campaign.']],
            'covers.generated' => ['label' => 'AI covers generated', 'group' => 'covers', 'kind' => 'output',
                'jobs' => ['reach.blog_cover_sweep', 'reach.routine_content_sweep']],
            'seo.checks_run' => ['label' => 'SEO keyword checks run', 'group' => 'seo', 'kind' => 'output', 'unit' => 'checks',
                'jobs' => ['reach.seo_tracker']],
            'search.clicks' => ['label' => 'Search clicks', 'group' => 'search', 'kind' => 'outcome', 'unit' => 'clicks',
                'jobs' => ['reach.search_console_ingest'], 'lagged_days' => 3],
            'search.impressions' => ['label' => 'Search impressions', 'group' => 'search', 'kind' => 'outcome', 'unit' => 'impressions',
                'jobs' => ['reach.search_console_ingest'], 'lagged_days' => 3],
            'leads.captured' => ['label' => 'Leads captured', 'group' => 'leads', 'kind' => 'outcome', 'unit' => 'leads', 'jobs' => []],
            'leads.pushed' => ['label' => 'Leads pushed to Engage', 'group' => 'leads', 'kind' => 'outcome', 'unit' => 'leads', 'jobs' => ['reach.engage_push_retry']],
            'engage.conversions' => ['label' => 'Engage conversions', 'group' => 'engage', 'kind' => 'outcome', 'unit' => 'leads', 'jobs' => []],
            'engage.revenue_inr' => ['label' => 'Engage revenue (₹)', 'group' => 'engage', 'kind' => 'outcome', 'unit' => 'inr', 'jobs' => []],
        ];

        foreach ($m as $key => $meta) {
            $m[$key] = $meta + ['key' => $key, 'unit' => 'count', 'content_type' => null, 'channel' => null, 'routine' => null, 'jobs' => [], 'lagged_days' => 0];
        }

        return $m;
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown plan metric: {$key}");
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $key): string
    {
        return (string) (self::all()[$key]['label'] ?? $key);
    }

    /** Group labels for the page, in display order. */
    public const GROUPS = [
        'content' => 'Content', 'video' => 'Video', 'social' => 'Social', 'email' => 'Email',
        'covers' => 'Covers', 'seo' => 'SEO checks', 'search' => 'Search', 'leads' => 'Leads', 'engage' => 'Engage',
    ];
}
