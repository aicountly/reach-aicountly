<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use App\Libraries\Intelligence\VisibilityExecutionService;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\TrafficAnalyticsService;
use CodeIgniter\Database\BaseConnection;

/**
 * Gathers Reach's own data into the compact digest the AI Insights model
 * reasons over. Every section says whether it had data; a source that is not
 * configured or has no rows is reported as such (with the reason) instead of
 * being left out or zero-filled, so the model can name the gap.
 *
 * Tables owned by other streams (site-wide Search Console facts, SEO
 * Tracker) are read only when they exist.
 */
final class InsightsDigestBuilder
{
    public const VERSION = 'h1';

    private BaseConnection $db;
    private SiteSignalsProbe $probe;

    /** @var callable(): array<string, mixed> */
    private $ga4;

    /** @var callable(int): array<string, mixed> */
    private $visibility;

    public function __construct(?BaseConnection $db = null, ?SiteSignalsProbe $probe = null, ?callable $ga4 = null, ?callable $visibility = null)
    {
        $this->db         = $db ?? \Config\Database::connect();
        $this->probe      = $probe ?? new SiteSignalsProbe();
        $this->ga4        = $ga4 ?? [$this, 'ga4FromAnalytics'];
        $this->visibility = $visibility ?? fn (int $days): array => (new AiVisibilityService($this->db))->compactSummary($days);
    }

    /** @return array<string, mixed> */
    public function build(int $days = 28): array
    {
        $days  = max(7, min(90, $days));
        $end   = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata'));
        $start = $end->modify('-' . ($days - 1) . ' days');
        $prevS = $start->modify('-' . $days . ' days');
        $prevE = $start->modify('-1 day');
        $since = $start->format('Y-m-d');

        $siteUrl = (string) ReachConfig::get('insights.site_url', 'https://aicountly.com');
        $brand   = VisibilityExecutionService::brandAliases()[0];

        $digest = [
            'digest_version' => self::VERSION,
            'generated_at'   => gmdate('c'),
            'brand'          => $brand,
            'period'         => ['start' => $since, 'end' => $end->format('Y-m-d'), 'days' => $days, 'previous_start' => $prevS->format('Y-m-d'), 'previous_end' => $prevE->format('Y-m-d')],
            'products'       => $this->section(fn () => $this->products()),
            'search_console' => $this->section(fn () => $this->search($since, $end->format('Y-m-d'), $prevS->format('Y-m-d'), $prevE->format('Y-m-d'))),
            'ga4'            => $this->section(fn () => ($this->ga4)()),
            'content'        => $this->section(fn () => $this->content($since)),
            'vetting'        => $this->section(fn () => $this->vetting($since)),
            'seo_tracker'    => $this->section(fn () => $this->seoTracker()),
            'ai_visibility'  => $this->section(function () use ($days): array {
                $v = ($this->visibility)($days);
                return ($v['answers'] ?? 0) > 0 ? ['available' => true] + $v : ['available' => false, 'reason' => 'No AI-visibility answers stored in this period yet.'] + $v;
            }),
            'campaigns'      => $this->section(fn () => $this->campaigns($since)),
            'refresh_recommendations' => $this->section(fn () => $this->refresh()),
            'competitors_tracked' => $this->section(fn () => ['available' => true, 'names' => array_column(VisibilityExecutionService::trackedCompetitors($this->db), 'name')]),
            'site_signals'   => $this->section(fn () => ['available' => true] + $this->probe->probe($siteUrl, $brand)),
            'not_tracked_in_reach' => [
                'note'    => 'Reach holds marketing data only. These business metrics are not available to it and must come from the product and billing systems.',
                'metrics' => ['sign-ups / activations', 'paying customers', 'MRR / ARR', 'churn and retention cohorts', 'CAC and payback', 'NPS / customer satisfaction', 'revenue by market'],
            ],
        ];
        $digest['ai_readiness_checks'] = self::readinessChecks($digest);

        return $digest;
    }

