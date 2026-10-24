<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Intelligence\Visibility\AssistantException;
use App\Libraries\Intelligence\Visibility\AssistantRegistry;
use App\Libraries\Intelligence\Visibility\MentionParser;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;

/**
 * Executes one AI-visibility run: puts the run's prompt version to the run's
 * assistant, stores the answer verbatim, and records what MentionParser found
 * — an Aicountly observation on every answer (mentioned or not), one per
 * competitor mentioned, and every cited URL.
 *
 * A provider error marks the run failed with the (redacted) reason; nothing
 * is retried automatically, so a bad key never multiplies the bill.
 */
class VisibilityExecutionService
{
    private BaseConnection $db;
    private AssistantRegistry $registry;
    private MentionParser $parser;

    public function __construct(?AssistantRegistry $registry = null, ?MentionParser $parser = null, ?BaseConnection $db = null)
    {
        $this->db       = $db ?? \Config\Database::connect();
        $this->registry = $registry ?? new AssistantRegistry();
        $this->parser   = $parser ?? new MentionParser();
    }

    /**
     * @return array{run_id: int, status: string, brand_mentioned?: bool, brand_rank?: ?int, competitors?: int, error?: string, skipped?: bool}
     */
    public function executeRun(int $runId): array
    {
        $run = $this->db->table('reach_ai_visibility_runs')->where('id', $runId)->get()->getRowArray();
        if (! $run) {
            throw new \RuntimeException("Visibility run {$runId} not found");
        }
        if (in_array($run['status'], ['completed', 'failed', 'cancelled'], true)) {
            return ['run_id' => $runId, 'status' => (string) $run['status'], 'skipped' => true];
        }

        $version = $this->db->table('reach_ai_visibility_prompt_versions')->where('id', (int) $run['prompt_version_id'])->get()->getRowArray();
        if (! $version) {
            return $this->fail($run, 'Prompt version not found.');
        }

        $client = $this->registry->get((string) $run['ai_provider']);
        $reason = $client === null ? 'Unknown assistant ' . $run['ai_provider'] : $client->unavailableReason();
        if ($reason !== null) {
            return $this->fail($run, $reason);
        }

        $this->db->table('reach_ai_visibility_runs')->where('id', $runId)->update([
            'status'     => 'running',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'ai_model'   => $client->model(),
        ]);

        $question = (string) $version['prompt_text'];
        try {
            $answer = $client->ask($question);
        } catch (AssistantException $e) {
            return $this->fail($run, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[Visibility] run ' . $runId . ': ' . $e->getMessage());
            return $this->fail($run, 'Unexpected error asking ' . $client->label() . '.');
        }

        $brandAliases = self::brandAliases();
        $parsed       = $this->parser->parse(
            $answer->text,
            $brandAliases,
            self::trackedCompetitors($this->db, (int) $run['tenant_id']),
            $answer->citations,
            $question,
            self::brandDomains($brandAliases),
        );

        $now = gmdate('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('reach_ai_visibility_responses')->insert([
            'run_id'               => $runId,
            'raw_response'         => json_encode(['text' => $answer->text, 'citations' => $answer->citations, 'model' => $answer->model], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_timestamp'   => $now,
            'parser_version'       => MentionParser::VERSION,
            'parse_status'         => 'parsed',
            'tokens_used'          => $answer->tokensUsed,
            'retention_expires_at' => gmdate('Y-m-d H:i:s', strtotime('+400 days')),
        ]);
        $responseId = (int) $this->db->insertID();

        $brand = $parsed['brand'];
        $this->db->table('reach_ai_visibility_observations')->insert([
            'response_id'      => $responseId,
            'run_id'           => $runId,
            'entity_mentioned' => mb_substr($brand['name'], 0, 120),
            'mention_type'     => 'brand',
            'mention_order'    => $brand['rank'],
            'coverage_state'   => $brand['mentioned'] ? 'mentioned' : 'not_mentioned',
            'evidence_excerpt' => $brand['excerpt'],
            'parser_finding'   => json_encode([
                'matched'       => $brand['matched'],
                'in_question'   => $brand['in_question'],
                'list_detected' => $parsed['list_detected'],
                'brand_cited'   => $parsed['brand_cited'],
                'ranked'        => $parsed['ranked'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at'       => $now,
        ]);

        foreach ($parsed['competitors'] as $c) {
            $this->db->table('reach_ai_visibility_observations')->insert([
                'response_id'      => $responseId,
                'run_id'           => $runId,
                'entity_mentioned' => mb_substr($c['name'], 0, 120),
                'mention_type'     => 'competitor',
                'mention_order'    => $c['rank'],
                'coverage_state'   => 'mentioned',
                'evidence_excerpt' => $c['excerpt'],
                'parser_finding'   => json_encode(['competitor_id' => $c['id'], 'matched' => $c['matched'], 'in_question' => $c['in_question']], JSON_UNESCAPED_UNICODE),
                'created_at'       => $now,
            ]);
        }

        foreach ($parsed['citations'] as $c) {
            $this->db->table('reach_ai_visibility_citations')->insert([
                'response_id'    => $responseId,
                'cited_url'      => $c['url'],
                'cited_domain'   => mb_substr($c['domain'], 0, 120),
                'citation_type'  => $c['source'] === 'provider' ? 'source' : 'link',
                'citation_order' => $c['order'],
                'created_at'     => $now,
            ]);
        }

        $this->db->table('reach_ai_visibility_runs')->where('id', $runId)->update([
            'status'       => 'completed',
            'completed_at' => $now,
            'tokens_used'  => $answer->tokensUsed,
            'ai_model'     => mb_substr($answer->model, 0, 80),
        ]);
        $this->db->transComplete();

        $this->refreshBatch($run['batch_id'] ?? null);

        return [
            'run_id'          => $runId,
            'status'          => 'completed',
            'brand_mentioned' => $brand['mentioned'],
            'brand_rank'      => $brand['rank'],
            'competitors'     => count($parsed['competitors']),
        ];
    }

    /**
     * Mark a batch finished once none of its runs is still queued/running.
     */
    public function refreshBatch(mixed $batchId): void
    {
        if ($batchId === null || $batchId === '') {
            return;
        }
        $open = $this->db->table('reach_ai_visibility_runs')
            ->where('batch_id', (int) $batchId)
            ->whereIn('status', ['queued', 'running'])
            ->countAllResults();
        if ($open === 0) {
            $this->db->table('reach_ai_visibility_batches')
                ->where('id', (int) $batchId)
                ->where('status', 'running')
                ->update(['status' => 'completed', 'finished_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    /** @return list<string> */
    public static function brandAliases(): array
    {
        $raw = (string) ReachConfig::get('visibility.brand_aliases', 'Aicountly,aicountly.com');
        $out = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $a): bool => $a !== ''));

        return $out !== [] ? $out : ['Aicountly', 'aicountly.com'];
    }

    /**
     * @param list<string> $aliases
     * @return list<string>
     */
    public static function brandDomains(array $aliases): array
    {
        $domains = array_values(array_filter($aliases, static fn (string $a): bool => preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $a) === 1));

        return $domains !== [] ? array_map('strtolower', $domains) : ['aicountly.com'];
    }

    /**
     * Monitored competitors with their aliases (and website domain).
     *
     * @return list<array{id: int, name: string, aliases: list<string>, domain: ?string}>
     */
    public static function trackedCompetitors(BaseConnection $db, int $tenantId = 1): array
    {
        $rows = $db->table('reach_competitors')
            ->select('id, name, website_domain')
            ->where('tenant_id', $tenantId)
            ->where('monitoring_enabled', true)
            ->where('monitoring_status', 'active')
            ->orderBy('id')
            ->get()->getResultArray();
        if ($rows === []) {
            return [];
        }

        $aliases = [];
        foreach ($db->table('reach_competitor_aliases')->select('competitor_id, alias_value')->whereIn('competitor_id', array_column($rows, 'id'))->get()->getResultArray() as $a) {
            $aliases[(int) $a['competitor_id']][] = (string) $a['alias_value'];
        }

        $out = [];
        foreach ($rows as $r) {
            $list   = $aliases[(int) $r['id']] ?? [];
            $domain = trim((string) ($r['website_domain'] ?? ''));
            if ($domain !== '') {
                $list[] = $domain;
            }
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'aliases' => $list, 'domain' => $domain !== '' ? strtolower($domain) : null];
        }

        return $out;
    }

    /** @param array<string, mixed> $run */
    private function fail(array $run, string $reason): array
    {
        $this->db->table('reach_ai_visibility_runs')->where('id', (int) $run['id'])->update([
            'status'        => 'failed',
            'error_message' => mb_substr($reason, 0, 1000),
            'completed_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        $this->refreshBatch($run['batch_id'] ?? null);

        return ['run_id' => (int) $run['id'], 'status' => 'failed', 'error' => $reason];
    }
}
