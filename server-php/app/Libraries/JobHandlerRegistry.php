<?php

namespace App\Libraries;

use App\Jobs\EngagePushRetryJob;
use App\Jobs\HealthCheckJob;
use App\Jobs\MarketingBotDispatchJob;
use App\Jobs\DailyApprovalDigestJob;
use App\Jobs\DailyMarketingPackJob;
use App\Jobs\ContentDueDateReminderJob;
use App\Jobs\ContentOverdueEscalationJob;
use App\Jobs\ContentRefreshDetectionJob;
use App\Jobs\ContentScheduleReadinessJob;
use App\Jobs\AiGenerationJob;
use App\Jobs\CommunityQuestionIntakeJob;
use App\Jobs\CommunityAnswerGenerationJob;
use App\Jobs\CommunityPublicationVerificationJob;
use App\Jobs\CommunityDeploymentRetryJob;
use App\Jobs\CommunityAnalyticsReconciliationJob;
use App\Jobs\CommunityPublicationReconciliationJob;
use App\Jobs\CommunitySyncChangesJob;
use App\Jobs\CommunityDeploymentRetrySweepJob;
use App\Jobs\PublicationJob;
use App\Jobs\BlogCoverSweepJob;
use App\Jobs\BlogWorkBlockJob;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use RuntimeException;

/**
 * Registry of job type → handler. Bootstrap wires the built-in handlers.
 * Additional handlers can be registered at runtime via `register()`.
 */