    /**
     * Deterministic AI-recommendation-readiness checklist from what was
     * observed. 'unknown' means we could not check, not that it is missing.
     *
     * @param array<string, mixed> $digest
     * @return list<array{key: string, item: string, status: string, evidence: string}>
     */
    public static function readinessChecks(array $digest): array
    {
        $s       = $digest['site_signals'] ?? [];
        $schema  = $s['schema'] ?? [];
        $types   = $schema['types'] ?? null;
        $org     = $schema['organization'] ?? null;
        $sitemap = $s['sitemap'] ?? [];
        $robots  = $s['robots'] ?? [];
        $wiki    = $s['wikidata'] ?? [];
        $kbCount = (int) ($digest['content']['published_total_by_type']['knowledge_base'] ?? 0);

        $checks = [];
        $add    = static function (string $key, string $item, string $status, string $evidence) use (&$checks): void {
            $checks[] = ['key' => $key, 'item' => $item, 'status' => $status, 'evidence' => $evidence];
        };

        $llms = $s['llms_txt']['present'] ?? null;
        $add('llms_txt', 'llms.txt published at the site root', $llms === null ? 'unknown' : ($llms ? 'done' : 'missing'),
            $llms === null ? 'Site could not be reached.' : ($llms ? 'llms.txt returned ' . (int) ($s['llms_txt']['bytes'] ?? 0) . ' bytes.' : 'GET /llms.txt returned HTTP ' . (int) ($s['llms_txt']['status'] ?? 0) . '.'));

        if (! is_array($types)) {
            $add('schema_organization', 'schema.org Organization markup', 'unknown', 'Home page could not be read.');
            $add('schema_software_application', 'schema.org SoftwareApplication markup for each product', 'unknown', 'Home page could not be read.');
        } else {
            $hasOrg = $org !== null;
            $add('schema_organization', 'schema.org Organization markup', $hasOrg ? 'done' : 'missing',
                $hasOrg ? 'Organization JSON-LD found on the home page.' : 'No Organization JSON-LD on the home page (types seen: ' . ($types === [] ? 'none' : implode(', ', array_slice($types, 0, 8))) . ').');
            $app = array_intersect($types, ['SoftwareApplication', 'WebApplication', 'MobileApplication']);
            $add('schema_software_application', 'schema.org SoftwareApplication markup for each product', $app !== [] ? 'partial' : 'missing',
                $app !== [] ? 'Found on the home page (' . implode(', ', $app) . '); product pages not checked.' : 'No SoftwareApplication/WebApplication JSON-LD on the home page.');
        }

        if ($org === null) {
            $add('entity_consistency', 'Consistent entity info (name, URL, logo, address/contact, sameAs profiles)', is_array($types) ? 'missing' : 'unknown', 'No Organization entity to check.');
        } else {
            $hosts = $org['same_as_hosts'] ?? [];
            $have  = array_filter([$org['has_url'] ? 'url' : null, $org['has_logo'] ? 'logo' : null, $org['has_address'] ? 'address' : null, $org['has_telephone'] ? 'contact' : null]);
            $status = count($hosts) >= 3 && count($have) >= 3 ? 'done' : ((count($hosts) > 0 || count($have) > 0) ? 'partial' : 'missing');
            $add('entity_consistency', 'Consistent entity info (name, URL, logo, address/contact, sameAs profiles)', $status,
                'Organization has ' . (count($have) ? implode(', ', $have) : 'no url/logo/address/contact') . '; sameAs profiles: ' . (count($hosts) ? implode(', ', $hosts) : 'none') . '.');
        }

        $hosts   = $org['same_as_hosts'] ?? [];
        $reviews = array_values(array_filter($hosts, static fn (string $h): bool => (bool) preg_match('/(g2\.com|capterra\.|getapp\.|trustpilot\.|softwaresuggest\.|techjockey\.)/', $h)));
        $add('review_sites', 'Listings with reviews on G2 / Capterra / Google Business / Indian SaaS directories', $reviews !== [] ? 'partial' : 'unknown',
            $reviews !== [] ? 'Linked from sameAs: ' . implode(', ', $reviews) . '. Review counts not checked.' : 'Not checked automatically — confirm listings and review counts manually.');

        $entities = $wiki['entities'] ?? null;
        $add('wikidata', 'Wikidata entity (Wikipedia needs independent press coverage first)', $entities === null ? 'unknown' : ($entities !== [] ? 'done' : 'missing'),
            $entities === null ? 'Wikidata could not be queried.' : ($entities !== [] ? 'Wikidata: ' . implode(', ', array_column($entities, 'id')) . '.' : 'No Wikidata item labelled ' . ($digest['brand'] ?? 'Aicountly') . '.'));

        $blocked = $robots['blocked_ai_crawlers'] ?? null;
        $add('ai_crawlers_allowed', 'robots.txt lets AI crawlers (GPTBot, Google-Extended, PerplexityBot, ClaudeBot…) read the site', $blocked === null ? 'unknown' : ($blocked === [] ? 'done' : 'missing'),
            $blocked === null ? 'robots.txt could not be read.' : ($blocked === [] ? 'No AI crawler is blocked site-wide.' : 'Blocked site-wide: ' . implode(', ', $blocked) . '.'));

        $cmp = $sitemap['comparison_pages'] ?? null;
        $add('comparison_pages', 'Comparison / alternative pages (e.g. "Aicountly vs Tally", "Tally alternative")', $cmp === null ? 'unknown' : ($cmp >= 3 ? 'done' : ($cmp > 0 ? 'partial' : 'missing')),
            $cmp === null ? 'Sitemap could not be read.' : $cmp . ' comparison/alternative URLs in the sitemap (' . (int) ($sitemap['urls'] ?? 0) . ' URLs total).');

        $docs = $sitemap['help_docs_pages'] ?? null;
        $depth = max((int) $docs, $kbCount);
        $add('docs_depth', 'Help / docs depth (how-to articles per product)', $docs === null && $kbCount === 0 ? 'unknown' : ($depth >= 50 ? 'done' : ($depth > 0 ? 'partial' : 'missing')),
            ($docs === null ? 'Sitemap not readable; ' : $docs . ' help/docs/KB URLs in the sitemap; ') . $kbCount . ' knowledge-base items published from Reach.');

        return $checks;
    }

