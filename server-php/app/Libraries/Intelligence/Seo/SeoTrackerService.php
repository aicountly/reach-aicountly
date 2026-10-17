<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Seo;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * SEO Tracker: daily Google positions for the tracked keywords, for
 * aicountly.com and every active competitor domain, from DataForSEO.
 *
 * Cost-aware by construction: only enabled reach_seo_tracked_keywords are
 * checked, at most `seo.tracker.max_keywords_per_day` per day, a keyword with
 * a successful check for the day is never re-bought, and an auth/billing
 * failure stops the run instead of failing the same way N more times.
 *
 * Honest by construction: no credentials → status `not_configured` and
 * nothing written. A failed check is stored as `failed` with the provider's
 * message, never as "not ranking".
 */
class SeoTrackerService
{
    private BaseConnection $db;

    private DataForSeoSerpClient $client;

    public function __construct(?BaseConnection $db = null, ?DataForSeoSerpClient $client = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->client = $client ?? DataForSeoSerpClient::fromConfig();
    }

    /**
     * @return array{status:string, detail:string, enabled:bool, credentials:bool, max_keywords_per_day:int, location_code:int, language_code:string, depth:int, our_domain:string}
     */
    public function status(): array
    {
        $enabled     = (bool) ReachConfig::get('seo.tracker.enabled', true);
        $credentials = $this->client->isConfigured();
        $migrated    = SchemaGuard::hasTable($this->db, 'reach_seo_serp_checks');

        [$status, $detail] = match (true) {
            ! $migrated    => ['not_migrated', 'SEO Tracker tables are missing. Run: php spark migrate'],
            ! $credentials => ['not_configured', 'Set the DataForSEO login and password under Settings → AI & Automation → SEO data (or DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD).'],
            ! $enabled     => ['disabled', 'SEO Tracker is switched off (seo.tracker.enabled).'],
            default        => ['ready', 'DataForSEO credentials present. Checks run daily at 06:00 IST.'],
        };

        return [
            'status'               => $status,
            'detail'               => $detail,
            'enabled'              => $enabled,
            'credentials'          => $credentials,
            'max_keywords_per_day' => $this->maxPerDay(),
            'location_code'        => (int) ReachConfig::get('seo.tracker.location_code', 2356),
            'language_code'        => (string) ReachConfig::get('seo.tracker.language_code', 'en'),
            'depth'                => $this->depth(),
            'our_domain'           => $this->ourDomain(),
        ];
    }

    /**
     * Check today's due keywords.
     *
     * @return array<string, mixed>
     */
    public function runDaily(?string $date = null, ?int $limit = null, bool $force = false): array
    {
        $date   = $date ?: date('Y-m-d');
        $status = $this->status();

        if ($status['status'] === 'not_migrated' || $status['status'] === 'not_configured'
            || ($status['status'] === 'disabled' && ! $force)) {
            return ['status' => $status['status'], 'detail' => $status['detail'], 'date' => $date, 'checked' => 0];
        }

        $alreadyOk = (int) $this->db->table('reach_seo_serp_checks')
            ->where('check_date', $date)->where('status', 'ok')->countAllResults();
        $budget = max(0, ($limit ?? $this->maxPerDay()) - $alreadyOk);

        $due = $budget === 0 ? [] : $this->db->query(
            "SELECT k.id, k.keyword FROM reach_seo_tracked_keywords k
             WHERE k.enabled = TRUE
               AND NOT EXISTS (SELECT 1 FROM reach_seo_serp_checks c
                               WHERE c.keyword_id = k.id AND c.check_date = ? AND c.status = 'ok')
             ORDER BY CASE k.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                      k.last_checked_at ASC NULLS FIRST, k.id ASC
             LIMIT {$budget}",
            [$date]
        )->getResultArray();

        $competitors = $this->competitors();
        $checked = 0;
        $failed  = 0;
        $cost    = 0.0;
        $errors  = [];
        $stopped = null;

        foreach ($due as $keyword) {
            try {
                $serp = $this->client->searchOrganic(
                    (string) $keyword['keyword'],
                    $status['location_code'],
                    $status['language_code'],
                    $status['depth'],
                );
                $this->storeCheck((int) $keyword['id'], $date, $serp, $competitors, $status);
                $checked++;
                $cost += (float) ($serp['cost'] ?? 0);
            } catch (DataForSeoException $e) {
                $failed++;
                $errors[] = $keyword['keyword'] . ': ' . $e->getMessage();
                $this->storeFailure((int) $keyword['id'], $date, $e->getMessage(), $status);
                if ($e->isFatalForRun()) {
                    $stopped = $e->category;
                    break;
                }
            }
        }

        return [
            'status'     => $failed === 0 ? 'completed' : ($checked > 0 ? 'partial' : 'failed'),
            'date'       => $date,
            'due'        => count($due),
            'checked'    => $checked,
            'failed'     => $failed,
            'cost'       => round($cost, 5),
            'stopped'    => $stopped,
            'errors'     => array_slice($errors, 0, 10),
            'already_ok' => $alreadyOk,
        ];
    }

