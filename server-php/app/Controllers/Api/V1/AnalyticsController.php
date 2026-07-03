<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\AnalyticsSnapshotModel;
use App\Models\BlogPostModel;
use App\Models\CampaignModel;
use App\Models\LeadModel;
use App\Models\SocialPostModel;

class AnalyticsController extends BaseApiController
{
    public function summary()
    {
        $blog     = new BlogPostModel();
        $campaign = new CampaignModel();
        $social   = new SocialPostModel();
        $lead     = new LeadModel();

        $since30 = date('Y-m-d H:i:s', strtotime('-30 days'));
        return $this->ok([
            'window' => '30d',
            'campaigns_created'   => $campaign->where('created_at >=', $since30)->countAllResults(),
            'posts_planned'       => $social->where('created_at >=', $since30)->countAllResults(),
            'posts_published'     => $social->where('status', 'posted')->where('published_at >=', $since30)->countAllResults(),
            'leads_generated'     => $lead->where('created_at >=', $since30)->countAllResults(),
            'blog_drafts'         => $blog->whereIn('status', ['draft','seo_review','internal_review'])->countAllResults(),
            'blog_published'      => $blog->where('status', 'published')->where('published_at >=', $since30)->countAllResults(),
            'approval_pending'    => $blog->where('approval_status', 'pending')->countAllResults(),
            'engagement_imported' => (new AnalyticsSnapshotModel())
                ->where('source !=', 'internal')
                ->where('captured_at >=', $since30)
                ->countAllResults(),
        ]);
    }

    public function traffic()
    {
        // Placeholder traffic panel — real values come from external integrations later.
        $rows = (new AnalyticsSnapshotModel())
            ->where('source', 'internal')
            ->orderBy('captured_at', 'DESC')
            ->limit(30)
            ->findAll();
        return $this->ok(['snapshots' => $rows]);
    }

    public function providers()
    {
        $checks = [
            'ga4'        => (string) env('GA4_MEASUREMENT_ID', '') !== '' && (string) env('GA4_API_SECRET', '') !== '',
            'gsc'        => (string) env('GSC_SITE_URL', '') !== '',
            'meta'       => (string) env('META_ACCESS_TOKEN', '') !== '',
            'linkedin'   => (string) env('LINKEDIN_ANALYTICS_TOKEN', '') !== '',
            'twitter'    => (string) env('TWITTER_ANALYTICS_TOKEN', '') !== '',
            'youtube'    => (string) env('YOUTUBE_ANALYTICS_TOKEN', '') !== '',
            'email'      => (string) env('EMAIL_PROVIDER_API_KEY', '') !== '',
            'whatsapp'   => (string) env('WHATSAPP_PROVIDER_API_KEY', '') !== '',
        ];
        $out = [];
        foreach ($checks as $provider => $configured) {
            $out[] = [
                'provider'   => $provider,
                'configured' => $configured,
                'status'     => $configured ? 'ready' : 'not_configured',
            ];
        }
        return $this->ok(['providers' => $out]);
    }
}
