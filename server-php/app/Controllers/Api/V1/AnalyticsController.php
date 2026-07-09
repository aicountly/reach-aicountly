<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\TrafficAnalyticsService;
use App\Models\AnalyticsSnapshotModel;
use App\Models\BlogPostModel;
use App\Models\CampaignModel;
use App\Models\LeadModel;
use App\Models\SocialPostModel;

class AnalyticsController extends BaseApiController
{
    private function trafficService(): TrafficAnalyticsService
    {
        return new TrafficAnalyticsService();
    }

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
            'blog_drafts'         => $blog->whereIn('status', ['draft', 'seo_review', 'internal_review'])->countAllResults(),
            'blog_published'      => $blog->where('status', 'published')->where('published_at >=', $since30)->countAllResults(),
            'approval_pending'    => $blog->where('approval_status', 'pending')->countAllResults(),
            'engagement_imported' => (new AnalyticsSnapshotModel())
                ->where('source !=', 'internal')
                ->where('captured_at >=', $since30)
                ->countAllResults(),
        ]);
    }

    public function trafficOverview()
    {
        $days   = max(7, min(90, (int) ($this->request->getGet('days') ?? 30)));
        $stream = (string) ($this->request->getGet('stream') ?? 'all');
        $result = $this->trafficService()->overview($days, $stream);

        return $this->ok($result['data']);
    }

    public function trafficSources()
    {
        $days   = max(7, min(90, (int) ($this->request->getGet('days') ?? 30)));
        $stream = (string) ($this->request->getGet('stream') ?? 'all');
        $result = $this->trafficService()->sources($days, $stream);

        return $this->ok($result['data']);
    }

    public function trafficLeads()
    {
        $days   = max(7, min(90, (int) ($this->request->getGet('days') ?? 30)));
        $stream = (string) ($this->request->getGet('stream') ?? 'all');
        $result = $this->trafficService()->leads($days, $stream);

        return $this->ok($result['data']);
    }

    public function trafficConfigStatus()
    {
        return $this->ok($this->trafficService()->configStatus());
    }

    /** @deprecated Use trafficConfigStatus — kept for older clients */
    public function traffic()
    {
        return $this->trafficOverview();
    }

    public function providers()
    {
        $config = $this->trafficService()->configStatus();
        $out    = [];

        foreach ($config['streams'] ?? [] as $stream) {
            $out[] = [
                'provider'   => (string) ($stream['id'] ?? ''),
                'label'      => (string) ($stream['label'] ?? ''),
                'configured' => ($stream['api_ok'] ?? false) === true,
                'status'     => ($stream['api_ok'] ?? false) === true ? 'ready' : 'not_configured',
            ];
        }

        return $this->ok([
            'providers' => $out,
            'ready'     => (bool) ($config['ready'] ?? false),
            'checklist' => $config['checklist'] ?? [],
        ]);
    }
}