    /**
     * Pure: best rank per domain of interest from a parsed SERP.
     *
     * @param array{items: list<array{rank_absolute:int, domain:string, url:string}>} $serp
     * @param list<array{id:int, name:string, domain:string}> $competitors
     * @return array{ours: ?array{rank:int, url:string, domain:string}, competitors: array<int, array{rank:int, url:string, domain:string}>}
     */
    public static function bestRanks(array $serp, string $ourDomain, array $competitors): array
    {
        $ours = null;
        $comp = [];
        foreach ($serp['items'] as $item) {
            if ($ours === null && DataForSeoSerpClient::hostMatches($item['domain'], $ourDomain)) {
                $ours = ['rank' => $item['rank_absolute'], 'url' => $item['url'], 'domain' => DataForSeoSerpClient::normaliseHost($ourDomain)];
                continue;
            }
            foreach ($competitors as $c) {
                if (! isset($comp[$c['id']]) && DataForSeoSerpClient::hostMatches($item['domain'], $c['domain'])) {
                    $comp[$c['id']] = ['rank' => $item['rank_absolute'], 'url' => $item['url'], 'domain' => DataForSeoSerpClient::normaliseHost($c['domain'])];
                }
            }
        }

        return ['ours' => $ours, 'competitors' => $comp];
    }

