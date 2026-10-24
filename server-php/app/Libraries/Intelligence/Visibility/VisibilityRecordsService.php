<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

use App\Libraries\Intelligence\VisibilityExecutionService;
use CodeIgniter\Database\BaseConnection;

/**
 * The records behind every AI-visibility number: the answer (run) list, one
 * answer with its raw text, observations and citations, the observation list,
 * and per-competitor mention rates.
 *
 * Each facet filter applies the same rule AiVisibilityService::summary()
 * counts with (names asked about in the question are not counted, the brand
 * is only "named" when the question did not name it, a gap is a counted
 * answer that names a competitor but not the brand), so a number on the
 * overview and the list it opens always agree.
 */
final class VisibilityRecordsService
{
    public const RUN_STATUSES      = ['queued', 'running', 'completed', 'failed', 'cancelled'];
    public const COVERAGE_STATES   = ['mentioned', 'not_mentioned', 'partial', 'unclear'];
    public const MENTION_TYPES     = ['brand', 'product', 'competitor', 'domain', 'unknown'];
    public const BRAND_FACETS      = ['named', 'not_named', 'cited'];

    /** Facets computed per answer from its observations — they imply completed answers only. */
    private const FACETS = ['entity', 'competitor_id', 'brand', 'gap', 'cited_domain'];

    private BaseConnection $db;
    private int $tenantId;

    public function __construct(?BaseConnection $db = null, int $tenantId = 1)
    {
        $this->db       = $db ?? \Config\Database::connect();
        $this->tenantId = $tenantId;
    }

    public static function brandName(): string
    {
        return VisibilityExecutionService::brandAliases()[0];
    }

