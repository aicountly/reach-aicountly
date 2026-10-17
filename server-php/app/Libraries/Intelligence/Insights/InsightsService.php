<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;

/**
 * AI Insights: sends the data digest to the AI Insights model (Gemini,
 * Settings → AI & Automation) and stores its report — what is working, our
 * gaps, prioritised recommendations, the AI-recommendation readiness
 * checklist, and the internationalisation / investor-readiness section.
 *
 * Honesty rules, enforced here rather than hoped for:
 *  - the model is told to use only numbers in the digest and to put anything
 *    else under `assumptions`;
 *  - NumberAudit lists every figure in the report it cannot trace back to
 *    the digest, stored with the report and shown beside it;
 *  - checklist items Reach could verify (llms.txt, JSON-LD, robots.txt,
 *    sitemap, Wikidata) keep the observed status whatever the model says.
 *
 * No key, a provider error or unusable output store a `failed` report with
 * the reason and raise InsightsUnavailableException — no placeholder text.
 */
final class InsightsService
{
    private const MAX_ATTEMPTS = 2;

    public const OWNER_TYPES = ['content', 'seo', 'engineering', 'marketing', 'partnerships', 'founder', 'product'];

    private BaseConnection $db;
    private InsightsDigestBuilder $builder;

    /** @var callable(string, array): array{status:int, body:string} */
    private $http;

    public function __construct(?BaseConnection $db = null, ?InsightsDigestBuilder $builder = null, ?callable $http = null)
    {
        $this->db      = $db ?? \Config\Database::connect();
        $this->builder = $builder ?? new InsightsDigestBuilder($this->db);
        $this->http    = $http ?? [\App\Libraries\Ai\Vetting\ContentVettingService::class, 'post'];
    }