    /**
     * Keyword table for the latest check date: our position, change against
     * the newest check at least 7 days older, each competitor's position and
     * who is winning.
     *
     * @return array<string, mixed>
     */
    public function keywordTable(?string $date = null): array
    {
        $status      = $this->status();
        $competitors = $this->competitors();

        if ($status['status'] === 'not_migrated') {
            return ['status' => $status, 'date' => null, 'competitors' => [], 'keywords' => [], 'summary' => []];
        }

        $date ??= $this->db->query("SELECT MAX(check_date)::text AS d FROM reach_seo_serp_checks WHERE status = 'ok'")->getRowArray()['d'] ?? null;

        $keywords = $this->db->query(
            'SELECT id, keyword, product, priority, enabled, last_checked_at FROM reach_seo_tracked_keywords ORDER BY enabled DESC, product, keyword'
        )->getResultArray();

        $checks = [];
        $prev   = [];
        $ranks  = [];
        if ($date !== null) {
            foreach ($this->db->query('SELECT * FROM reach_seo_serp_checks WHERE check_date = ?', [$date])->getResultArray() as $c) {
                $checks[(int) $c['keyword_id']] = $c;
            }
            // Newest successful check at least 7 days before $date, per keyword.
            foreach ($this->db->query(
                "SELECT DISTINCT ON (keyword_id) keyword_id, check_date::text AS check_date, our_rank
                 FROM reach_seo_serp_checks
                 WHERE status = 'ok' AND check_date <= (?::date - 7)
                 ORDER BY keyword_id, check_date DESC",
                [$date]
            )->getResultArray() as $p) {
                $prev[(int) $p['keyword_id']] = $p;
            }
            foreach ($this->db->query(
                'SELECT keyword_id, competitor_id, is_ours, rank_absolute, url FROM reach_seo_serp_rankings WHERE check_date = ?',
                [$date]
            )->getResultArray() as $r) {
                if ($r['competitor_id'] !== null) {
                    $ranks[(int) $r['keyword_id']][(int) $r['competitor_id']] = (int) $r['rank_absolute'];
                }
            }
        }

        $rows = [];
        $summary = ['checked' => 0, 'we_rank' => 0, 'top10' => 0, 'up' => 0, 'down' => 0, 'competitor_wins' => 0];
        $top10   = ['ours' => 0];
        foreach ($competitors as $c) {
            $top10[$c['id']] = 0;
        }

        foreach ($keywords as $k) {
            $id    = (int) $k['id'];
            $check = $checks[$id] ?? null;
            $ok    = $check !== null && $check['status'] === 'ok';
            $our   = $ok && $check['our_rank'] !== null ? (int) $check['our_rank'] : null;
            $p     = $prev[$id] ?? null;
            $prevRank = $p !== null && $p['our_rank'] !== null ? (int) $p['our_rank'] : null;

            $change = null;
            if ($ok && $p !== null) {
                // Positive = moved up. Entering/leaving the tracked depth counts
                // as a move to/from (depth + 1).
                $depthPlus = $this->depth() + 1;
                $change = ($prevRank ?? $depthPlus) - ($our ?? $depthPlus);
            }

            $compRanks = [];
            $winner    = null;
            if ($ok) {
                $best = $our !== null ? ['name' => 'aicountly', 'rank' => $our, 'ours' => true] : null;
                foreach ($competitors as $c) {
                    $r = $ranks[$id][$c['id']] ?? null;
                    $compRanks[(string) $c['id']] = $r;
                    if ($r !== null && ($best === null || $r < $best['rank'])) {
                        $best = ['name' => $c['name'], 'rank' => $r, 'ours' => false];
                    }
                    if ($r !== null && $r <= 10) {
                        $top10[$c['id']]++;
                    }
                }
                $winner = $best;

                $summary['checked']++;
                if ($our !== null) {
                    $summary['we_rank']++;
                }
                if ($our !== null && $our <= 10) {
                    $summary['top10']++;
                    $top10['ours']++;
                }
                if ($change !== null && $change > 0) {
                    $summary['up']++;
                }
                if ($change !== null && $change < 0) {
                    $summary['down']++;
                }
                if ($winner !== null && ! $winner['ours']) {
                    $summary['competitor_wins']++;
                }
            }

            $rows[] = [
                'id'                => $id,
                'keyword'           => $k['keyword'],
                'product'           => $k['product'],
                'priority'          => $k['priority'],
                'enabled'           => in_array($k['enabled'], [true, 't', 'true', '1', 1], true),
                'check_status'      => $check['status'] ?? null,
                'error'             => $check['error'] ?? null,
                'our_rank'          => $our,
                'our_url'           => $ok ? ($check['our_url'] ?? null) : null,
                'previous_rank'     => $prevRank,
                'previous_date'     => $p['check_date'] ?? null,
                'change'            => $change,
                'competitor_ranks'  => $compRanks,
                'winner'            => $winner,
            ];
        }

        $share = [];
        $denominator = max(1, $summary['checked']);
        $share[] = ['id' => null, 'name' => 'aicountly', 'domain' => $this->ourDomain(), 'ours' => true,
            'top10' => $top10['ours'], 'share' => $summary['checked'] > 0 ? round($top10['ours'] / $denominator, 4) : null];
        foreach ($competitors as $c) {
            $share[] = ['id' => $c['id'], 'name' => $c['name'], 'domain' => $c['domain'], 'ours' => false,
                'top10' => $top10[$c['id']], 'share' => $summary['checked'] > 0 ? round($top10[$c['id']] / $denominator, 4) : null];
        }
        usort($share, static fn ($a, $b) => $b['top10'] <=> $a['top10']);

        return [
            'status'           => $status,
            'date'             => $date,
            'competitors'      => $competitors,
            'keywords'         => $rows,
            'summary'          => $summary,
            'top10_share'      => $share,
        ];
    }