    /**
     * @param callable(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function section(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            log_message('error', '[InsightsDigest] ' . $e->getMessage());
            return ['available' => false, 'reason' => 'Could not be read: ' . mb_substr($e->getMessage(), 0, 160)];
        }
    }

    private function has(string $table): bool
    {
        return SchemaGuard::hasTable($this->db, $table);
    }

    /** @return array<string, mixed> */
    private function products(): array
    {
        if (! $this->has('reach_products')) {
            return ['available' => false, 'reason' => 'Product catalogue table missing.'];
        }
        $rows = $this->db->table('reach_products')->select('slug, name, short_description, status')
            ->where('status !=', 'archived')->orderBy('name')->limit(30)->get()->getResultArray();

        return $rows === [] ? ['available' => false, 'reason' => 'No products in the Reach catalogue.'] : ['available' => true, 'items' => $rows];
    }

    /** @return array<string, mixed> */
    private function search(string $since, string $until, string $prevStart, string $prevEnd): array
    {
        $useSite = $this->has('reach_search_site_facts')
            && (int) $this->db->table('reach_search_site_facts')->where('metric_date >=', $prevStart)->countAllResults() > 0;
        $table = $useSite ? 'reach_search_site_facts' : 'reach_search_metric_facts';
        if (! $useSite && ! $this->has('reach_search_metric_facts')) {
            return ['available' => false, 'reason' => 'Search Console tables are missing.'];
        }

        $totals = function (string $from, string $to) use ($table): array {
            $r = $this->db->query(
                "SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions,
                        CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS position,
                        MAX(metric_date) AS data_through
                   FROM {$table} WHERE metric_date BETWEEN ? AND ?",
                [$from, $to]
            )->getRowArray() ?? [];
            $clicks = (int) ($r['clicks'] ?? 0);
            $impr   = (int) ($r['impressions'] ?? 0);

            return [
                'clicks'       => $clicks,
                'impressions'  => $impr,
                'ctr'          => $impr > 0 ? round($clicks / $impr, 4) : null,
                'avg_position' => isset($r['position']) ? round((float) $r['position'], 1) : null,
                'data_through' => $r['data_through'] ?? null,
            ];
        };

        $current = $totals($since, $until);
        if ($current['impressions'] === 0 && $current['clicks'] === 0) {
            return ['available' => false, 'reason' => 'No Search Console rows in this period (not connected, or ingestion has not run).', 'source_table' => $table];
        }
        $previous = $totals($prevStart, $prevEnd);

        $grouped = function (string $col, string $extraWhere = '', int $limit = 15) use ($table, $since, $until): array {
            return array_map(static function (array $r): array {
                $impr = (int) $r['impressions'];
                return [
                    'key'          => $r['k'],
                    'clicks'       => (int) $r['clicks'],
                    'impressions'  => $impr,
                    'ctr'          => $impr > 0 ? round((int) $r['clicks'] / $impr, 4) : null,
                    'avg_position' => $r['position'] !== null ? round((float) $r['position'], 1) : null,
                ];
            }, $this->db->query(
                "SELECT {$col} AS k, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
                        CASE WHEN SUM(impressions) > 0 THEN SUM(avg_position * impressions) / SUM(impressions) END AS position
                   FROM {$table}
                  WHERE metric_date BETWEEN ? AND ? AND {$col} IS NOT NULL AND {$col} <> '' {$extraWhere}
                  GROUP BY {$col}
                  ORDER BY SUM(impressions) DESC
                  LIMIT {$limit}",
                [$since, $until]
            )->getResultArray());
        };

        $pages  = $grouped('page_url', '', 200);
        $lowCtr = array_values(array_filter($pages, static fn (array $p): bool => $p['impressions'] >= 100 && $p['ctr'] !== null && $p['ctr'] < 0.02 && $p['avg_position'] !== null && $p['avg_position'] <= 20));

        return [
            'available'     => true,
            'source_table'  => $table,
            'totals'        => $current,
            'previous'      => $previous,
            'top_queries'   => $grouped('query'),
            'top_pages'     => array_slice($pages, 0, 10),
            'low_ctr_pages' => array_slice($lowCtr, 0, 10),
            'low_ctr_rule'  => 'impressions >= 100, CTR < 2%, average position <= 20',
            'countries'     => $grouped('country', '', 10),
        ];
    }