    /**
     * @return array<string, mixed> the stored report (decoded)
     * @throws InsightsUnavailableException
     */
    public function run(string $trigger = 'manual', ?int $userId = null): array
    {
        $trigger = in_array($trigger, ['scheduled', 'manual', 'bot'], true) ? $trigger : 'manual';
        $model   = ReachConfig::vettingModel('insights');
        $key     = ReachConfig::secret('ai.gemini.api_key');

        if ($key === null || $model === '') {
            $msg = 'AI Insights is not configured: set the Gemini API key (and optionally the AI Insights model) in Settings → AI & Automation.';
            $this->store($trigger, 'failed', $model, ['not_built' => 'AI Insights is not configured.'], null, [], $msg, 0, $userId);
            throw new InsightsUnavailableException($msg);
        }

        $digest  = $this->builder->build();
        $started = hrtime(true);

        $error = 'No usable report.';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $res = ($this->http)($this->url($model, $key), $this->requestBody($digest));
                if ($res['status'] !== 200) {
                    throw new InsightsUnavailableException('AI Insights model returned HTTP ' . $res['status'] . ': ' . self::providerMessage($res['body']));
                }
                $report     = $this->normalise($this->parse($res['body']), $digest);
                $unverified = NumberAudit::unverified($digest, $report);
                $latency    = (int) ((hrtime(true) - $started) / 1_000_000);
                $id         = $this->store($trigger, 'completed', $model, $digest, $report, $unverified, null, $latency, $userId);
                $this->storeActions($id, $report['recommendations']);

                return $this->find($id) ?? [];
            } catch (InsightsUnavailableException $e) {
                $error = $e->getMessage();
            }
        }

        $this->store($trigger, 'failed', $model, $digest, null, [], $error, (int) ((hrtime(true) - $started) / 1_000_000), $userId);
        throw new InsightsUnavailableException($error);
    }

    /** @return array<string, mixed> */
    public function requestBody(array $digest): array
    {
        $str  = ['type' => 'STRING'];
        $strs = ['type' => 'ARRAY', 'items' => $str];
        $obj  = static fn (array $props, array $required): array => ['type' => 'OBJECT', 'properties' => $props, 'required' => $required];

        $schema = $obj([
            'summary'       => $str,
            'whats_working' => ['type' => 'ARRAY', 'items' => $obj(['finding' => $str, 'evidence' => $str], ['finding', 'evidence'])],
            'gaps'          => ['type' => 'ARRAY', 'items' => $obj(['area' => $str, 'finding' => $str, 'evidence' => $str], ['area', 'finding', 'evidence'])],
            'recommendations' => ['type' => 'ARRAY', 'items' => $obj([
                'title'            => $str,
                'detail'           => $str,
                'expected_impact'  => ['type' => 'STRING', 'enum' => ['high', 'medium', 'low']],
                'impact_rationale' => $str,
                'effort'           => ['type' => 'STRING', 'enum' => ['S', 'M', 'L']],
                'owner_type'       => ['type' => 'STRING', 'enum' => self::OWNER_TYPES],
                'horizon_days'     => ['type' => 'INTEGER'],
            ], ['title', 'detail', 'expected_impact', 'impact_rationale', 'effort', 'owner_type', 'horizon_days'])],
            'ai_readiness_checklist' => ['type' => 'ARRAY', 'items' => $obj([
                'key'       => $str,
                'item'      => $str,
                'status'    => ['type' => 'STRING', 'enum' => ['done', 'partial', 'missing', 'unknown']],
                'evidence'  => $str,
                'next_step' => $str,
            ], ['key', 'item', 'status', 'evidence', 'next_step'])],
            'internationalisation' => $obj([
                'markets_to_test'    => ['type' => 'ARRAY', 'items' => $obj(['market' => $str, 'rationale' => $str, 'first_step' => $str], ['market', 'rationale', 'first_step'])],
                'localisation_needs' => $strs,
            ], ['markets_to_test', 'localisation_needs']),
            'investor_readiness' => $obj([
                'metrics_investors_ask' => ['type' => 'ARRAY', 'items' => $obj(['metric' => $str, 'have_data' => ['type' => 'BOOLEAN'], 'evidence_or_gap' => $str], ['metric', 'have_data', 'evidence_or_gap'])],
                'narrative_points'      => $strs,
                'data_room_gaps'        => $strs,
            ], ['metrics_investors_ask', 'narrative_points', 'data_room_gaps']),
            'assumptions' => $strs,
        ], ['summary', 'whats_working', 'gaps', 'recommendations', 'ai_readiness_checklist', 'internationalisation', 'investor_readiness', 'assumptions']);

        $prompt = "DATA DIGEST (JSON — the only facts you may use):\n"
            . json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nWrite the report for the next two weeks. Checklist keys to cover (use these exact keys): "
            . implode(', ', array_column($digest['ai_readiness_checks'] ?? [], 'key')) . '.';

        return [
            'systemInstruction' => ['parts' => [['text' => self::systemPrompt()]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig'  => [
                'temperature'      => 0.3,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema,
            ],
        ];
    }

    public static function systemPrompt(): string
    {
        return <<<'TXT'
You are the growth analyst for AICOUNTLY, an Indian AI-first accounting/billing/inventory SaaS for small businesses (products are listed in the digest). The owner's goals: (1) within 1-2 months, AI assistants (ChatGPT, Gemini, Perplexity, Claude) recommend AICOUNTLY's products for relevant buyer questions; (2) within 3 months, be ready to raise from international investors (e.g. Japanese, Chinese, SE Asian, Middle Eastern funds) and to test markets outside India.

Rules — follow all of them:
- Use ONLY numbers that appear in the digest. Quote them as they appear (a ratio such as 0.034 may be written as 3.4%). Never estimate traffic, revenue, users, market sizes or competitor figures.
- Anything you believe but the digest does not show (market facts, regulation, investor behaviour, typical benchmarks) goes in `assumptions`, written as an assumption, without numbers that are not in the digest.
- When a digest section has "available": false, say the data is missing and treat getting it as a gap; do not guess its values.
- `ai_visibility` holds measured answers from AI assistant APIs: brand_mention_rate is the share of answers naming AICOUNTLY; `gaps` are buyer questions where competitors were named and AICOUNTLY was not. Use them to name content gaps.
- `ai_readiness_checks` are observed facts about the live site. Your `ai_readiness_checklist` must contain one entry per check key with the same status and evidence; add a concrete next_step for each. You may add further items with status "unknown" when they cannot be verified from the digest.
- Recommendations: at most 10, ordered by priority, each doable within the next 14 days by a small team; expected_impact is your judgement (high/medium/low) with the reason tied to digest evidence; effort S (<1 day), M (1-3 days), L (>3 days); owner_type is who does it.
- investor_readiness.metrics_investors_ask: list what international SaaS investors ask for (growth, activation, retention, revenue, CAC, market traction, AI-search presence). have_data is true only when the digest contains that metric; otherwise explain the gap (see not_tracked_in_reach).
- internationalisation: base market choices on digest evidence (search countries, AI-visibility by market) and label reasoning that is not in the digest as an assumption.
- Plain, specific English. No hype, no claims of human review, no promises of rankings.
TXT;
    }

    /** @return array<string, mixed> */
    private function parse(string $body): array
    {
        $json = json_decode($body, true);
        $text = '';
        foreach ((array) ($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (! ($part['thought'] ?? false) && isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $report = json_decode(trim($text), true);
        if (! is_array($report) || ! is_string($report['summary'] ?? null) || ! is_array($report['recommendations'] ?? null)) {
            throw new InsightsUnavailableException('AI Insights model returned no usable report.');
        }

        return $report;
    }

    /**
     * Coerce the model's report into the stored shape and put the observed
     * checklist facts back over whatever the model said about them.
     *
     * @param array<string, mixed> $r
     * @param array<string, mixed> $digest
     * @return array<string, mixed>
     */
    public function normalise(array $r, array $digest): array
    {
        $s    = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';
        $list = static fn (mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        $strs = static fn (mixed $v): array => is_array($v) ? array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v))) : [];

        $recs = [];
        foreach (array_slice($list($r['recommendations'] ?? []), 0, 10) as $rec) {
            if ($s($rec['title'] ?? '') === '') {
                continue;
            }
            $recs[] = [
                'title'            => mb_substr($s($rec['title']), 0, 300),
                'detail'           => $s($rec['detail'] ?? ''),
                'expected_impact'  => in_array($rec['expected_impact'] ?? null, ['high', 'medium', 'low'], true) ? $rec['expected_impact'] : 'medium',
                'impact_rationale' => $s($rec['impact_rationale'] ?? ''),
                'effort'           => in_array($rec['effort'] ?? null, ['S', 'M', 'L'], true) ? $rec['effort'] : 'M',
                'owner_type'       => in_array($rec['owner_type'] ?? null, self::OWNER_TYPES, true) ? $rec['owner_type'] : 'marketing',
                'horizon_days'     => max(1, min(30, (int) ($rec['horizon_days'] ?? 14))),
            ];
        }

        $modelChecks = [];
        foreach ($list($r['ai_readiness_checklist'] ?? []) as $c) {
            $modelChecks[$s($c['key'] ?? '')] = $c;
        }
        $checklist = [];
        foreach ($digest['ai_readiness_checks'] ?? [] as $observed) {
            $m           = $modelChecks[$observed['key']] ?? [];
            $checklist[] = $observed + ['next_step' => $s($m['next_step'] ?? ''), 'source' => 'observed'];
            unset($modelChecks[$observed['key']]);
        }
        foreach ($modelChecks as $key => $c) {
            if ($key === '' || $s($c['item'] ?? '') === '') {
                continue;
            }
            $checklist[] = [
                'key'       => $key,
                'item'      => $s($c['item']),
                'status'    => 'unknown',
                'evidence'  => 'Not verified by Reach. ' . $s($c['evidence'] ?? ''),
                'next_step' => $s($c['next_step'] ?? ''),
                'source'    => 'model',
            ];
        }

        $intl = is_array($r['internationalisation'] ?? null) ? $r['internationalisation'] : [];
        $inv  = is_array($r['investor_readiness'] ?? null) ? $r['investor_readiness'] : [];

        return [
            'summary'       => $s($r['summary'] ?? ''),
            'whats_working' => array_map(static fn (array $w): array => ['finding' => $s($w['finding'] ?? ''), 'evidence' => $s($w['evidence'] ?? '')], $list($r['whats_working'] ?? [])),
            'gaps'          => array_map(static fn (array $g): array => ['area' => $s($g['area'] ?? ''), 'finding' => $s($g['finding'] ?? ''), 'evidence' => $s($g['evidence'] ?? '')], $list($r['gaps'] ?? [])),
            'recommendations' => $recs,
            'ai_readiness_checklist' => $checklist,
            'internationalisation' => [
                'markets_to_test'    => array_map(static fn (array $m): array => ['market' => $s($m['market'] ?? ''), 'rationale' => $s($m['rationale'] ?? ''), 'first_step' => $s($m['first_step'] ?? '')], $list($intl['markets_to_test'] ?? [])),
                'localisation_needs' => $strs($intl['localisation_needs'] ?? []),
            ],
            'investor_readiness' => [
                'metrics_investors_ask' => array_map(static fn (array $m): array => ['metric' => $s($m['metric'] ?? ''), 'have_data' => (bool) ($m['have_data'] ?? false), 'evidence_or_gap' => $s($m['evidence_or_gap'] ?? '')], $list($inv['metrics_investors_ask'] ?? [])),
                'narrative_points'      => $strs($inv['narrative_points'] ?? []),
                'data_room_gaps'        => $strs($inv['data_room_gaps'] ?? []),
            ],
            'assumptions' => $strs($r['assumptions'] ?? []),
        ];
    }

    // ── reads ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('reach_ai_insight_reports')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return null;
        }
        $report            = $this->decode($row, true);
        $report['actions'] = $this->actions(['report_id' => $id]);

        return $report;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $row = $this->db->table('reach_ai_insight_reports')->where('status', 'completed')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return $row ? $this->find((int) $row['id']) : null;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 30): array
    {
        $rows = $this->db->table('reach_ai_insight_reports')
            ->select('id, trigger_type, status, model, period_start, period_end, error, latency_ms, created_at, unverified_numbers, report_json')
            ->orderBy('id', 'DESC')->limit(max(1, min(100, $limit)))->get()->getResultArray();

        return array_map(function (array $r): array {
            $d = $this->decode($r, false);
            $d['summary']              = $d['report']['summary'] ?? null;
            $d['recommendation_count'] = count($d['report']['recommendations'] ?? []);
            unset($d['report']);
            return $d;
        }, $rows);
    }

    /**
     * @param array{report_id?: int, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function actions(array $filters = []): array
    {
        $b = $this->db->table('reach_ai_insight_actions');
        if (isset($filters['report_id'])) {
            $b->where('report_id', (int) $filters['report_id'])->orderBy('position');
        } else {
            $b->orderBy('report_id', 'DESC')->orderBy('position');
        }
        if (! empty($filters['status'])) {
            $b->where('status', $filters['status']);
        }

        return array_map(static function (array $a): array {
            foreach (['id', 'report_id', 'position', 'horizon_days', 'status_changed_by'] as $k) {
                $a[$k] = $a[$k] !== null ? (int) $a[$k] : null;
            }
            return $a;
        }, $b->limit(200)->get()->getResultArray());
    }

    /** @return array<string, mixed>|null */
    public function setActionStatus(int $actionId, string $status, ?int $userId, ?string $note = null): ?array
    {
        if (! in_array($status, ['open', 'done', 'dismissed'], true)) {
            throw new \InvalidArgumentException('status must be open, done or dismissed');
        }
        $row = $this->db->table('reach_ai_insight_actions')->where('id', $actionId)->get()->getRowArray();
        if (! $row) {
            return null;
        }
        $this->db->table('reach_ai_insight_actions')->where('id', $actionId)->update([
            'status'            => $status,
            'status_note'       => $note !== null ? mb_substr($note, 0, 2000) : $row['status_note'],
            'status_changed_by' => $userId,
            'status_changed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $actions = $this->actions(['report_id' => (int) $row['report_id']]);
        foreach ($actions as $a) {
            if ($a['id'] === $actionId) {
                return $a;
            }
        }

        return null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>      $digest
     * @param array<string, mixed>|null $report
     * @param list<string>              $unverified
     */
    private function store(string $trigger, string $status, string $model, array $digest, ?array $report, array $unverified, ?string $error, int $latency, ?int $userId): int
    {
        $digestJson = json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $this->db->table('reach_ai_insight_reports')->insert([
            'trigger_type'       => $trigger,
            'status'             => $status,
            'model'              => $model !== '' ? mb_substr($model, 0, 120) : null,
            'period_start'       => $digest['period']['start'] ?? null,
            'period_end'         => $digest['period']['end'] ?? null,
            'digest_json'        => $digestJson,
            'digest_sha256'      => hash('sha256', $digestJson),
            'report_json'        => $report !== null ? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'unverified_numbers' => json_encode(array_values($unverified), JSON_UNESCAPED_UNICODE),
            'error'              => $error !== null ? mb_substr($error, 0, 2000) : null,
            'latency_ms'         => $latency,
            'requested_by'       => $userId,
        ]);

        return (int) $this->db->insertID();
    }

    /** @param list<array<string, mixed>> $recs */
    private function storeActions(int $reportId, array $recs): void
    {
        foreach ($recs as $i => $rec) {
            $this->db->table('reach_ai_insight_actions')->insert([
                'report_id'        => $reportId,
                'position'         => $i + 1,
                'title'            => $rec['title'],
                'detail'           => $rec['detail'],
                'expected_impact'  => $rec['expected_impact'],
                'impact_rationale' => $rec['impact_rationale'],
                'effort'           => $rec['effort'],
                'owner_type'       => $rec['owner_type'],
                'horizon_days'     => $rec['horizon_days'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row, bool $withDigest): array
    {
        $j = static fn (mixed $v): mixed => is_array($v) ? $v : ($v === null ? null : json_decode((string) $v, true));
        $out = [
            'id'                 => (int) $row['id'],
            'trigger_type'       => $row['trigger_type'] ?? null,
            'status'             => $row['status'],
            'model'              => $row['model'] ?? null,
            'period_start'       => $row['period_start'] ?? null,
            'period_end'         => $row['period_end'] ?? null,
            'report'             => $j($row['report_json'] ?? null),
            'unverified_numbers' => $j($row['unverified_numbers'] ?? '[]') ?? [],
            'error'              => $row['error'] ?? null,
            'latency_ms'         => isset($row['latency_ms']) ? (int) $row['latency_ms'] : null,
            'created_at'         => $row['created_at'] ?? null,
        ];
        if ($withDigest) {
            $out['digest'] = $j($row['digest_json'] ?? '{}');
        }

        return $out;
    }

    private function url(string $model, string $key): string
    {
        $base = $_ENV['AI_GEMINI_BASE_URL'] ?? getenv('AI_GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com';

        return rtrim((string) $base, '/') . '/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
    }

    private static function providerMessage(string $body): string
    {
        $msg = (string) (json_decode($body, true)['error']['message'] ?? '');

        return mb_substr(preg_replace('/key=[^&\s]+/', 'key=[REDACTED]', $msg) ?? '', 0, 300);
    }
}
