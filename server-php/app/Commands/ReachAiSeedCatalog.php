<?php

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Ai\Providers\GeminiProvider;
use App\Libraries\Ai\Providers\OpenAiProvider;
use App\Libraries\Ai\Providers\PerplexityProvider;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;
use App\Libraries\Database\SchemaGuard;

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
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:ai-seed-catalog';
    protected $description = 'Seed AI provider/model catalog and blog generation routes.';
    protected $usage       = 'reach:ai-seed-catalog [--prefer=openai|gemini] [--force-enable]';
    protected $options     = [
        '--prefer'       => 'Primary provider for draft/brief routes: openai|gemini.',
        '--force-enable' => 'Force-enable providers even if env keys look empty.',
    ];

    public function run(array $params): int
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $prefer = strtolower((string) ($this->sparkOption('prefer', $params, '') ?? ''));
        $created = [
            'providers' => [],
            'models'    => [],
            'routes'    => [],
            'fallbacks' => [],
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

            // Text models are env-driven so upgrades never require a code change.
            $openaiModelKey = trim((string) (env('AI_OPENAI_DEFAULT_MODEL') ?: 'gpt-5-mini'));
            $geminiModelKey = trim((string) (env('AI_GEMINI_DEFAULT_MODEL') ?: 'gemini-2.5-flash'));

            $openaiModel = $this->upsertModel($db, $openaiId, [
                'model_key'    => $openaiModelKey,
                'display_name' => 'OpenAI ' . $openaiModelKey,
                'model_family' => explode('-', $openaiModelKey)[0] . '-' . (explode('-', $openaiModelKey)[1] ?? ''),
                'context_limit'=> 128000,
            ], $now, $created);

            $geminiModel = $this->upsertModel($db, $geminiId, [
                'model_key'    => $geminiModelKey,
                'display_name' => 'Gemini ' . $geminiModelKey,
                'model_family' => 'gemini',
                'context_limit'=> 1000000,
            ], $now, $created);
            // Retired on Google AI.
            $this->disableModelKey($db, $geminiId, 'gemini-2.0-flash', $now);

            // Strict alternation needs BOTH providers routable for every blog
            // task; provider_preference hints pick per run. --prefer only
            // decides who wins when no hint is present.
            $openaiFirst = $prefer !== 'gemini';
            [$hiModel, $loModel] = $openaiFirst
                ? [$openaiModel, $geminiModel]
                : [$geminiModel, $openaiModel];

            $routeSpecs = [];
            foreach ([
                ['draft_generation', 'blog_post'],
                ['draft_generation', null],
                ['brief_generation', 'generic'],
                ['brief_generation', null],
                ['editorial_review', 'blog_post'],
                ['editorial_review', null],
                ['content_revision', 'blog_post'],
                ['content_revision', null],
                ['community_answer', null],
            ] as [$task, $contentType]) {
                $routeSpecs[] = [$task, $contentType, $hiModel, 100];
                $routeSpecs[] = [$task, $contentType, $loModel, 90];
            }

            $routeIds = [];
            foreach ($routeSpecs as [$task, $contentType, $modelId, $priority]) {
                if ($modelId <= 0) {
                    continue;
                }
                $routeIds[] = [
                    'id' => $this->upsertRoute($db, $task, $contentType, $modelId, $priority, $now, $created),
                    'source_model_id' => $modelId,
                    'task_type' => $task,
                ];
            }

            // Disable routes still pointing at models outside the current pair
            // (e.g. retired gpt-4o-mini / gemini-2.5-flash primaries).
            $this->demoteOtherPrimaryModels(
                $db,
                ['draft_generation', 'brief_generation', 'editorial_review', 'content_revision', 'community_answer'],
                array_values(array_filter([$openaiModel, $geminiModel])),
                $now,
            );

            // Mutual failover: each provider's route falls back to the other.
            foreach ($routeIds as $route) {
                $fallbackModel = ((int) $route['source_model_id'] === $openaiModel) ? $geminiModel : $openaiModel;
                if ($fallbackModel <= 0) {
                    continue;
                }
                if ($this->upsertFallback(
                    $db,
                    (int) $route['id'],
                    (int) $route['source_model_id'],
                    $fallbackModel,
                    ['budget_blocked', 'authentication_error', 'rate_limited', 'provider_unavailable', 'timeout', 'network_error'],
                    $now,
                )) {
                    $created['fallbacks'][] = $route['task_type'] . '->model#' . $fallbackModel;
                }
            }

            CLI::write(json_encode([
                'event'   => 'ai_seed_catalog.completed',
                'ts'      => gmdate('c'),
                'prefer'  => $prefer !== '' ? $prefer : 'auto',
                'openai_model_id'  => $openaiModel,
                'gemini_model_id'  => $geminiModel,
                'created' => $created,
                'hint'    => 'Both providers stay routable; scheduled drafts alternate via reach_ai_provider_rotation.',
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
    ): int {
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
            return (int) $row['id'];
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
        return (int) $db->insertID();
    }

    /**
     * @param list<string> $taskTypes
     * @param list<int>    $keepModelIds
     */
    private function demoteOtherPrimaryModels($db, array $taskTypes, array $keepModelIds, string $now): void
    {
        $keepModelIds = array_values(array_filter($keepModelIds, static fn ($id) => (int) $id > 0));
        if ($keepModelIds === [] || $taskTypes === []) {
            return;
        }

        $db->table('reach_ai_model_routes')
            ->whereIn('task_type', $taskTypes)
            ->whereNotIn('primary_model_id', $keepModelIds)
            ->where('deleted_at IS NULL', null, false)
            ->update([
                'enabled'    => false,
                'priority'   => 10,
                'updated_at' => $now,
            ]);
    }

    private function disableModelKey($db, int $providerId, string $modelKey, string $now): void
    {
        if ($providerId <= 0 || $modelKey === '') {
            return;
        }
        $db->table('reach_ai_models')
            ->where('provider_id', $providerId)
            ->where('model_key', $modelKey)
            ->update([
                'enabled'    => false,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param list<string> $allowedCategories
     */
    private function upsertFallback(
        $db,
        int $routeId,
        int $sourceModelId,
        int $fallbackModelId,
        array $allowedCategories,
        string $now,
    ): bool {
        if ($routeId <= 0 || $sourceModelId <= 0 || $fallbackModelId <= 0 || $sourceModelId === $fallbackModelId) {
            return false;
        }
        if (! SchemaGuard::hasTable($db, 'reach_ai_model_fallbacks')) {
            return false;
        }

        // Key on the UNIQUE constraint (route, source, order) — not on the
        // fallback target. When the target model changes (e.g. a retired
        // Gemini row swapped for its replacement), the order-1 slot must be
        // UPDATED in place; keying on fallback_model_id inserted a duplicate
        // order-1 row and died on uq_ai_fallback_route_src_order.
        $row = $db->table('reach_ai_model_fallbacks')
            ->where('route_id', $routeId)
            ->where('source_model_id', $sourceModelId)
            ->where('fallback_order', 1)
            ->get()
            ->getRowArray();

        $data = [
            'fallback_model_id'        => $fallbackModelId,
            'allowed_error_categories' => json_encode(array_values($allowedCategories)),
            'enabled'                  => true,
            'updated_at'               => $now,
        ];

        if ($row) {
            $db->table('reach_ai_model_fallbacks')->where('id', (int) $row['id'])->update($data);
            return false;
        }

        $db->table('reach_ai_model_fallbacks')->insert(array_merge($data, [
            'route_id'          => $routeId,
            'source_model_id'   => $sourceModelId,
            'fallback_order'    => 1,
            'created_at'        => $now,
        ]));

        return true;
    }
}
