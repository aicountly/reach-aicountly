<?php

namespace App\Commands;

use App\Libraries\Ai\Providers\GeminiProvider;
use App\Libraries\Ai\Providers\OpenAiProvider;
use App\Libraries\Ai\Providers\PerplexityProvider;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:ai-seed-catalog`
 *
 * Idempotently seeds reach_ai_providers / reach_ai_models / reach_ai_model_routes
 * so blog automation can route draft_generation / brief_generation / editorial_review.
 *
 * Env keys must already be set (AI_OPENAI_API_KEY, etc.). Secrets are never written to DB.
 */
class ReachAiSeedCatalog extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:ai-seed-catalog';
    protected $description = 'Seed AI provider/model catalog and blog generation routes.';
    protected $usage       = 'reach:ai-seed-catalog [--force-enable]';

    public function run(array $params): int
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $created = [
            'providers' => [],
            'models'    => [],
            'routes'    => [],
        ];

        try {
            $openaiId = $this->upsertProvider($db, [
                'provider_key'         => 'openai',
                'display_name'         => 'OpenAI',
                'adapter_class'        => OpenAiProvider::class,
                'secret_env_reference' => 'AI_OPENAI_API_KEY',
                'configured'           => $this->envSet('AI_OPENAI_API_KEY'),
            ], $now, $created);

            $geminiId = $this->upsertProvider($db, [
                'provider_key'         => 'gemini',
                'display_name'         => 'Google Gemini',
                'adapter_class'        => GeminiProvider::class,
                'secret_env_reference' => 'AI_GEMINI_API_KEY',
                'configured'           => $this->envSet('AI_GEMINI_API_KEY'),
            ], $now, $created);

            $this->upsertProvider($db, [
                'provider_key'         => 'perplexity',
                'display_name'         => 'Perplexity',
                'adapter_class'        => PerplexityProvider::class,
                'secret_env_reference' => 'AI_PERPLEXITY_API_KEY',
                'configured'           => $this->envSet('AI_PERPLEXITY_API_KEY'),
            ], $now, $created);

            $gptMini = $this->upsertModel($db, $openaiId, [
                'model_key'    => 'gpt-4o-mini',
                'display_name' => 'GPT-4o mini',
                'model_family' => 'gpt-4o',
                'context_limit'=> 128000,
            ], $now, $created);

            $geminiFlash = $this->upsertModel($db, $geminiId, [
                'model_key'    => 'gemini-2.0-flash',
                'display_name' => 'Gemini 2.0 Flash',
                'model_family' => 'gemini',
                'context_limit'=> 1000000,
            ], $now, $created);

            // Prefer OpenAI for drafts when configured; else Gemini.
            $primaryDraftModel = $this->envSet('AI_OPENAI_API_KEY') ? $gptMini : $geminiFlash;
            $crossReviewModel  = ($primaryDraftModel === $gptMini) ? $geminiFlash : $gptMini;

            $routeSpecs = [
                ['draft_generation', 'blog_post', $primaryDraftModel, 100],
                ['draft_generation', null,        $primaryDraftModel, 50],
                ['brief_generation', 'generic',   $primaryDraftModel, 100],
                ['brief_generation', null,        $primaryDraftModel, 50],
                ['editorial_review', 'blog_post', $crossReviewModel, 100],
                ['editorial_review', null,        $crossReviewModel, 50],
            ];

            foreach ($routeSpecs as [$task, $contentType, $modelId, $priority]) {
                if ($modelId <= 0) {
                    continue;
                }
                $this->upsertRoute($db, $task, $contentType, $modelId, $priority, $now, $created);
            }

            CLI::write(json_encode([
                'event'   => 'ai_seed_catalog.completed',
                'ts'      => gmdate('c'),
                'created' => $created,
                'hint'    => 'Retry draft: php spark reach:blog-advance --id=1 --dispatch  OR recreate GENERATE_DRAFT work block',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            return 1;
        }
    }

    private function envSet(string $key): bool
    {
        $v = env($key);
        return is_string($v) && trim($v) !== '';
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,list<string>> $created
     */
    private function upsertProvider($db, array $spec, string $now, array &$created): int
    {
        $row = $db->table('reach_ai_providers')
            ->where('provider_key', $spec['provider_key'])
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();

        $data = [
            'display_name'               => $spec['display_name'],
            'adapter_class'              => $spec['adapter_class'],
            'secret_env_reference'       => $spec['secret_env_reference'],
            'status'                     => ! empty($spec['configured']) ? 'enabled' : 'draft',
            'supports_structured_output' => true,
            'supports_health_check'      => true,
            'configuration_status'       => ! empty($spec['configured']) ? 'configured' : 'unconfigured',
            'updated_at'                 => $now,
        ];

        if ($row) {
            $db->table('reach_ai_providers')->where('id', $row['id'])->update($data);
            return (int) $row['id'];
        }

        $db->table('reach_ai_providers')->insert(array_merge($data, [
            'provider_key'       => $spec['provider_key'],
            'created_actor_type' => 'system',
            'created_at'         => $now,
        ]));
        $id = (int) $db->insertID();
        $created['providers'][] = $spec['provider_key'];
        return $id;
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,list<string>> $created
     */
    private function upsertModel($db, int $providerId, array $spec, string $now, array &$created): int
    {
        if ($providerId <= 0) {
            return 0;
        }

        $row = $db->table('reach_ai_models')
            ->where('provider_id', $providerId)
            ->where('model_key', $spec['model_key'])
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();

        $data = [
            'display_name'               => $spec['display_name'],
            'model_family'               => $spec['model_family'],
            'context_limit'              => (int) ($spec['context_limit'] ?? 8192),
            'maximum_output_tokens'      => 8192,
            'supports_structured_output' => true,
            'enabled'                    => true,
            'approval_status'            => 'approved',
            'updated_at'                 => $now,
        ];

        if ($row) {
            $db->table('reach_ai_models')->where('id', $row['id'])->update($data);
            return (int) $row['id'];
        }

        $db->table('reach_ai_models')->insert(array_merge($data, [
            'provider_id' => $providerId,
            'model_key'   => $spec['model_key'],
            'created_at'  => $now,
        ]));
        $id = (int) $db->insertID();
        $created['models'][] = $spec['model_key'];
        return $id;
    }

    /**
     * @param array<string,list<string>> $created
     */
    private function upsertRoute(
        $db,
        string $taskType,
        ?string $contentType,
        int $modelId,
        int $priority,
        string $now,
        array &$created,
    ): void {
        $builder = $db->table('reach_ai_model_routes')
            ->where('task_type', $taskType)
            ->where('deleted_at IS NULL', null, false)
            ->where('primary_model_id', $modelId);

        if ($contentType === null) {
            $builder->where('content_type IS NULL', null, false);
        } else {
            $builder->where('content_type', $contentType);
        }

        $row = $builder->get()->getRowArray();
        if ($row) {
            $db->table('reach_ai_model_routes')->where('id', $row['id'])->update([
                'enabled'  => true,
                'priority' => $priority,
                'updated_at' => $now,
            ]);
            return;
        }

        $db->table('reach_ai_model_routes')->insert([
            'task_type'        => $taskType,
            'content_type'     => $contentType,
            'primary_model_id' => $modelId,
            'enabled'          => true,
            'priority'         => $priority,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $created['routes'][] = $taskType . '/' . ($contentType ?? '*');
    }
}
