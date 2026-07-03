<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalModel;
use App\Models\BlogPostModel;
use App\Models\CampaignModel;
use App\Models\ContentCalendarItemModel;
use App\Models\LeadModel;
use App\Models\MarketingBotQueueModel;
use App\Models\MarketingBotReportModel;
use App\Models\SocialPostModel;

class DashboardController extends BaseApiController
{
    public function summary()
    {
        $blog     = new BlogPostModel();
        $campaign = new CampaignModel();
        $social   = new SocialPostModel();
        $lead     = new LeadModel();
        $approval = new ApprovalModel();
        $reports  = new MarketingBotReportModel();
        $queue    = new MarketingBotQueueModel();
        $calendar = new ContentCalendarItemModel();

        return $this->ok([
            'blog' => [
                'total'       => $blog->countAllResults(false),
                'drafts'      => $blog->where('status', 'draft')->countAllResults(),
                'in_review'   => $blog->whereIn('status', ['seo_review', 'internal_review'])->countAllResults(),
                'approved'    => $blog->where('status', 'approved')->countAllResults(),
                'scheduled'   => $blog->where('status', 'scheduled')->countAllResults(),
                'published'   => $blog->where('status', 'published')->countAllResults(),
                'pending_publishing' => $blog->where('publishing_status', 'pending_publishing')->countAllResults(),
            ],
            'campaigns' => [
                'total'            => $campaign->countAllResults(false),
                'draft'            => $campaign->where('status', 'draft')->countAllResults(),
                'pending_approval' => $campaign->where('status', 'pending_approval')->countAllResults(),
                'running'          => $campaign->where('status', 'running')->countAllResults(),
                'completed'        => $campaign->where('status', 'completed')->countAllResults(),
            ],
            'social' => [
                'total'    => $social->countAllResults(false),
                'draft'    => $social->where('status', 'draft')->countAllResults(),
                'queue'    => $social->whereIn('status', ['approved', 'scheduled', 'manual_queue'])->countAllResults(),
                'posted'   => $social->where('status', 'posted')->countAllResults(),
                'failed'   => $social->where('status', 'failed')->countAllResults(),
            ],
            'leads' => [
                'total'            => $lead->countAllResults(false),
                'pending_push'     => $lead->where('engage_push_status', 'pending_push')->countAllResults(),
                'pushed'           => $lead->where('engage_push_status', 'pushed')->countAllResults(),
                'failed'           => $lead->where('engage_push_status', 'failed')->countAllResults(),
                'duplicate'        => $lead->where('engage_push_status', 'duplicate')->countAllResults(),
                'retry_scheduled'  => $lead->where('engage_push_status', 'retry_scheduled')->countAllResults(),
            ],
            'approvals' => [
                'pending' => $approval->pendingCount(),
                'total'   => $approval->countAllResults(false),
            ],
            'bot' => [
                'reports_total'    => $reports->countAllResults(false),
                'reports_pending'  => $reports->where('approval_status', 'pending')->countAllResults(),
                'queue_running'    => $queue->where('status', 'running')->countAllResults(),
                'queue_completed'  => $queue->where('status', 'completed')->countAllResults(),
                'queue_failed'     => $queue->where('status', 'failed')->countAllResults(),
            ],
            'calendar_upcoming' => $calendar
                ->where('date >=', date('Y-m-d'))
                ->orderBy('date', 'ASC')
                ->limit(10)
                ->findAll(),
        ]);
    }

    public function counts()
    {
        // Used by the sidebar to badge menu items.
        return $this->ok([
            'blog'      => (new BlogPostModel())->whereIn('status', ['idea','draft','seo_review','internal_review'])->countAllResults(),
            'approvals' => (new ApprovalModel())->pendingCount(),
            'leads_pending_push' => (new LeadModel())->whereIn('engage_push_status', ['pending_push','failed','retry_scheduled'])->countAllResults(),
            'social_queue' => (new SocialPostModel())->whereIn('status', ['approved','scheduled','manual_queue'])->countAllResults(),
            'bot_queue_running' => (new MarketingBotQueueModel())->where('status', 'running')->countAllResults(),
        ]);
    }
}