class JobHandlerRegistry
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    public function __construct()
    {
        $this->handlers['reach.health_check']                   = new HealthCheckJob();
        $this->handlers['reach.marketing_bot_dispatch']          = new MarketingBotDispatchJob();
        $this->handlers['reach.engage_push_retry']               = new EngagePushRetryJob();
        // Phase 2 content jobs
        $this->handlers['reach.daily_approval_digest']           = new DailyApprovalDigestJob();
        $this->handlers['reach.daily_marketing_pack']            = new DailyMarketingPackJob();
        $this->handlers['reach.content_due_date_reminder']       = new ContentDueDateReminderJob();
        $this->handlers['reach.content_overdue_escalation']      = new ContentOverdueEscalationJob();
        $this->handlers['reach.content_refresh_detection']       = new ContentRefreshDetectionJob();
        $this->handlers['reach.content_schedule_readiness']      = new ContentScheduleReadinessJob();
        // Phase 3 AI generation
        $this->handlers['reach.ai_generation']                   = new AiGenerationJob();
        // Stream H: AI visibility (GEO) passes and AI Insights reports
        $this->handlers['reach.ai_visibility_daily']                 = new \App\Jobs\AiVisibilityDailyJob();
        $this->handlers['intelligence.visibility.execute']           = new \App\Jobs\AiVisibilityRunJob();
        $this->handlers['reach.ai_insights_run']                     = new \App\Jobs\AiInsightsJob();
        // Phase 5 Community Q&A
        $this->handlers['reach.community_question_intake']           = new CommunityQuestionIntakeJob();
        $this->handlers['reach.community_answer_generation']         = new CommunityAnswerGenerationJob();
        $this->handlers['reach.community_publication_verification']  = new CommunityPublicationVerificationJob();
        $this->handlers['reach.community_deployment_retry']          = new CommunityDeploymentRetryJob();
        $this->handlers['reach.community_analytics_reconciliation']  = new CommunityAnalyticsReconciliationJob();
        $this->handlers['reach.community_publication_reconciliation'] = new CommunityPublicationReconciliationJob();
        $this->handlers['reach.community_sync_changes']              = new CommunitySyncChangesJob();
        $this->handlers['reach.community_deployment_retry_sweep']     = new CommunityDeploymentRetrySweepJob();
        // Phase 2 blog automation / publishing
        $this->handlers['reach.publication']                         = new PublicationJob();
        $this->handlers['reach.blog_work_block']                     = new BlogWorkBlockJob();
        $this->handlers['reach.blog_cover_sweep']                    = new BlogCoverSweepJob();
        // Content base + cover gallery (2026-08 automation revamp)
        $this->handlers['reach.content_base_sync']                   = new \App\Jobs\ContentBaseSyncJob();
        $this->handlers['reach.gallery_deficit_alert']               = new \App\Jobs\GalleryDeficitAlertJob();
        $this->handlers['reach.cover_gallery_autofill']              = new \App\Jobs\CoverGalleryAutofillJob();
        // Search Console ingestion — writes reach_search_metric_facts, which the
        // SEO snapshot job and every Search Intelligence view read from.
        $this->handlers['reach.search_console_ingest']               = new \App\Jobs\SearchConsoleIngestJob();
        // SEO Command Centre daily snapshots
        $this->handlers['reach.seo_snapshot']                        = new \App\Jobs\SeoSnapshotJob();
        // Campaign distribution (queue `distribution`) — email/SMS/WhatsApp batches,
        // social posts, scheduled campaign starts and dispatch reconciliation.
        $channelDispatch = new \App\Jobs\DistributionChannelDispatchJob();
        $this->handlers[DistributionJobTypes::CAMPAIGN_DISPATCH_EMAIL]  = $channelDispatch;
        $this->handlers[DistributionJobTypes::CAMPAIGN_DISPATCH_SMS]    = $channelDispatch;
        $this->handlers[DistributionJobTypes::CAMPAIGN_DISPATCH_WA]     = $channelDispatch;
        $this->handlers[DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL] = new \App\Jobs\DistributionSocialDispatchJob();
        $this->handlers[DistributionJobTypes::CAMPAIGN_SCHEDULE]        = new \App\Jobs\DistributionCampaignScheduleJob();
        $this->handlers[DistributionJobTypes::CAMPAIGN_RECONCILE]       = new \App\Jobs\DistributionReconcileJob();
        // social-api.ai: confirm `publishing` posts (published / failed / 30-minute timeout).
        $this->handlers[\App\Libraries\Distribution\SocialPostStatusPoller::JOB_TYPE] = new \App\Jobs\SocialPostPollJob();
        // Stream a: social inbox bot — comments / DMs → vetted reply, Flow ticket or lead (every 10 minutes).
        $this->handlers[\App\Libraries\Distribution\SocialInbox\SocialInboxService::JOB_TYPE] = new \App\Jobs\SocialInboxPollJob();
        // Stream F: SEO Tracker (DataForSEO SERP checks) + daily analytics report
        $this->handlers['reach.seo_tracker']                         = new \App\Jobs\SeoTrackerJob();
        $this->handlers['reach.daily_analytics_report']              = new \App\Jobs\DailyAnalyticsReportJob();
        // Release notes + workflow guides: retry pending vetting / failed publishes
        $this->handlers['reach.structured_content_sweep']            = new \App\Jobs\StructuredContentSweepJob();
        // Stream C: Claude-routine blog/KB pipeline (vet → approve → cover → publish → verify)
        $this->handlers['reach.routine_content_sweep']               = new \App\Jobs\RoutineContentSweepJob();
        // Automated video (queue `video`) — Stream V: one resumable step per job + hourly sweep.
        $this->handlers['reach.video_pipeline_step']  = new \App\Jobs\VideoPipelineStepJob();
        $this->handlers['reach.video_pipeline_sweep'] = new \App\Jobs\VideoPipelineSweepJob();
        // Bot Plan & Performance: actuals snapshot, review + self-tune, plan regeneration.
        $this->handlers[\App\Jobs\BotPlanJob::TYPE]  = new \App\Jobs\BotPlanJob();
        // Stream 9 — "Run now" of a cron command (Bot → Activities); allow-listed by BotActivityRegistry.
        $this->handlers[\App\Libraries\Bot\BotActivityRegistry::COMMAND_JOB_TYPE] = new \App\Jobs\BotActivityCommandJob();
    }

    public function register(string $jobType, JobHandlerInterface $handler): void
    {
        $this->handlers[$jobType] = $handler;
    }

    public function has(string $jobType): bool
    {
        return isset($this->handlers[$jobType]);
    }

    public function get(string $jobType): JobHandlerInterface
    {
        if (! isset($this->handlers[$jobType])) {
            throw new RuntimeException("No handler registered for job_type '{$jobType}'");
        }
        return $this->handlers[$jobType];
    }

    /**
     * Execute a reserved job row.
     * @param array<string,mixed> $row  the reserved reach_jobs row
     * @return array<string,mixed>      the handler result
     */
    public function execute(array $row, ?string $workerId = null): array
    {
        $handler = $this->get((string) $row['job_type']);
        $payload = [];
        if (isset($row['payload_json'])) {
            $decoded = is_array($row['payload_json'])
                ? $row['payload_json']
                : json_decode((string) $row['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $ctx = JobContext::fromJobRow($row, $workerId ?? (string) ($row['worker_id'] ?? 'unknown'));
        return $handler->handle($payload, $ctx);
    }
}