    /** @return array<string, mixed> */
    public function ga4FromAnalytics(): array
    {
        $prop = trim((string) (env('GA4_PROPERTY_ID_MARKETING') ?? ''));
        if ($prop === '') {
            return ['available' => false, 'reason' => 'GA4 is not configured for the marketing site (GA4_PROPERTY_ID_MARKETING).'];
        }
        $res = (new TrafficAnalyticsService())->overview(28, 'marketing_site');
        if (! empty($res['unconfigured']) || ! empty($res['demo'])) {
            return ['available' => false, 'reason' => (string) ($res['data']['reason'] ?? 'GA4 did not return data.')];
        }
        $d = $res['data'] ?? [];

        return [
            'available'  => true,
            'window_days' => 28,
            'totals'     => array_intersect_key((array) ($d['totals'] ?? []), array_flip(['sessions', 'users', 'new_users', 'pageviews', 'bounce_rate', 'engagement_rate'])),
            'comparison' => $d['comparison'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function content(string $since): array
    {
        $out = ['available' => false];
        if ($this->has('reach_content_items')) {
            $rows = $this->db->query(
                "SELECT content_type,
                        COUNT(*) FILTER (WHERE published_at IS NOT NULL OR publication_status = 'published') AS total,
                        COUNT(*) FILTER (WHERE published_at >= ?) AS recent
                   FROM reach_content_items WHERE deleted_at IS NULL GROUP BY content_type",
                [$since]
            )->getResultArray();
            foreach ($rows as $r) {
                $out['published_total_by_type'][$r['content_type']]  = (int) $r['total'];
                $out['published_period_by_type'][$r['content_type']] = (int) $r['recent'];
            }
            $out['available'] = true;
        }
        if ($this->has('reach_blog_posts')) {
            $r = $this->db->query(
                "SELECT COUNT(*) FILTER (WHERE status = 'published') AS total, COUNT(*) FILTER (WHERE status = 'published' AND published_at >= ?) AS recent FROM reach_blog_posts",
                [$since]
            )->getRowArray();
            $out['blog_posts_published_total']  = (int) ($r['total'] ?? 0);
            $out['blog_posts_published_period'] = (int) ($r['recent'] ?? 0);
            $out['available']                   = true;
        }
        if ($this->has('reach_community_official_answers')) {
            $out['community_answers_published_total'] = (int) $this->db->table('reach_community_official_answers')->where('publication_status', 'published')->countAllResults();
        }

        return $out['available'] ? $out : ['available' => false, 'reason' => 'Content tables are missing.'];
    }

    /** @return array<string, mixed> */
    private function vetting(string $since): array
    {
        if (! $this->has('reach_ai_vetting_runs')) {
            return ['available' => false, 'reason' => 'Vetting runs table missing.'];
        }
        $rows = $this->db->query(
            'SELECT content_type, decision, COUNT(*) AS n FROM reach_ai_vetting_runs WHERE created_at >= ? GROUP BY content_type, decision',
            [$since]
        )->getResultArray();
        if ($rows === []) {
            return ['available' => false, 'reason' => 'No content was vetted in this period.'];
        }
        $by = [];
        foreach ($rows as $r) {
            $by[$r['content_type']][$r['decision']] = (int) $r['n'];
        }

        $reasons = [];
        foreach ($this->db->query(
            "SELECT reasons_json FROM reach_ai_vetting_runs WHERE created_at >= ? AND decision IN ('reject','revise') ORDER BY id DESC LIMIT 400",
            [$since]
        )->getResultArray() as $r) {
            $list = is_array($r['reasons_json']) ? $r['reasons_json'] : json_decode((string) $r['reasons_json'], true);
            foreach ((array) $list as $reason) {
                $key = mb_strtolower(trim(mb_substr((string) $reason, 0, 140)));
                if ($key !== '') {
                    $reasons[$key] = ($reasons[$key] ?? 0) + 1;
                }
            }
        }
        arsort($reasons);

        return [
            'available'          => true,
            'decisions_by_type'  => $by,
            'top_reject_or_revise_reasons' => array_map(static fn ($k, $v) => ['reason' => (string) $k, 'count' => $v], array_keys(array_slice($reasons, 0, 10, true)), array_values(array_slice($reasons, 0, 10, true))),
        ];
    }

    /** @return array<string, mixed> */
    private function seoTracker(): array
    {
        if (! $this->has('reach_seo_tracked_keywords') || ! $this->has('reach_seo_serp_checks')) {
            return ['available' => false, 'reason' => 'SEO Tracker is not installed in this build.'];
        }
        $latest = $this->db->query(
            "SELECT DISTINCT ON (c.keyword_id) c.keyword_id, k.keyword, k.priority, c.our_rank, c.check_date
               FROM reach_seo_serp_checks c
               JOIN reach_seo_tracked_keywords k ON k.id = c.keyword_id
              WHERE c.status = 'ok' AND k.enabled = TRUE
              ORDER BY c.keyword_id, c.check_date DESC, c.id DESC"
        )->getResultArray();
        if ($latest === []) {
            return ['available' => false, 'reason' => 'No successful SERP checks yet (DataForSEO not configured or not run).'];
        }
        $ranks   = array_map(static fn (array $r): ?int => $r['our_rank'] !== null ? (int) $r['our_rank'] : null, $latest);
        $missing = array_values(array_filter($latest, static fn (array $r): bool => $r['priority'] === 'high' && ($r['our_rank'] === null || (int) $r['our_rank'] > 10)));

        return [
            'available'        => true,
            'keywords_checked' => count($latest),
            'latest_check'     => max(array_column($latest, 'check_date')),
            'top3'             => count(array_filter($ranks, static fn (?int $r): bool => $r !== null && $r <= 3)),
            'top10'            => count(array_filter($ranks, static fn (?int $r): bool => $r !== null && $r <= 10)),
            'not_ranking'      => count(array_filter($ranks, static fn (?int $r): bool => $r === null)),
            'high_priority_outside_top10' => array_slice(array_map(static fn (array $r): array => ['keyword' => $r['keyword'], 'our_rank' => $r['our_rank'] !== null ? (int) $r['our_rank'] : null], $missing), 0, 15),
        ];
    }

    /** @return array<string, mixed> */
    private function campaigns(string $since): array
    {
        $out = ['available' => false];
        if ($this->has('reach_campaigns')) {
            foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM reach_campaigns GROUP BY status')->getResultArray() as $r) {
                $out['campaigns_by_status'][(string) $r['status']] = (int) $r['n'];
            }
            $out['campaigns_pending_approval'] = (int) $this->db->table('reach_campaigns')->where('approval_status', 'pending')->countAllResults();
            $out['available']                  = true;
        }
        if ($this->has('reach_landing_pages')) {
            foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM reach_landing_pages GROUP BY status')->getResultArray() as $r) {
                $out['landing_pages_by_status'][(string) $r['status']] = (int) $r['n'];
            }
        }
        if ($this->has('reach_leads')) {
            $out['leads_in_period'] = (int) $this->db->table('reach_leads')->where('created_at >=', $since)->countAllResults();
        }
        if ($this->has('reach_social_posts')) {
            foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM reach_social_posts GROUP BY status')->getResultArray() as $r) {
                $out['social_posts_by_status'][(string) $r['status']] = (int) $r['n'];
            }
        }

        return $out['available'] ? $out : ['available' => false, 'reason' => 'Campaign tables are missing.'];
    }

    /** @return array<string, mixed> */
    private function refresh(): array
    {
        if (! $this->has('reach_refresh_recommendations')) {
            return ['available' => false, 'reason' => 'Refresh recommendations table missing.'];
        }
        $by = [];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM reach_refresh_recommendations GROUP BY status')->getResultArray() as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
        }

        return $by === [] ? ['available' => false, 'reason' => 'No content-refresh recommendations have been generated yet.'] : ['available' => true, 'by_status' => $by];
    }
}