    /** @return list<array{id:int, name:string, domain:string}> */
    public function competitors(): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_competitors')) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT id, name, website_domain FROM reach_competitors
             WHERE tenant_id = 1 AND monitoring_enabled = TRUE AND monitoring_status = 'active'
               AND website_domain IS NOT NULL AND website_domain <> ''
             ORDER BY name"
        )->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'     => (int) $r['id'],
            'name'   => (string) $r['name'],
            'domain' => DataForSeoSerpClient::normaliseHost((string) $r['website_domain']),
        ], $rows);
    }

    /**
     * @param array<string, mixed> $serp
     * @param list<array{id:int, name:string, domain:string}> $competitors
     * @param array<string, mixed> $status
     */
    private function storeCheck(int $keywordId, string $date, array $serp, array $competitors, array $status): void
    {
        $best = self::bestRanks($serp, $this->ourDomain(), $competitors);

        $this->db->transStart();
        $checkId = $this->upsertCheck($keywordId, $date, [
            'status'           => 'ok',
            'our_rank'         => $best['ours']['rank'] ?? null,
            'our_url'          => $best['ours']['url'] ?? null,
            'items_count'      => (int) $serp['items_count'],
            'se_results_count' => $serp['se_results_count'],
            'cost'             => $serp['cost'],
            'error'            => null,
        ], $status);

        $this->db->query('DELETE FROM reach_seo_serp_rankings WHERE check_id = ?', [$checkId]);
        if ($best['ours'] !== null) {
            $this->db->query(
                'INSERT INTO reach_seo_serp_rankings (check_id, keyword_id, check_date, competitor_id, is_ours, domain, rank_absolute, url)
                 VALUES (?, ?, ?, NULL, TRUE, ?, ?, ?)',
                [$checkId, $keywordId, $date, $best['ours']['domain'], $best['ours']['rank'], $best['ours']['url']]
            );
        }
        foreach ($best['competitors'] as $competitorId => $r) {
            $this->db->query(
                'INSERT INTO reach_seo_serp_rankings (check_id, keyword_id, check_date, competitor_id, is_ours, domain, rank_absolute, url)
                 VALUES (?, ?, ?, ?, FALSE, ?, ?, ?)
                 ON CONFLICT (keyword_id, check_date, domain) DO NOTHING',
                [$checkId, $keywordId, $date, $competitorId, $r['domain'], $r['rank'], $r['url']]
            );
        }
        $this->db->query('UPDATE reach_seo_tracked_keywords SET last_checked_at = NOW() WHERE id = ?', [$keywordId]);
        $this->db->transComplete();
    }

    /** @param array<string, mixed> $status */
    private function storeFailure(int $keywordId, string $date, string $error, array $status): void
    {
        // Never overwrite a successful check for the day with a later failure.
        $existing = $this->db->query(
            'SELECT status FROM reach_seo_serp_checks WHERE keyword_id = ? AND check_date = ?',
            [$keywordId, $date]
        )->getRowArray();
        if (($existing['status'] ?? null) === 'ok') {
            return;
        }

        $this->upsertCheck($keywordId, $date, [
            'status' => 'failed', 'our_rank' => null, 'our_url' => null, 'items_count' => null,
            'se_results_count' => null, 'cost' => null, 'error' => mb_substr($error, 0, 2000),
        ], $status);
        $this->db->query('UPDATE reach_seo_tracked_keywords SET last_checked_at = NOW() WHERE id = ?', [$keywordId]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $status
     */
    private function upsertCheck(int $keywordId, string $date, array $values, array $status): int
    {
        $row = $this->db->query(
            "INSERT INTO reach_seo_serp_checks
                (keyword_id, check_date, status, provider, location_code, language_code, depth,
                 our_rank, our_url, items_count, se_results_count, cost, error, checked_at)
             VALUES (?, ?, ?, 'dataforseo', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON CONFLICT (keyword_id, check_date) DO UPDATE SET
                status = EXCLUDED.status, location_code = EXCLUDED.location_code,
                language_code = EXCLUDED.language_code, depth = EXCLUDED.depth,
                our_rank = EXCLUDED.our_rank, our_url = EXCLUDED.our_url,
                items_count = EXCLUDED.items_count, se_results_count = EXCLUDED.se_results_count,
                cost = EXCLUDED.cost, error = EXCLUDED.error, checked_at = NOW()
             RETURNING id",
            [
                $keywordId, $date, $values['status'], $status['location_code'], $status['language_code'], $status['depth'],
                $values['our_rank'], $values['our_url'], $values['items_count'], $values['se_results_count'],
                $values['cost'], $values['error'],
            ]
        )->getRowArray();

        return (int) $row['id'];
    }

    private function maxPerDay(): int
    {
        return max(0, (int) ReachConfig::get('seo.tracker.max_keywords_per_day', 35));
    }

    private function depth(): int
    {
        return max(10, min(100, (int) ReachConfig::get('seo.tracker.depth', 100)));
    }

    private function ourDomain(): string
    {
        $domain = DataForSeoSerpClient::normaliseHost((string) ReachConfig::get('seo.tracker.our_domain', 'aicountly.com'));

        return $domain !== '' ? $domain : 'aicountly.com';
    }
}
