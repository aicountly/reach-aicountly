<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Blog\BlogMixService;
use App\Libraries\Blog\BlogTaxonomy;
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
                    'Business operations: invoicing, stock, sales follow-up, notes and documents — app-led where a live app does the job',
                ],
            ],
            // What to file the article under and which app it may be about
            // (BlogTaxonomy — the aicountly.com list), and the mix against the
            // owner's 50/50 target, so the routine can steer.
            'taxonomy'          => [
                'categories'   => BlogTaxonomy::topics(),
                'groups'       => BlogTaxonomy::GROUPS,
                'products'     => BlogTaxonomy::productsForRoutine(),
                'note'         => 'Send "category" as one slug from categories. Send "product_slug" only for an app-led article (the app is the subject); leave it out for a compliance/finance topic article that merely mentions an app. Beta apps: describe only what their repository shows and always call them Beta, e.g. "HRMS (Beta)".',
            ],
            'mix'               => $this->mix($now),
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
                'category must be one of taxonomy.categories (slug); anything else is refused with HTTP 422 listing the allowed slugs. product_slug, when sent, must be one of taxonomy.products.',
                'Mix: half compliance/finance topic articles, half app-led. Follow mix.next (kind app_led / topic / either, and app_status live / beta) when the queue gives you a choice.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mix(\DateTimeImmutable $now): array
    {
        try {
            return (new BlogMixService($this->db))->summary($now);
        } catch (\Throwable $e) {
            log_message('error', '[BlogPlanService] mix unavailable: ' . $e->getMessage());

            return ['target' => BlogTaxonomy::mix([])['target'], 'next' => null, 'error' => 'mix unavailable'];
        }
    }

    /**
     * Most recent blogs that are live or on their way (not rejected/archived),
     * newest first.
     *
     * @return list<array{title: string, slug: string, status: string, content_key: ?string, category: ?string, product_slug: ?string}>
     */
    private function existing(int $limit): array
    {
        $product = \App\Libraries\Database\SchemaGuard::hasColumn($this->db, 'reach_blog_publication_profiles', 'product_slug') ? 'p.product_slug' : 'NULL AS product_slug';
        $rows = $this->db->table('reach_content_items i')
            ->select("i.title, i.slug, i.workflow_status, i.content_base_key, p.category, {$product}", false)
            ->join('reach_blog_publication_profiles p', 'p.content_item_id = i.id', 'left')
            ->where('i.content_type', 'blog')
            ->where('i.deleted_at IS NULL', null, false)
            ->whereNotIn('i.workflow_status', ['rejected', 'archived', 'unpublished'])
            ->orderBy('COALESCE(i.published_at, i.created_at)', 'DESC', false)
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'title'        => (string) $r['title'],
            'slug'         => (string) $r['slug'],
            'status'       => (string) $r['workflow_status'],
            'content_key'  => $r['content_base_key'] ?? null,
            'category'     => BlogTaxonomy::canonical($r['category'] ?? null),
            'product_slug' => BlogTaxonomy::canonicalProduct($r['product_slug'] ?? null),
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