    /**
     * UTC start of the last $days IST days — the window summary() uses.
     */
    public static function since(int $days): string
    {
        $days = max(1, min(180, $days));
        $ist  = new \DateTimeZone('Asia/Kolkata');
        $from = (new \DateTimeImmutable(AiVisibilityService::istToday(), $ist))->modify('-' . ($days - 1) . ' days');

        return $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Answers (runs), newest first.
     *
     * Filters: days, status, provider, prompt_id, market, batch_id, q
     * (question contains), and the facets entity, competitor_id,
     * brand (named|not_named|cited), gap=1, cited_domain.
     *
     * @param array<string, mixed> $f
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function runs(array $f, int $page = 1, int $perPage = 50): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $f       = $this->normaliseRunFilters($f);
        $facets  = array_intersect_key($f, array_flip(self::FACETS));

        [$where, $binds] = $this->runWhere($f, $facets !== []);
        $select = "SELECT r.id, r.status, r.ai_provider, r.ai_model, r.run_type, r.queued_at, r.started_at, r.completed_at,
                          r.error_message, r.batch_id, r.tokens_used, r.actual_cost_cents,
                          p.id AS prompt_id, p.name AS prompt_name, p.persona AS market, v.prompt_text, v.version_number
                     FROM reach_ai_visibility_runs r
                     JOIN reach_ai_visibility_prompt_versions v ON v.id = r.prompt_version_id
                     JOIN reach_ai_visibility_prompts p ON p.id = v.prompt_id
                    WHERE {$where}
                    ORDER BY r.id DESC";

        if ($facets === []) {
            $total = (int) ($this->db->query(
                "SELECT COUNT(*) AS n FROM reach_ai_visibility_runs r
                   JOIN reach_ai_visibility_prompt_versions v ON v.id = r.prompt_version_id
                   JOIN reach_ai_visibility_prompts p ON p.id = v.prompt_id
                  WHERE {$where}",
                $binds
            )->getRowArray()['n'] ?? 0);
            $rows  = $this->db->query($select . ' LIMIT ? OFFSET ?', array_merge($binds, [$perPage, ($page - 1) * $perPage]))->getResultArray();
            $facts = $this->facts(array_map('intval', array_column($rows, 'id')));
        } else {
            // Facets are decided per answer from its observations, so filter
            // in PHP with the summary's rules. The sample is small (one answer
            // per prompt per assistant per day), capped for safety.
            $all   = $this->db->query($select . ' LIMIT 20000', $binds)->getResultArray();
            $facts = $this->facts(array_map('intval', array_column($all, 'id')));
            $all   = array_values(array_filter($all, fn (array $r): bool => $this->matches($facts[(int) $r['id']] ?? null, $facets)));
            $total = count($all);
            $rows  = array_slice($all, ($page - 1) * $perPage, $perPage);
        }

        return [
            'rows'     => array_map(fn (array $r): array => $this->shapeRun($r, $facts[(int) $r['id']] ?? null), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'filters'  => $f,
        ];
    }

    /**
     * One answer: what was asked, the raw answer text, every observation the
     * parser recorded and every cited source.
     *
     * @return array<string, mixed>|null
     */
    public function run(int $id): ?array
    {
        $row = $this->db->query(
            "SELECT r.id, r.uuid, r.status, r.ai_provider, r.ai_model, r.ai_route, r.run_type, r.queued_at, r.started_at, r.completed_at,
                    r.error_message, r.batch_id, r.tokens_used, r.actual_cost_cents, r.prompt_version_id,
                    p.id AS prompt_id, p.name AS prompt_name, p.persona AS market, p.topic, p.intent, p.locale,
                    v.prompt_text, v.version_number
               FROM reach_ai_visibility_runs r
               JOIN reach_ai_visibility_prompt_versions v ON v.id = r.prompt_version_id
               JOIN reach_ai_visibility_prompts p ON p.id = v.prompt_id
              WHERE r.id = ? AND r.tenant_id = ?",
            [$id, $this->tenantId]
        )->getRowArray();
        if (! $row) {
            return null;
        }

        $responses = [];
        foreach ($this->db->table('reach_ai_visibility_responses')->where('run_id', $id)->orderBy('id')->get()->getResultArray() as $resp) {
            $raw         = json_decode((string) $resp['raw_response'], true);
            $responses[] = [
                'id'                 => (int) $resp['id'],
                'response_timestamp' => $resp['response_timestamp'],
                'parser_version'     => $resp['parser_version'],
                'parse_status'       => $resp['parse_status'],
                'tokens_used'        => $resp['tokens_used'] !== null ? (int) $resp['tokens_used'] : null,
                'model'              => is_array($raw) ? ($raw['model'] ?? null) : null,
                'text'               => is_array($raw) ? (string) ($raw['text'] ?? '') : (string) $resp['raw_response'],
            ];
        }

        $observations = array_map(static function (array $o): array {
            $finding = is_array($o['parser_finding']) ? $o['parser_finding'] : json_decode((string) $o['parser_finding'], true);
            $finding = is_array($finding) ? $finding : [];

            return [
                'id'               => (int) $o['id'],
                'entity_mentioned' => $o['entity_mentioned'],
                'mention_type'     => $o['mention_type'],
                'mention_order'    => $o['mention_order'] !== null ? (int) $o['mention_order'] : null,
                'coverage_state'   => $o['coverage_state'],
                'sentiment'        => $o['sentiment_classification'],
                'confidence'       => $o['confidence'],
                'evidence_excerpt' => $o['evidence_excerpt'],
                'in_question'      => (bool) ($finding['in_question'] ?? false),
                'competitor_id'    => isset($finding['competitor_id']) ? (int) $finding['competitor_id'] : null,
                'matched'          => $finding['matched'] ?? null,
            ];
        }, $this->db->table('reach_ai_visibility_observations')->where('run_id', $id)->orderBy('id')->get()->getResultArray());

        $citations = $this->db->query(
            'SELECT c.id, c.cited_url, c.cited_domain, c.citation_type, c.citation_order, c.linked_content_identity_id
               FROM reach_ai_visibility_citations c
               JOIN reach_ai_visibility_responses s ON s.id = c.response_id
              WHERE s.run_id = ?
              ORDER BY c.citation_order NULLS LAST, c.id',
            [$id]
        )->getResultArray();

        $facts = $this->facts([$id])[$id] ?? null;

        return $this->shapeRun($row, $facts) + [
            'uuid'         => $row['uuid'],
            'ai_route'     => $row['ai_route'],
            'topic'        => $row['topic'],
            'intent'       => $row['intent'],
            'locale'       => $row['locale'],
            'responses'    => $responses,
            'observations' => $observations,
            'citations'    => $citations,
        ];
    }

    /**
     * Observation rows (one per name per answer), newest first.
     *
     * Filters: run_id, coverage_state, mention_type, entity, competitor_id,
     * provider, prompt_id, days, counted=1 (leave out names that were asked
     * about in the question — the rows a mention rate counts).
     *
     * @param array<string, mixed> $f
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function observations(array $f, int $page = 1, int $perPage = 50): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $clean   = [];
        $where   = ['r.tenant_id = ?'];
        $binds   = [$this->tenantId];

        if (($runId = (int) ($f['run_id'] ?? 0)) > 0) {
            $where[] = 'o.run_id = ?';
            $binds[] = $clean['run_id'] = $runId;
        }
        if (in_array($f['coverage_state'] ?? null, self::COVERAGE_STATES, true)) {
            $where[] = 'o.coverage_state = ?';
            $binds[] = $clean['coverage_state'] = $f['coverage_state'];
        }
        if (in_array($f['mention_type'] ?? null, self::MENTION_TYPES, true)) {
            $where[] = 'o.mention_type = ?';
            $binds[] = $clean['mention_type'] = $f['mention_type'];
        }
        $entity = trim((string) ($f['entity'] ?? ''));
        if ($entity !== '') {
            $clean['entity'] = $entity;
            if ($entity === self::brandName()) {
                $where[] = "o.mention_type = 'brand'";
            } else {
                $where[] = 'o.entity_mentioned = ?';
                $binds[] = $entity;
            }
        }
        if (($cid = (int) ($f['competitor_id'] ?? 0)) > 0) {
            $where[] = "o.mention_type = 'competitor' AND (o.parser_finding->>'competitor_id') = ?";
            $binds[] = (string) $cid;
            $clean['competitor_id'] = $cid;
        }
        $provider = trim((string) ($f['provider'] ?? ''));
        if ($provider !== '') {
            $where[] = 'r.ai_provider = ?';
            $binds[] = $clean['provider'] = $provider;
        }
        if (($pid = (int) ($f['prompt_id'] ?? 0)) > 0) {
            $where[] = 'p.id = ?';
            $binds[] = $clean['prompt_id'] = $pid;
        }
        if (($days = (int) ($f['days'] ?? 0)) > 0) {
            $where[] = "r.status = 'completed' AND COALESCE(r.completed_at, r.queued_at) >= ?";
            $binds[] = self::since($days);
            $clean['days'] = max(1, min(180, $days));
        }
        if (! empty($f['counted']) && $f['counted'] !== '0') {
            $where[] = "COALESCE((o.parser_finding->>'in_question')::boolean, FALSE) = FALSE";
            $clean['counted'] = 1;
        }
        $whereSql = implode(' AND ', $where);
        $from     = "FROM reach_ai_visibility_observations o
                     JOIN reach_ai_visibility_runs r ON r.id = o.run_id
                     JOIN reach_ai_visibility_prompt_versions v ON v.id = r.prompt_version_id
                     JOIN reach_ai_visibility_prompts p ON p.id = v.prompt_id";

        $total = (int) ($this->db->query("SELECT COUNT(*) AS n {$from} WHERE {$whereSql}", $binds)->getRowArray()['n'] ?? 0);
        $rows  = $this->db->query(
            "SELECT o.id, o.run_id, o.entity_mentioned, o.mention_type, o.mention_order, o.coverage_state, o.evidence_excerpt,
                    o.parser_finding, o.created_at, r.ai_provider, r.ai_model, COALESCE(r.completed_at, r.queued_at) AS asked_at,
                    p.id AS prompt_id, p.name AS prompt_name, p.persona AS market, v.prompt_text
               {$from}
              WHERE {$whereSql}
              ORDER BY o.id DESC
              LIMIT ? OFFSET ?",
            array_merge($binds, [$perPage, ($page - 1) * $perPage])
        )->getResultArray();

        $rows = array_map(static function (array $o): array {
            $finding = is_array($o['parser_finding']) ? $o['parser_finding'] : json_decode((string) $o['parser_finding'], true);
            $finding = is_array($finding) ? $finding : [];
            unset($o['parser_finding']);
            $o['id']            = (int) $o['id'];
            $o['run_id']        = (int) $o['run_id'];
            $o['prompt_id']     = (int) $o['prompt_id'];
            $o['mention_order'] = $o['mention_order'] !== null ? (int) $o['mention_order'] : null;
            $o['in_question']   = (bool) ($finding['in_question'] ?? false);
            $o['competitor_id'] = isset($finding['competitor_id']) ? (int) $finding['competitor_id'] : null;
            $o['brand_cited']   = (bool) ($finding['brand_cited'] ?? false);

            return $o;
        }, $rows);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'filters' => $clean];
    }

    /**
     * Sample mention rate per competitor over the last $days IST days:
     * answers naming it (not in the question) ÷ completed answers — the
     * "Names in answers" mention rate on the overview.
     *
     * @param list<int>|null $competitorIds null = every competitor with a mention
     * @return array<int, array{answers: int, mentions: int, mention_rate: float, days: int}>
     */
    public function competitorRates(int $days = 28, ?array $competitorIds = null): array
    {
        $days    = max(1, min(180, $days));
        $since   = self::since($days);
        $answers = (int) ($this->db->query(
            "SELECT COUNT(*) AS n FROM reach_ai_visibility_runs r
              WHERE r.tenant_id = ? AND r.status = 'completed' AND COALESCE(r.completed_at, r.queued_at) >= ?",
            [$this->tenantId, $since]
        )->getRowArray()['n'] ?? 0);

        $rows = $this->db->query(
            "SELECT (o.parser_finding->>'competitor_id')::bigint AS competitor_id, COUNT(DISTINCT o.run_id) AS mentions
               FROM reach_ai_visibility_observations o
               JOIN reach_ai_visibility_runs r ON r.id = o.run_id
              WHERE r.tenant_id = ? AND r.status = 'completed' AND COALESCE(r.completed_at, r.queued_at) >= ?
                AND o.mention_type = 'competitor' AND o.coverage_state = 'mentioned'
                AND (o.parser_finding->>'competitor_id') IS NOT NULL
                AND COALESCE((o.parser_finding->>'in_question')::boolean, FALSE) = FALSE
              GROUP BY 1",
            [$this->tenantId, $since]
        )->getResultArray();

        $mentions = [];
        foreach ($rows as $r) {
            $mentions[(int) $r['competitor_id']] = (int) $r['mentions'];
        }

        $out = [];
        foreach ($competitorIds ?? array_keys($mentions) as $cid) {
            $m         = $mentions[(int) $cid] ?? 0;
            $out[(int) $cid] = [
                'answers'      => $answers,
                'mentions'     => $m,
                'mention_rate' => $answers > 0 ? round($m / $answers, 4) : 0.0,
                'days'         => $days,
            ];
        }

        return $out;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function normaliseRunFilters(array $f): array
    {
        $out = [];
        if (($days = (int) ($f['days'] ?? 0)) > 0) {
            $out['days'] = max(1, min(180, $days));
        }
        if (in_array($f['status'] ?? null, self::RUN_STATUSES, true)) {
            $out['status'] = $f['status'];
        }
        foreach (['provider', 'market', 'q', 'entity', 'cited_domain'] as $k) {
            $v = trim((string) ($f[$k] ?? ''));
            if ($v !== '') {
                $out[$k] = mb_substr($v, 0, 200);
            }
        }
        foreach (['prompt_id', 'batch_id', 'competitor_id'] as $k) {
            if (($v = (int) ($f[$k] ?? 0)) > 0) {
                $out[$k] = $v;
            }
        }
        if (in_array($f['brand'] ?? null, self::BRAND_FACETS, true)) {
            $out['brand'] = $f['brand'];
        }
        if (! empty($f['gap']) && $f['gap'] !== '0') {
            $out['gap'] = 1;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>}
     */
    private function runWhere(array $f, bool $completedOnly): array
    {
        $where = ['r.tenant_id = ?'];
        $binds = [$this->tenantId];

        if (isset($f['days'])) {
            $where[] = 'COALESCE(r.completed_at, r.queued_at) >= ?';
            $binds[] = self::since((int) $f['days']);
        }
        if ($completedOnly) {
            $where[] = "r.status = 'completed'";
        } elseif (isset($f['status'])) {
            $where[] = 'r.status = ?';
            $binds[] = $f['status'];
        }
        if (isset($f['provider'])) {
            $where[] = 'r.ai_provider = ?';
            $binds[] = $f['provider'];
        }
        if (isset($f['prompt_id'])) {
            $where[] = 'p.id = ?';
            $binds[] = $f['prompt_id'];
        }
        if (isset($f['batch_id'])) {
            $where[] = 'r.batch_id = ?';
            $binds[] = $f['batch_id'];
        }
        if (isset($f['market'])) {
            // summary() files prompts without a persona under "Unspecified".
            if ($f['market'] === 'Unspecified') {
                $where[] = "COALESCE(p.persona, '') = ''";
            } else {
                $where[] = 'p.persona = ?';
                $binds[] = $f['market'];
            }
        }
        if (isset($f['q'])) {
            $where[] = '(v.prompt_text ILIKE ? OR p.name ILIKE ?)';
            $like    = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $binds[] = $like;
            $binds[] = $like;
        }

        return [implode(' AND ', $where), $binds];
    }

    /**
     * What each answer counted for, with summary()'s rules.
     *
     * @param list<int> $runIds
     * @return array<int, array<string, mixed>>
     */
    private function facts(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }
        $brandName = self::brandName();
        $obs       = [];
        $domains   = [];
        foreach (array_chunk($runIds, 500) as $chunk) {
            foreach ($this->db->table('reach_ai_visibility_observations')
                ->select('run_id, entity_mentioned, mention_type, mention_order, coverage_state, parser_finding')
                ->whereIn('run_id', $chunk)->orderBy('id')->get()->getResultArray() as $o) {
                $finding      = is_array($o['parser_finding']) ? $o['parser_finding'] : json_decode((string) $o['parser_finding'], true);
                $o['finding'] = is_array($finding) ? $finding : [];
                $obs[(int) $o['run_id']][] = $o;
            }
            foreach ($this->db->table('reach_ai_visibility_citations')
                ->select('reach_ai_visibility_responses.run_id, reach_ai_visibility_citations.cited_domain')
                ->join('reach_ai_visibility_responses', 'reach_ai_visibility_responses.id = reach_ai_visibility_citations.response_id')
                ->whereIn('reach_ai_visibility_responses.run_id', $chunk)
                ->get()->getResultArray() as $c) {
                $d = (string) $c['cited_domain'];
                if ($d !== '') {
                    $domains[(int) $c['run_id']][$d] = true;
                }
            }
        }

        $out = [];
        foreach ($runIds as $id) {
            $entities = [];
            $compIds  = [];
            $eligible = true;
            $cited    = false;
            $rank     = null;
            foreach ($obs[$id] ?? [] as $o) {
                $inQ = (bool) ($o['finding']['in_question'] ?? false);
                if ($o['mention_type'] === 'brand') {
                    $eligible = ! $inQ;
                    $cited    = $cited || (bool) ($o['finding']['brand_cited'] ?? false);
                }
                if ($o['coverage_state'] === 'mentioned' && ! $inQ) {
                    $name            = $o['mention_type'] === 'brand' ? $brandName : (string) $o['entity_mentioned'];
                    $entities[$name] = $o['mention_order'] !== null ? (int) $o['mention_order'] : null;
                    if ($o['mention_type'] === 'competitor' && isset($o['finding']['competitor_id'])) {
                        $compIds[(int) $o['finding']['competitor_id']] = true;
                    }
                }
            }
            $named       = array_key_exists($brandName, $entities);
            $competitors = array_values(array_filter(array_keys($entities), static fn (string $n): bool => $n !== $brandName));
            if ($named) {
                $rank = $entities[$brandName];
            }
            $out[$id] = [
                'has_observations' => isset($obs[$id]),
                'entities'         => $entities,
                'competitors'      => $competitors,
                'competitor_ids'   => array_keys($compIds),
                'brand_counted'    => $eligible,
                'brand_named'      => $named,
                'brand_rank'       => $rank,
                'brand_cited'      => $cited,
                'gap'              => $eligible && ! $named && $competitors !== [],
                'domains'          => array_keys($domains[$id] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $facts
     * @param array<string, mixed>      $facets
     */
    private function matches(?array $facts, array $facets): bool
    {
        if ($facts === null) {
            return false;
        }
        if (isset($facets['entity']) && ! array_key_exists((string) $facets['entity'], $facts['entities'])) {
            return false;
        }
        if (isset($facets['competitor_id']) && ! in_array((int) $facets['competitor_id'], $facts['competitor_ids'], true)) {
            return false;
        }
        if (isset($facets['brand'])) {
            $ok = match ($facets['brand']) {
                'named'     => $facts['brand_named'],
                'not_named' => $facts['brand_counted'] && ! $facts['brand_named'],
                'cited'     => $facts['brand_cited'],
                default     => true,
            };
            if (! $ok) {
                return false;
            }
        }
        if (isset($facets['gap']) && ! $facts['gap']) {
            return false;
        }
        if (isset($facets['cited_domain']) && ! in_array((string) $facets['cited_domain'], $facts['domains'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>      $r
     * @param array<string, mixed>|null $facts
     * @return array<string, mixed>
     */
    private function shapeRun(array $r, ?array $facts): array
    {
        $completed = $r['status'] === 'completed';

        return [
            'run_id'            => (int) $r['id'],
            'id'                => (int) $r['id'],
            'status'            => $r['status'],
            'provider'          => $r['ai_provider'],
            'model'             => $r['ai_model'],
            'run_type'          => $r['run_type'],
            'prompt_id'         => (int) $r['prompt_id'],
            'prompt_name'       => $r['prompt_name'],
            'prompt_version'    => (int) $r['version_number'],
            'market'            => $r['market'],
            'question'          => $r['prompt_text'],
            'queued_at'         => $r['queued_at'],
            'started_at'        => $r['started_at'],
            'completed_at'      => $r['completed_at'],
            'asked_at'          => $r['completed_at'] ?? $r['queued_at'],
            'error'             => $r['error_message'],
            'batch_id'          => $r['batch_id'] !== null ? (int) $r['batch_id'] : null,
            'tokens_used'       => $r['tokens_used'] !== null ? (int) $r['tokens_used'] : null,
            'actual_cost_cents' => $r['actual_cost_cents'] !== null ? (int) $r['actual_cost_cents'] : null,
            'brand_counted'     => $completed && $facts !== null ? $facts['brand_counted'] : null,
            'brand_mentioned'   => $completed && $facts !== null && $facts['has_observations'] ? $facts['brand_named'] : null,
            'brand_rank'        => $completed && $facts !== null ? $facts['brand_rank'] : null,
            'brand_cited'       => $completed && $facts !== null ? $facts['brand_cited'] : false,
            'gap'               => $completed && $facts !== null ? $facts['gap'] : false,
            'competitors'       => $facts['competitors'] ?? [],
            'cited_domains'     => $facts['domains'] ?? [],
        ];
    }
}
