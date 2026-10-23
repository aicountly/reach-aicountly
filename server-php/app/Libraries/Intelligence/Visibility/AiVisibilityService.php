<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

use App\Libraries\Intelligence\IntelligenceJobTypes;
use App\Libraries\Intelligence\VisibilityExecutionService;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * AI visibility (GEO): plans the daily pass over the prompt set, and turns
 * stored answers into share-of-voice, mention rate and rank per assistant.
 *
 * Every figure here is counted from stored answers — nothing is estimated.
 * The sample is small (one answer per prompt per assistant per day, over the
 * providers' APIs, which are not identical to the consumer apps), so the UI
 * and the insights digest carry that caveat with the numbers.
 */
final class AiVisibilityService
{
    public const SAMPLE_NOTE = 'One API answer per prompt per assistant per day. API answers can differ from the consumer apps (ChatGPT, Gemini, Perplexity) and vary run to run; treat rates as a trend, not a market share.';

    private BaseConnection $db;
    private AssistantRegistry $registry;

    /** @var callable(int): void */
    private $enqueue;

    private ?VisibilityExecutionService $executor;

    public function __construct(?BaseConnection $db = null, ?AssistantRegistry $registry = null, ?callable $enqueue = null, ?VisibilityExecutionService $executor = null)
    {
        $this->db       = $db ?? \Config\Database::connect();
        $this->registry = $registry ?? new AssistantRegistry();
        $this->enqueue  = $enqueue ?? static function (int $runId): void {
            Services::jobService()->enqueue(IntelligenceJobTypes::VISIBILITY_RUN_EXECUTE, ['run_id' => $runId], [
                'queue'               => 'default',
                'idempotency_key'     => 'visibility-run-' . $runId,
                'max_attempts'        => 1,
                'enqueued_actor_type' => 'system',
            ]);
        };
        $this->executor = $executor;
    }

    /** @return list<array<string, mixed>> */
    public function providers(): array
    {
        return $this->registry->status();
    }

    public static function istToday(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
    }

    /** UTC timestamp of 00:00 IST today. */
    private static function istMidnightUtc(): string
    {
        $ist = new \DateTimeImmutable(self::istToday() . ' 00:00:00', new \DateTimeZone('Asia/Kolkata'));

        return $ist->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Ask today's prompts to every runnable assistant.
     *
     * Scheduled passes run once per IST day and respect `visibility.enabled`;
     * manual passes ignore the switch but not the daily cap. Prompts whose
     * current version already ran today are not asked again.
     *
     * @return array<string, mixed> the batch row plus `run_ids`
     */
    public function startBatch(string $trigger = 'scheduled', ?int $userId = null, bool $inline = false): array
    {
        $trigger = $trigger === 'manual' ? 'manual' : 'scheduled';
        $today   = self::istToday();

        if ($trigger === 'scheduled') {
            $existing = $this->db->table('reach_ai_visibility_batches')
                ->where('batch_date', $today)->where('trigger_type', 'scheduled')
                ->get()->getRowArray();
            if ($existing) {
                return $this->decodeBatch($existing) + ['run_ids' => [], 'already_ran' => true];
            }
        }

        $status = $this->providers();
        $this->db->table('reach_ai_visibility_batches')->insert([
            'tenant_id'      => 1,
            'batch_date'     => $today,
            'trigger_type'   => $trigger,
            'status'         => 'running',
            'providers_json' => json_encode($status, JSON_UNESCAPED_UNICODE),
            'requested_by'   => $userId,
        ]);
        $batchId = (int) $this->db->insertID();

        if ($trigger === 'scheduled' && ! ReachConfig::get('visibility.enabled', true)) {
            return $this->finishSkipped($batchId, 'Daily AI-visibility checks are switched off (Settings → AI & Automation).');
        }

        $runnable = $this->registry->runnable();
        if ($runnable === []) {
            return $this->finishSkipped($batchId, 'No assistant is configured: add a Gemini, OpenAI or Perplexity API key in Settings → AI & Automation.');
        }

        $cap       = max(0, (int) ReachConfig::get('visibility.daily_prompt_limit', 25));
        $since     = self::istMidnightUtc();
        $usedToday = (int) ($this->db->query(
            'SELECT COUNT(DISTINCT prompt_version_id) AS n FROM reach_ai_visibility_runs WHERE tenant_id = 1 AND queued_at >= ?',
            [$since]
        )->getRowArray()['n'] ?? 0);
        $remaining = $cap - $usedToday;
        if ($remaining <= 0) {
            return $this->finishSkipped($batchId, "Today's cap of {$cap} prompts is used ({$usedToday} asked). Raise visibility.daily_prompt_limit to ask more.");
        }

        $prompts = $this->db->query(
            "SELECT p.id AS prompt_id, p.name, v.id AS version_id, MAX(r.queued_at) AS last_run
               FROM reach_ai_visibility_prompts p
               JOIN reach_ai_visibility_prompt_versions v ON v.prompt_id = p.id AND v.is_active = TRUE
               LEFT JOIN reach_ai_visibility_prompt_versions v2 ON v2.prompt_id = p.id
               LEFT JOIN reach_ai_visibility_runs r ON r.prompt_version_id = v2.id
              WHERE p.tenant_id = 1 AND p.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM reach_ai_visibility_runs t WHERE t.prompt_version_id = v.id AND t.queued_at >= ?)
              GROUP BY p.id, p.name, v.id
              ORDER BY last_run ASC NULLS FIRST, p.id ASC
              LIMIT ?",
            [$since, $remaining]
        )->getResultArray();
        if ($prompts === []) {
            return $this->finishSkipped($batchId, 'Every active prompt with an approved version has already been asked today.');
        }

        $runIds = [];
        foreach ($prompts as $p) {
            foreach ($runnable as $client) {
                $this->db->table('reach_ai_visibility_runs')->insert([
                    'tenant_id'         => 1,
                    'prompt_version_id' => (int) $p['version_id'],
                    'run_type'          => $trigger === 'manual' ? 'manual_test' : 'scheduled',
                    'ai_provider'       => $client->key(),
                    'ai_model'          => mb_substr($client->model(), 0, 80),
                    'ai_route'          => 'visibility.' . $client->key(),
                    'status'            => 'queued',
                    'queued_at'         => gmdate('Y-m-d H:i:s'),
                    'batch_id'          => $batchId,
                ]);
                $runIds[] = (int) $this->db->insertID();
            }
        }

        $this->db->table('reach_ai_visibility_batches')->where('id', $batchId)->update([
            'prompts_planned' => count($prompts),
            'runs_queued'     => count($runIds),
        ]);

        $executor = $this->executor ?? new VisibilityExecutionService($this->registry, null, $this->db);
        foreach ($runIds as $runId) {
            if ($inline) {
                $executor->executeRun($runId);
            } else {
                ($this->enqueue)($runId);
            }
        }
        $executor->refreshBatch($batchId);

        $row = $this->db->table('reach_ai_visibility_batches')->where('id', $batchId)->get()->getRowArray();

        return $this->decodeBatch((array) $row) + ['run_ids' => $runIds];
    }

    /**
     * Queue one prompt version on every runnable assistant (Run now on a
     * single prompt). Counts toward the daily cap like any other run.
     *
     * @return list<int> run ids
     */
    public function queuePromptVersion(int $versionId, ?int $userId = null): array
    {
        $runnable = $this->registry->runnable();
        if ($runnable === []) {
            throw new \DomainException('No assistant is configured: add a Gemini, OpenAI or Perplexity API key in Settings → AI & Automation.');
        }
        $version = $this->db->table('reach_ai_visibility_prompt_versions')->where('id', $versionId)->get()->getRowArray();
        if (! $version) {
            throw new \InvalidArgumentException('Prompt version not found.');
        }

        $cap  = max(0, (int) ReachConfig::get('visibility.daily_prompt_limit', 25));
        $used = (int) ($this->db->query(
            'SELECT COUNT(DISTINCT prompt_version_id) AS n FROM reach_ai_visibility_runs WHERE tenant_id = 1 AND queued_at >= ? AND prompt_version_id <> ?',
            [self::istMidnightUtc(), $versionId]
        )->getRowArray()['n'] ?? 0);
        if ($used >= $cap) {
            throw new \OverflowException("Today's cap of {$cap} prompts is used. Raise visibility.daily_prompt_limit to ask more.");
        }

        $ids = [];
        foreach ($runnable as $client) {
            $this->db->table('reach_ai_visibility_runs')->insert([
                'tenant_id'         => 1,
                'prompt_version_id' => $versionId,
                'run_type'          => 'manual_test',
                'ai_provider'       => $client->key(),
                'ai_model'          => mb_substr($client->model(), 0, 80),
                'ai_route'          => 'visibility.' . $client->key(),
                'status'            => 'queued',
                'queued_at'         => gmdate('Y-m-d H:i:s'),
            ]);
            $ids[] = (int) $this->db->insertID();
        }
        foreach ($ids as $id) {
            ($this->enqueue)($id);
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    public function batches(int $limit = 20): array
    {
        $rows = $this->db->table('reach_ai_visibility_batches')->orderBy('id', 'DESC')->limit(max(1, min(100, $limit)))->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $counts = [];
        foreach ($this->db->table('reach_ai_visibility_runs')->select('batch_id, status, COUNT(*) AS n', false)
            ->whereIn('batch_id', array_column($rows, 'id'))->groupBy('batch_id, status')->get()->getResultArray() as $c) {
            $counts[(int) $c['batch_id']][(string) $c['status']] = (int) $c['n'];
        }

        return array_map(fn (array $r): array => $this->decodeBatch($r) + ['run_counts' => $counts[(int) $r['id']] ?? new \stdClass()], $rows);
    }

    /**
     * Latest answers with what was found in each.
     *
     * @return list<array<string, mixed>>
     */
    public function answers(int $limit = 50, ?string $provider = null, ?int $promptId = null): array
    {
        $b = $this->db->table('reach_ai_visibility_runs')
            ->select('reach_ai_visibility_runs.id, reach_ai_visibility_runs.ai_provider, reach_ai_visibility_runs.ai_model, reach_ai_visibility_runs.status, reach_ai_visibility_runs.error_message, reach_ai_visibility_runs.queued_at, reach_ai_visibility_runs.completed_at, reach_ai_visibility_runs.batch_id, reach_ai_visibility_prompts.id AS prompt_id, reach_ai_visibility_prompts.name AS prompt_name, reach_ai_visibility_prompts.persona AS market, reach_ai_visibility_prompt_versions.prompt_text')
            ->join('reach_ai_visibility_prompt_versions', 'reach_ai_visibility_prompt_versions.id = reach_ai_visibility_runs.prompt_version_id')
            ->join('reach_ai_visibility_prompts', 'reach_ai_visibility_prompts.id = reach_ai_visibility_prompt_versions.prompt_id')
            ->orderBy('reach_ai_visibility_runs.id', 'DESC')
            ->limit(max(1, min(200, $limit)));
        if ($provider !== null && $provider !== '') {
            $b->where('reach_ai_visibility_runs.ai_provider', $provider);
        }
        if ($promptId !== null) {
            $b->where('reach_ai_visibility_prompts.id', $promptId);
        }
        $runs = $b->get()->getResultArray();
        if ($runs === []) {
            return [];
        }
        $ids  = array_map('intval', array_column($runs, 'id'));
        $obs  = $this->observationsByRun($ids);
        $cits = $this->citationsByRun($ids);
        $text = [];
        foreach ($this->db->table('reach_ai_visibility_responses')->select('run_id, raw_response')->whereIn('run_id', $ids)->get()->getResultArray() as $r) {
            $decoded                  = json_decode((string) $r['raw_response'], true);
            $text[(int) $r['run_id']] = is_array($decoded) ? (string) ($decoded['text'] ?? '') : '';
        }

        $out = [];
        foreach ($runs as $r) {
            $id    = (int) $r['id'];
            $brand = null;
            $comps = [];
            foreach ($obs[$id] ?? [] as $o) {
                if ($o['mention_type'] === 'brand') {
                    $brand = $o;
                } elseif ($o['coverage_state'] === 'mentioned') {
                    $comps[] = ['name' => $o['entity_mentioned'], 'rank' => $o['mention_order'] !== null ? (int) $o['mention_order'] : null, 'in_question' => (bool) ($o['finding']['in_question'] ?? false)];
                }
            }
            $out[] = [
                'run_id'          => $id,
                'prompt_id'       => (int) $r['prompt_id'],
                'prompt_name'     => $r['prompt_name'],
                'market'          => $r['market'],
                'question'        => $r['prompt_text'],
                'provider'        => $r['ai_provider'],
                'model'           => $r['ai_model'],
                'status'          => $r['status'],
                'error'           => $r['error_message'],
                'asked_at'        => $r['completed_at'] ?? $r['queued_at'],
                'brand_mentioned' => $brand !== null ? $brand['coverage_state'] === 'mentioned' : null,
                'brand_rank'      => $brand !== null && $brand['mention_order'] !== null ? (int) $brand['mention_order'] : null,
                'brand_excerpt'   => $brand['evidence_excerpt'] ?? null,
                'brand_cited'     => (bool) ($brand['finding']['brand_cited'] ?? false),
                'competitors'     => $comps,
                'citations'       => $cits[$id] ?? [],
                'answer_text'     => isset($text[$id]) ? mb_substr($text[$id], 0, 6000) : null,
            ];
        }

        return $out;
    }

    /**
     * Share of voice, mention rate and rank per assistant over the last
     * $days IST days, with a per-day series and the prompts where competitors
     * are named but AICOUNTLY is not.
     *
     * Entities named in the question itself ("Tally alternatives",
     * "What is AICOUNTLY?") are left out of that answer's counts, so asking
     * about a brand does not inflate its numbers.
     *
     * @return array<string, mixed>
     */
    public function summary(int $days = 28): array
    {
        $days  = max(1, min(180, $days));
        $ist   = new \DateTimeZone('Asia/Kolkata');
        $from  = (new \DateTimeImmutable(self::istToday(), $ist))->modify('-' . ($days - 1) . ' days');
        $since = $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $runs = $this->db->query(
            "SELECT r.id, r.ai_provider, r.status, r.error_message, COALESCE(r.completed_at, r.queued_at) AS at,
                    p.id AS prompt_id, p.name AS prompt_name, p.persona AS market, v.prompt_text
               FROM reach_ai_visibility_runs r
               JOIN reach_ai_visibility_prompt_versions v ON v.id = r.prompt_version_id
               JOIN reach_ai_visibility_prompts p ON p.id = v.prompt_id
              WHERE r.tenant_id = 1 AND r.status IN ('completed','failed') AND COALESCE(r.completed_at, r.queued_at) >= ?
              ORDER BY r.id",
            [$since]
        )->getResultArray();

        $completed = array_values(array_filter($runs, static fn (array $r): bool => $r['status'] === 'completed'));
        $ids       = array_map('intval', array_column($completed, 'id'));
        $obs       = $ids !== [] ? $this->observationsByRun($ids) : [];
        $cits      = $ids !== [] ? $this->citationsByRun($ids) : [];
        $brandName = VisibilityExecutionService::brandAliases()[0];

        $providers   = [];
        $markets     = [];
        $overall     = $this->emptyAgg();
        $series      = [];
        $gaps        = [];
        $domainCount = [];

        foreach ($completed as $r) {
            $id   = (int) $r['id'];
            $prov = (string) $r['ai_provider'];
            $date = (new \DateTimeImmutable((string) $r['at']))->setTimezone($ist)->format('Y-m-d');
            $market = (string) ($r['market'] ?? '') !== '' ? (string) $r['market'] : 'Unspecified';
            $providers[$prov] ??= $this->emptyAgg();
            $markets[$market] ??= $this->emptyAgg();
            $series[$date][$prov] ??= $this->emptyAgg();

            $entities   = [];   // name => rank|null, mentioned in this answer (not in the question)
            $brandEligible = true;
            foreach ($obs[$id] ?? [] as $o) {
                $inQ = (bool) ($o['finding']['in_question'] ?? false);
                if ($o['mention_type'] === 'brand') {
                    $brandEligible = ! $inQ;
                }
                if ($o['coverage_state'] === 'mentioned' && ! $inQ) {
                    $entities[$o['mention_type'] === 'brand' ? $brandName : (string) $o['entity_mentioned']] = $o['mention_order'] !== null ? (int) $o['mention_order'] : null;
                }
            }
            $brandCited = false;
            $seenDomains = [];
            foreach ($cits[$id] ?? [] as $c) {
                $d = (string) $c['domain'];
                if ($d !== '' && ! isset($seenDomains[$d])) {
                    $seenDomains[$d] = true;
                    $domainCount[$d] = ($domainCount[$d] ?? 0) + 1;
                }
            }
            foreach ($obs[$id] ?? [] as $o) {
                if ($o['mention_type'] === 'brand' && ($o['finding']['brand_cited'] ?? false)) {
                    $brandCited = true;
                }
            }

            foreach ([&$overall, &$providers[$prov], &$markets[$market], &$series[$date][$prov]] as &$agg) {
                $this->addAnswer($agg, $entities, $brandName, $brandEligible, $brandCited);
            }
            unset($agg);

            $competitorsHere = array_values(array_filter(array_keys($entities), static fn (string $n): bool => $n !== $brandName));
            if ($brandEligible && ! isset($entities[$brandName]) && $competitorsHere !== []) {
                $pid = (int) $r['prompt_id'];
                $gaps[$pid] ??= ['prompt_id' => $pid, 'prompt_name' => $r['prompt_name'], 'market' => $r['market'], 'question' => $r['prompt_text'], 'answers_without_us' => 0, 'providers' => [], 'competitors' => []];
                $gaps[$pid]['answers_without_us']++;
                $gaps[$pid]['providers'][$prov] = true;
                foreach ($competitorsHere as $n) {
                    $gaps[$pid]['competitors'][$n] = ($gaps[$pid]['competitors'][$n] ?? 0) + 1;
                }
            }
        }

        $failed = [];
        foreach ($runs as $r) {
            if ($r['status'] === 'failed') {
                $p = (string) $r['ai_provider'];
                $failed[$p] ??= ['provider' => $p, 'failed_runs' => 0, 'last_error' => null];
                $failed[$p]['failed_runs']++;
                $failed[$p]['last_error'] = $r['error_message'];
            }
        }

        $topCompetitors = array_slice(array_keys($this->finalise($overall)['entities']), 0, 6);
        $topCompetitors = array_values(array_filter($topCompetitors, static fn (string $n): bool => $n !== $brandName));

        $seriesOut = [];
        ksort($series);
        foreach ($series as $date => $byProv) {
            foreach ($byProv as $prov => $agg) {
                $f           = $this->finalise($agg);
                $seriesOut[] = [
                    'date'           => $date,
                    'provider'       => $prov,
                    'answers'        => $f['answers'],
                    'brand_mentions' => $f['brand_mentions'],
                    'brand_rate'     => $f['brand_mention_rate'],
                    'brand_sov'      => $f['entities'][$brandName]['share_of_voice'] ?? 0.0,
                    'sov'            => array_map(static fn (array $e): float => $e['share_of_voice'], array_intersect_key($f['entities'], array_flip(array_merge([$brandName], $topCompetitors)))),
                ];
            }
        }

        usort($gaps, static fn (array $a, array $b): int => $b['answers_without_us'] <=> $a['answers_without_us']);
        $gaps = array_map(static function (array $g): array {
            arsort($g['competitors']);
            $g['providers']   = array_keys($g['providers']);
            $g['competitors'] = array_map(static fn ($n, $c) => ['name' => $n, 'answers' => $c], array_keys($g['competitors']), $g['competitors']);
            return $g;
        }, array_slice($gaps, 0, 15));

        arsort($domainCount);
        $domainCount = array_slice($domainCount, 0, 15, true);
        $topDomains  = array_map(static fn ($d, $n) => ['domain' => (string) $d, 'answers' => $n], array_keys($domainCount), array_values($domainCount));

        $byProvider = [];
        foreach ($providers as $prov => $agg) {
            $byProvider[] = ['provider' => $prov] + $this->finalise($agg);
        }

        $byMarket = [];
        foreach ($markets as $market => $agg) {
            $f          = $this->finalise($agg);
            $byMarket[] = ['market' => $market, 'answers' => $f['answers'], 'brand_mentions' => $f['brand_mentions'], 'brand_mention_rate' => $f['brand_mention_rate'], 'top_entities' => array_slice(array_keys($f['entities']), 0, 5)];
        }

        $batches = $this->batches(1);

        return [
            'period'         => ['from' => $from->format('Y-m-d'), 'to' => self::istToday(), 'days' => $days],
            'brand'          => $brandName,
            'providers'      => $this->providers(),
            'overall'        => $this->finalise($overall),
            'by_provider'    => $byProvider,
            'by_market'      => $byMarket,
            'top_competitors' => $topCompetitors,
            'series'         => $seriesOut,
            'gaps'           => $gaps,
            'top_cited_domains' => $topDomains,
            'failures'       => array_values($failed),
            'latest_batch'   => $batches[0] ?? null,
            'sample_note'    => self::SAMPLE_NOTE,
        ];
    }

    /**
     * What was asked, of which assistant, and who each answer named — per
     * prompt and assistant over the last $days IST days. Counted exactly like
     * summary(): an answer to a question that itself names AICOUNTLY is not
     * counted for the brand, and entities named in the question are left out
     * of that answer's competitor list.
     *
     * @return array<string, mixed>
     */
    public function byPrompt(int $days = 28): array
    {
        $days  = max(1, min(180, $days));
        $ist   = new \DateTimeZone('Asia/Kolkata');
        $from  = (new \DateTimeImmutable(self::istToday(), $ist))->modify('-' . ($days - 1) . ' days');
        $since = $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $runs = $this->db->query(
            "SELECT r.id, r.ai_provider, r.ai_model, r.status, r.error_message, COALESCE(r.completed_at, r.queued_at) AS at,
                    p.id AS prompt_id, p.name AS prompt_name, p.persona AS market, v.prompt_text
               FROM reach_ai_visibility_runs r
               JOIN reach_ai_visibility_prompt_versions v ON v.id = r.prompt_version_id
               JOIN reach_ai_visibility_prompts p ON p.id = v.prompt_id
              WHERE r.tenant_id = 1 AND r.status IN ('completed','failed') AND COALESCE(r.completed_at, r.queued_at) >= ?
              ORDER BY r.id",
            [$since]
        )->getResultArray();

        $ids       = array_map('intval', array_column(array_filter($runs, static fn (array $r): bool => $r['status'] === 'completed'), 'id'));
        $obs       = $ids !== [] ? $this->observationsByRun($ids) : [];
        $brandName = VisibilityExecutionService::brandAliases()[0];
        $atIst     = static fn (string $ts): string => (new \DateTimeImmutable($ts))->setTimezone($ist)->format('c');

        $prompts = [];
        $totals  = ['answers' => 0, 'counted' => 0, 'brand_mentions' => 0, 'failed' => 0];
        foreach ($runs as $r) {
            $pid  = (int) $r['prompt_id'];
            $prov = (string) $r['ai_provider'];
            $prompts[$pid] ??= ['prompt_id' => $pid, 'name' => $r['prompt_name'], 'market' => $r['market'], 'question' => $r['prompt_text'],
                'answers' => 0, 'counted' => 0, 'brand_mentions' => 0, 'failed' => 0, 'competitors' => [], 'providers' => [], 'last_asked_at' => null];
            $p = &$prompts[$pid];
            $p['providers'][$prov] ??= ['provider' => $prov, 'model' => $r['ai_model'], 'answers' => 0, 'counted' => 0, 'brand_mentions' => 0,
                'failed' => 0, 'last_error' => null, 'competitors' => [], 'last_asked_at' => null, 'last_brand_excerpt' => null];
            $pp = &$p['providers'][$prov];
            $at = $atIst((string) $r['at']);

            if ($r['status'] === 'failed') {
                $pp['failed']++;
                $p['failed']++;
                $totals['failed']++;
                $pp['last_error'] = $r['error_message'] !== null ? mb_substr((string) $r['error_message'], 0, 200) : null;
                unset($pp, $p);
                continue;
            }

            $eligible = true;
            $named    = false;
            $excerpt  = null;
            $comps    = [];
            foreach ($obs[(int) $r['id']] ?? [] as $o) {
                $inQ = (bool) ($o['finding']['in_question'] ?? false);
                if ($o['mention_type'] === 'brand') {
                    $eligible = ! $inQ;
                    $named    = $o['coverage_state'] === 'mentioned' && ! $inQ;
                    $excerpt  = $named ? $o['evidence_excerpt'] : null;
                } elseif ($o['coverage_state'] === 'mentioned' && ! $inQ) {
                    $comps[(string) $o['entity_mentioned']] = true;
                }
            }

            foreach ([&$p, &$pp, &$totals] as &$agg) {
                $agg['answers']++;
                $agg['counted'] += $eligible ? 1 : 0;
                $agg['brand_mentions'] += $named ? 1 : 0;
            }
            unset($agg);
            foreach (array_keys($comps) as $n) {
                $p['competitors'][$n]  = ($p['competitors'][$n] ?? 0) + 1;
                $pp['competitors'][$n] = ($pp['competitors'][$n] ?? 0) + 1;
            }
            if ($pp['last_asked_at'] === null || $at > $pp['last_asked_at']) {
                $pp['last_asked_at'] = $at;
                $pp['model']         = $r['ai_model'];
                if ($excerpt !== null) {
                    $pp['last_brand_excerpt'] = mb_substr((string) $excerpt, 0, 280);
                }
            }
            if ($p['last_asked_at'] === null || $at > $p['last_asked_at']) {
                $p['last_asked_at'] = $at;
            }
            unset($pp, $p);
        }

        $byCount = static function (array $counts): array {
            arsort($counts);

            return array_map(static fn ($n, $c) => ['name' => (string) $n, 'answers' => $c], array_keys($counts), array_values($counts));
        };
        $out = [];
        foreach ($prompts as $p) {
            $p['competitors'] = $byCount($p['competitors']);
            $p['providers']   = array_values(array_map(static function (array $pp) use ($byCount): array {
                $pp['competitors'] = $byCount($pp['competitors']);

                return $pp;
            }, $p['providers']));
            $out[] = $p;
        }
        usort($out, static fn (array $a, array $b): int => [$b['brand_mentions'], $b['answers']] <=> [$a['brand_mentions'], $a['answers']] ?: strcmp((string) $a['name'], (string) $b['name']));

        return [
            'period'      => ['from' => $from->format('Y-m-d'), 'to' => self::istToday(), 'days' => $days],
            'brand'       => $brandName,
            'totals'      => $totals,
            'prompts'     => $out,
            'providers'   => $this->providers(),
            'sample_note' => self::SAMPLE_NOTE,
        ];
    }

    /**
     * A compact block for the daily report / insights digest.
     *
     * @return array<string, mixed>
     */
    public function compactSummary(int $days = 7): array
    {
        $s = $this->summary($days);

        return [
            'period'            => $s['period'],
            'answers'           => $s['overall']['answers'],
            'brand_mention_rate' => $s['overall']['brand_mention_rate'],
            'brand_avg_rank'    => $s['overall']['brand_avg_rank'],
            'brand_cited_answers' => $s['overall']['brand_cited'],
            'by_provider'       => array_map(static fn (array $p): array => [
                'provider'           => $p['provider'],
                'answers'            => $p['answers'],
                'brand_mention_rate' => $p['brand_mention_rate'],
                'brand_avg_rank'     => $p['brand_avg_rank'],
                'top_competitors'    => array_slice(array_map(static fn ($n, $e) => ['name' => $n, 'mention_rate' => $e['mention_rate'], 'share_of_voice' => $e['share_of_voice']], array_keys($p['entities']), $p['entities']), 0, 5),
            ], $s['by_provider']),
            'by_market'         => array_map(static fn (array $m): array => ['market' => $m['market'], 'answers' => $m['answers'], 'brand_mention_rate' => $m['brand_mention_rate'], 'top_entities' => $m['top_entities']], $s['by_market']),
            'gaps'              => array_slice(array_map(static fn (array $g): array => ['question' => $g['question'], 'market' => $g['market'], 'answers_without_us' => $g['answers_without_us'], 'competitors' => array_slice(array_column($g['competitors'], 'name'), 0, 4)], $s['gaps']), 0, 8),
            'top_cited_domains' => array_slice($s['top_cited_domains'], 0, 10),
            'skipped_providers' => array_values(array_map(static fn (array $p): array => ['provider' => $p['key'], 'reason' => $p['reason']], array_filter($s['providers'], static fn (array $p): bool => ! $p['will_run']))),
            'failures'          => $s['failures'],
            'sample_note'       => $s['sample_note'],
        ];
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function emptyAgg(): array
    {
        return ['answers' => 0, 'brand_eligible' => 0, 'brand_mentions' => 0, 'brand_ranks' => [], 'brand_cited' => 0, 'mentions' => [], 'ranks' => [], 'total_mentions' => 0];
    }

    /**
     * @param array<string, mixed>   $agg
     * @param array<string, ?int>    $entities
     */
    private function addAnswer(array &$agg, array $entities, string $brandName, bool $brandEligible, bool $brandCited): void
    {
        $agg['answers']++;
        if ($brandEligible) {
            $agg['brand_eligible']++;
            if (array_key_exists($brandName, $entities)) {
                $agg['brand_mentions']++;
                if ($entities[$brandName] !== null) {
                    $agg['brand_ranks'][] = $entities[$brandName];
                }
            }
        }
        if ($brandCited) {
            $agg['brand_cited']++;
        }
        foreach ($entities as $name => $rank) {
            $agg['mentions'][$name] = ($agg['mentions'][$name] ?? 0) + 1;
            if ($rank !== null) {
                $agg['ranks'][$name][] = $rank;
            }
            $agg['total_mentions']++;
        }
    }

    /**
     * @param array<string, mixed> $agg
     * @return array<string, mixed>
     */
    private function finalise(array $agg): array
    {
        $entities = [];
        arsort($agg['mentions']);
        foreach ($agg['mentions'] as $name => $n) {
            $ranks           = $agg['ranks'][$name] ?? [];
            $entities[$name] = [
                'mentions'       => $n,
                'mention_rate'   => $agg['answers'] > 0 ? round($n / $agg['answers'], 4) : 0.0,
                'share_of_voice' => $agg['total_mentions'] > 0 ? round($n / $agg['total_mentions'], 4) : 0.0,
                'avg_rank'       => $ranks !== [] ? round(array_sum($ranks) / count($ranks), 2) : null,
            ];
        }
        $ranks = $agg['brand_ranks'];

        return [
            'answers'            => $agg['answers'],
            'brand_answers_counted' => $agg['brand_eligible'],
            'brand_mentions'     => $agg['brand_mentions'],
            'brand_mention_rate' => $agg['brand_eligible'] > 0 ? round($agg['brand_mentions'] / $agg['brand_eligible'], 4) : 0.0,
            'brand_avg_rank'     => $ranks !== [] ? round(array_sum($ranks) / count($ranks), 2) : null,
            'brand_top3'         => count(array_filter($ranks, static fn (int $r): bool => $r <= 3)),
            'brand_cited'        => $agg['brand_cited'],
            'entities'           => $entities,
        ];
    }

    /**
     * @param list<int> $runIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function observationsByRun(array $runIds): array
    {
        $out = [];
        foreach (array_chunk($runIds, 500) as $chunk) {
            foreach ($this->db->table('reach_ai_visibility_observations')
                ->select('run_id, entity_mentioned, mention_type, mention_order, coverage_state, evidence_excerpt, parser_finding')
                ->whereIn('run_id', $chunk)->orderBy('id')->get()->getResultArray() as $o) {
                $finding      = is_array($o['parser_finding']) ? $o['parser_finding'] : json_decode((string) $o['parser_finding'], true);
                $o['finding'] = is_array($finding) ? $finding : [];
                unset($o['parser_finding']);
                $out[(int) $o['run_id']][] = $o;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $runIds
     * @return array<int, list<array{url: string, domain: string, type: string}>>
     */
    private function citationsByRun(array $runIds): array
    {
        $out = [];
        foreach (array_chunk($runIds, 500) as $chunk) {
            foreach ($this->db->table('reach_ai_visibility_citations')
                ->select('reach_ai_visibility_responses.run_id, reach_ai_visibility_citations.cited_url, reach_ai_visibility_citations.cited_domain, reach_ai_visibility_citations.citation_type')
                ->join('reach_ai_visibility_responses', 'reach_ai_visibility_responses.id = reach_ai_visibility_citations.response_id')
                ->whereIn('reach_ai_visibility_responses.run_id', $chunk)
                ->orderBy('reach_ai_visibility_citations.citation_order')
                ->get()->getResultArray() as $c) {
                $out[(int) $c['run_id']][] = ['url' => (string) $c['cited_url'], 'domain' => (string) $c['cited_domain'], 'type' => (string) $c['citation_type']];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function finishSkipped(int $batchId, string $note): array
    {
        $this->db->table('reach_ai_visibility_batches')->where('id', $batchId)->update([
            'status'      => 'skipped',
            'note'        => $note,
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $row = $this->db->table('reach_ai_visibility_batches')->where('id', $batchId)->get()->getRowArray();

        return $this->decodeBatch((array) $row) + ['run_ids' => []];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeBatch(array $row): array
    {
        $p = $row['providers_json'] ?? '[]';
        $row['providers'] = is_array($p) ? $p : (json_decode((string) $p, true) ?: []);
        unset($row['providers_json']);
        foreach (['id', 'tenant_id', 'prompts_planned', 'runs_queued'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = (int) $row[$k];
            }
        }

        return $row;
    }
}
