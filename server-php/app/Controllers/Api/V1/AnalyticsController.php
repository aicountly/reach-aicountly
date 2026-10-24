<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Analytics\AnalyticsRecordsService;
use App\Libraries\TrafficAnalyticsService;
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
        $campaign = new CampaignModel();
        $social   = new SocialPostModel();
        $lead     = new LeadModel();
        $records  = new AnalyticsRecordsService();

        $since30 = date('Y-m-d H:i:s', strtotime('-30 days'));

        // Blog tiles count reach_blog_posts, which the bot's "Generate blog
        // draft" still writes; they and engagement share their predicate with
        // the lists they open (legacyBlogPosts / snapshots below).
        return $this->ok([
            'window' => '30d',
            'campaigns_created'   => $campaign->where('created_at >=', $since30)->countAllResults(),
            'posts_planned'       => $social->where('created_at >=', $since30)->countAllResults(),
            'posts_published'     => $social->where('status', 'posted')->where('published_at >=', $since30)->countAllResults(),
            'leads_generated'     => $lead->where('created_at >=', $since30)->countAllResults(),
            'blog_drafts'         => $records->legacyBlogCount('drafts', 30),
            'blog_published'      => $records->legacyBlogCount('published', 30),
            'approval_pending'    => $records->legacyBlogCount('approval_pending', 30),
            'engagement_imported' => $records->snapshotCount('external', 30),
        ]);
    }

    /**
     * GET v1/analytics/legacy-blog-posts — the rows behind the Blog tiles.
     * ?bucket=drafts|published|approval_pending (the tiles), ?status,
     * ?approval_status, ?days (published window, default 30), ?q, ?page, ?limit.
     */
    public function legacyBlogPosts()
    {
        $bucket = (string) ($this->request->getGet('bucket') ?? '');
        if ($bucket !== '' && ! in_array($bucket, AnalyticsRecordsService::BLOG_BUCKETS, true)) {
            return $this->fail('`bucket` must be one of: ' . implode(', ', AnalyticsRecordsService::BLOG_BUCKETS) . '.', 422);
        }
        [$page, $limit] = $this->pagination(25, 100);

        return $this->ok((new AnalyticsRecordsService())->legacyBlogPosts([
            'bucket'          => $bucket,
            'status'          => (string) ($this->request->getGet('status') ?? ''),
            'approval_status' => (string) ($this->request->getGet('approval_status') ?? ''),
            'days'            => $this->request->getGet('days'),
            'q'               => (string) ($this->request->getGet('q') ?? ''),
        ], $page, $limit));
    }

    /**
     * GET v1/analytics/snapshots — imported engagement snapshots with their
     * metrics payload. ?source (a source, or `external` = all but internal,
     * the tile), ?days or ?from/?to (Y-m-d, captured_at), ?id, ?page, ?limit.
     */
    public function snapshots()
    {
        $source = (string) ($this->request->getGet('source') ?? '');
        if ($source !== '' && $source !== 'external' && ! in_array($source, AnalyticsRecordsService::SNAPSHOT_SOURCES, true)) {
            return $this->fail('Unknown snapshot source.', 422);
        }
        [$page, $limit] = $this->pagination(25, 100);

        return $this->ok((new AnalyticsRecordsService())->snapshots([
            'source' => $source,
            'days'   => $this->request->getGet('days'),
            'from'   => $this->request->getGet('from'),
            'to'     => $this->request->getGet('to'),
            'id'     => $this->request->getGet('id'),
        ], $page, $limit));
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

    /**
     * GET v1/analytics/traffic/explore — GA4 traffic grouped by ?group_by
     * (TrafficAnalyticsService::EXPLORE_DIMENSIONS) within exact-match
     * filters ?date ?channel ?source ?page ?device ?country ?event, for
     * ?days / ?stream like the overview. ?sort ?dir ?q (contains) ?limit
     * ?offset page through the groups.
     */
    public function trafficExplore()
    {
        $groupBy = (string) ($this->request->getGet('group_by') ?? 'date');
        if (! isset(TrafficAnalyticsService::EXPLORE_DIMENSIONS[$groupBy])) {
            return $this->fail('`group_by` must be one of: ' . implode(', ', array_keys(TrafficAnalyticsService::EXPLORE_DIMENSIONS)) . '.', 422);
        }
        $days    = max(7, min(90, (int) ($this->request->getGet('days') ?? 30)));
        $stream  = (string) ($this->request->getGet('stream') ?? 'all');
        $filters = [];
        foreach (array_keys(TrafficAnalyticsService::EXPLORE_DIMENSIONS) as $key) {
            $filters[$key] = (string) ($this->request->getGet($key) ?? '');
        }
        $opts = [
            'sort'   => (string) ($this->request->getGet('sort') ?? ''),
            'dir'    => (string) ($this->request->getGet('dir') ?? ''),
            'search' => (string) ($this->request->getGet('q') ?? ''),
            'limit'  => (int) ($this->request->getGet('limit') ?? 50),
            'offset' => (int) ($this->request->getGet('offset') ?? 0),
        ];

        try {
            $result = $this->trafficService()->explore($groupBy, $days, $stream, $filters, $opts);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

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
