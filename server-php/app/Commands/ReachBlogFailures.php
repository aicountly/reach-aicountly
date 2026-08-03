<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:blog-failures` — show why GENERATE_DRAFT / AI requests failed.
 */
class ReachBlogFailures extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:blog-failures';
    protected $description = 'Show recent failed blog work blocks and AI generation errors.';
    protected $usage       = 'reach:blog-failures [--limit=10]';

    public function run(array $params): int
    {
        $limit = max(1, (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 10)));
        $db    = Database::connect();

        try {
            $failedBlocks = [];
            if ($db->tableExists('reach_work_blocks')) {
                $rows = $db->table('reach_work_blocks')
                    ->select('id, content_item_id, block_type, eligibility_status, failure_classification, output_json, updated_at')
                    ->where('eligibility_status', 'failed')
                    ->whereIn('block_type', ['GENERATE_DRAFT', 'GENERATE_BRIEF', 'GENERATE_OUTLINE', 'FACT_VERIFY', 'CROSS_REVIEW'])
                    ->orderBy('id', 'DESC')
                    ->limit($limit)
                    ->get()
                    ->getResultArray();
                foreach ($rows as $row) {
                    $out = is_string($row['output_json'] ?? null)
                        ? json_decode($row['output_json'], true)
                        : ($row['output_json'] ?? []);
                    $failedBlocks[] = [
                        'work_block_id'          => (int) $row['id'],
                        'content_item_id'        => (int) ($row['content_item_id'] ?? 0),
                        'block_type'             => $row['block_type'],
                        'failure_classification' => $row['failure_classification'],
                        'reason'                 => $out['reason'] ?? null,
                        'updated_at'             => $row['updated_at'],
                    ];
                }
            }

            $aiRequests = [];
            if ($db->tableExists('reach_ai_generation_requests')) {
                $rows = $db->table('reach_ai_generation_requests')
                    ->select('id, task_type, content_type, content_item_id, status, created_at, completed_at')
                    ->where('status', 'failed')
                    ->orderBy('id', 'DESC')
                    ->limit($limit)
                    ->get()
                    ->getResultArray();
                foreach ($rows as $row) {
                    $run = null;
                    if ($db->tableExists('reach_ai_generation_runs')) {
                        $run = $db->table('reach_ai_generation_runs')
                            ->select('status, error_category, redacted_error_message, duration_ms')
                            ->where('generation_request_id', (int) $row['id'])
                            ->orderBy('id', 'DESC')
                            ->limit(1)
                            ->get()
                            ->getRowArray();
                    }
                    $aiRequests[] = [
                        'request_id'      => (int) $row['id'],
                        'content_item_id' => (int) ($row['content_item_id'] ?? 0),
                        'task_type'       => $row['task_type'],
                        'content_type'    => $row['content_type'],
                        'status'          => $row['status'],
                        'created_at'      => $row['created_at'],
                        'last_run'        => $run,
                    ];
                }
            }

            $aiConfig = [
                'openai_key_set'     => $this->envSet('AI_OPENAI_API_KEY'),
                'gemini_key_set'     => $this->envSet('AI_GEMINI_API_KEY'),
                'perplexity_key_set' => $this->envSet('AI_PERPLEXITY_API_KEY'),
                'routes'             => [],
            ];
            if ($db->tableExists('reach_ai_model_routes')) {
                $routes = $db->table('reach_ai_model_routes r')
                    ->select('r.task_type, r.content_type, r.priority, r.is_enabled, m.model_key, p.provider_key, p.is_enabled AS provider_enabled')
                    ->join('reach_ai_models m', 'm.id = r.model_id', 'left')
                    ->join('reach_ai_providers p', 'p.id = m.provider_id', 'left')
                    ->whereIn('r.task_type', ['draft_generation', 'brief_generation', 'editorial_review'])
                    ->orderBy('r.task_type', 'ASC')
                    ->orderBy('r.priority', 'DESC')
                    ->get()
                    ->getResultArray();
                $aiConfig['routes'] = $routes;
            }

            $items = [];
            if ($db->tableExists('reach_content_items')) {
                $items = $db->table('reach_content_items')
                    ->select('id, title, workflow_status, current_version_id')
                    ->where('content_type', 'blog')
                    ->where('deleted_at IS NULL', null, false)
                    ->orderBy('id', 'ASC')
                    ->get()
                    ->getResultArray();
            }

            CLI::write(json_encode([
                'event'         => 'blog_failures',
                'ts'            => gmdate('c'),
                'ai_config'     => $aiConfig,
                'content_items' => $items,
                'failed_blocks' => $failedBlocks,
                'failed_ai'     => $aiRequests,
                'retry'         => [
                    'php spark reach:blog-advance --dispatch',
                    'php spark reach:work --queue blog,publishing,community,default --limit 20',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }
    }

    private function envSet(string $key): bool
    {
        $v = $_ENV[$key] ?? getenv($key) ?: '';
        return is_string($v) && trim($v) !== '';
    }
}
