<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Publishing\LinkRegistryService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * GET v1/automation/blog-plan — what the blog routine needs to pick its next
 * article: today's remaining quota, what already exists (for de-duplication),
 * the priority areas and the internal links it may use.
 *
 * Topic supply: `index_suggestions` carries the head of the unified
 * next-topic queue (GET v1/automation/content-base/next?type=blog — roadmap
 * selections and content-base topics, each with its content_base_key). The
 * routine takes the first one that fits, or proposes its own topic.
 */
class BlogPlanService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function plan(?\DateTimeImmutable $now = null): array
    {
        $now      = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata'));
        $siteBase = rtrim((string) env('AICOUNTLY_PUBLIC_SITE_BASE_URL', 'https://aicountly.com'), '/');
        $quota    = (new RoutineSubmissions($this->db))->quota('blog');

        return [
            'date'            => $now->format('Y-m-d'),
            'enabled'         => RoutineFlags::enabled('blog.enabled'),
            'quota'           => $quota,
            'stop'            => $quota['remaining_today'] <= 0 || ! RoutineFlags::enabled('blog.enabled'),
            'existing_titles' => $this->existing(200),
            'priority_topics' => [
                'launch_apps'         => LaunchApps::forRoutine($siteBase),
                'compliance_calendar' => (new ComplianceCalendar())->upcoming($now, 30),
                'themes'              => [
                    'Indian small-business accounting and bookkeeping practice',
                    'GST compliance (returns, input tax credit, e-invoicing, e-way bills)',
                    'TDS/TCS, advance tax and income-tax filing for businesses',
                    'Payroll compliance (PF, ESI, professional tax)',
                    'Company law and MCA filings for private limited companies and LLPs',
                    'How the launch apps help with the above — only features visible in their code',
                ],
            ],
            'index_suggestions' => $this->indexSuggestions(),
            'internal_links'  => [
                'site_base' => $siteBase,
                'links'     => (new LinkRegistryService($this->db))->excerpt(150),
            ],
            'rules' => [
                'Take the first fitting topic from index_suggestions and submit under its content_base_key; otherwise propose your own topic with a new content_base_key, e.g. blog-' . $now->format('Ymd') . '-<short-slug>.',
                'Do not repeat or closely paraphrase any title in existing_titles; a duplicate title or slug is refused with HTTP 409.',
                'HTTP 429 means today\'s quota is used: stop the run.',
                'Every date in compliance_calendar is a statutory default — confirm current extensions before stating one.',
            ],
        ];
    }

    /**
     * Most recent blogs that are live or on their way (not rejected/archived),
     * newest first.
     *
     * @return list<array{title: string, slug: string, status: string, content_key: ?string}>
     */
    private function existing(int $limit): array
    {
        $rows = $this->db->table('reach_content_items')
            ->select('title, slug, workflow_status, content_base_key')
            ->where('content_type', 'blog')
            ->where('deleted_at IS NULL', null, false)
            ->whereNotIn('workflow_status', ['rejected', 'archived', 'unpublished'])
            ->orderBy('COALESCE(published_at, created_at)', 'DESC', false)
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'title'       => (string) $r['title'],
            'slug'        => (string) $r['slug'],
            'status'      => (string) $r['workflow_status'],
            'content_key' => $r['content_base_key'] ?? null,
        ], $rows);
    }

    /**
     * The first topics of the unified next-topic queue (regeneration →
     * roadmap-selected → content base), each with the content_base_key to
     * submit under. Optional: the routine may still propose its own topic.
     *
     * @return list<array<string, mixed>>
     */
    private function indexSuggestions(): array
    {
        try {
            $entries = new \App\Libraries\Content\Base\ContentBaseEntries($this->db);
            if (! $entries->available()) {
                return [];
            }
            $entries->reconcile('blog');

            return (new \App\Libraries\Content\Base\RoutineTopicQueue($this->db, $entries))->next('blog', 5)['items'];
        } catch (\Throwable $e) {
            log_message('error', '[BlogPlanService] next topics unavailable: ' . $e->getMessage());

            return [];
        }
    }
}
