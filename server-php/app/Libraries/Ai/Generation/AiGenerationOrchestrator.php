<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiFallbackResolver;
use App\Libraries\Ai\AiModelRouter;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Grounding\AiGroundingContextBuilder;
use App\Libraries\Ai\Grounding\GroundingException;
use App\Libraries\Ai\Grounding\GroundingSnapshotService;
use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use App\Libraries\Ai\Prompts\PromptRenderer;
use App\Libraries\Ai\Providers\MockAiProvider;
use App\Libraries\Ai\Security\AiCircuitBreaker;
use App\Libraries\Ai\Security\ConfidentialDataDetector;
use App\Libraries\Ai\Security\PiiScrubber;
use App\Libraries\Ai\Security\PromptInjectionDetector;
use App\Libraries\AuditLogger;

/**
 * Phase 3 — Core generation orchestrator.
 *
 * This is the single entry point for executing a queued generation request.
 * It implements:
 * 1. Permission/status validation
 * 2. Grounding context assembly
 * 3. Prompt rendering
 * 4. Budget check
 * 5. Provider selection and fallback
 * 6. Artifact storage
 * 7. Usage ledger recording
 *
 * Contract:
 * - Never publishes content or sends campaigns.
 * - Never auto-approves generated content.
 * - Never calls production AI APIs in test mode.
 */
class AiGenerationOrchestrator
{
    private const MAX_ATTEMPTS = 3;
    /** Maximum characters allowed in a single prompt part (system or user). */
    private const MAX_PROMPT_CHARS = 32_000;

    private AiProviderRegistry $registry;
    private AiModelRouter $router;
    private AiFallbackResolver $fallback;
    private AiGroundingContextBuilder $grounding;
    private GroundingSnapshotService $snapshots;
    private AiGenerationRequestService $requests;
    private AiGenerationRunService $runs;
    private AiGenerationArtifactService $artifacts;
    private AiBudgetService $budget;
    private PromptRenderer $renderer;
    private PromptInjectionDetector $injectionDetector;
    private PiiScrubber $piiScrubber;
    private ConfidentialDataDetector $confidentialDetector;
    private AiCircuitBreaker $circuitBreaker;

    public function __construct()
    {
        $this->registry             = new AiProviderRegistry();
        $this->router               = new AiModelRouter($this->registry);
        $this->fallback             = new AiFallbackResolver($this->registry);
        $this->grounding            = new AiGroundingContextBuilder();
        $this->snapshots            = new GroundingSnapshotService();
        $this->requests             = new AiGenerationRequestService();
        $this->runs                 = new AiGenerationRunService();
        $this->artifacts            = new AiGenerationArtifactService();
        $this->budget               = new AiBudgetService();
        $this->renderer             = new PromptRenderer();
        $this->injectionDetector    = new PromptInjectionDetector();
        $this->piiScrubber          = new PiiScrubber();
        $this->confidentialDetector = new ConfidentialDataDetector();
        $this->circuitBreaker       = new AiCircuitBreaker();
    }

    /**
     * Execute a generation request by ID.
     * Called from the job queue worker — NOT from user-facing controllers.
     */
    public function execute(int $requestId): void
    {
        $request = $this->requests->findById($requestId);

        if ($request['status'] === 'cancelled') {
            return;
        }

        $this->requests->updateStatus($requestId, 'grounding');

        // --- Grounding ---
        $groundingContext = [];
        try {
            $productSlug = $this->resolveProductSlug($request);
            if ($productSlug) {
                $groundingContext = $this->grounding->buildForProduct($productSlug, $request['task_type']);
            } else {
                $groundingContext = $this->grounding->buildForIntent($request['task_type']);
            }
        } catch (GroundingException $e) {
            $this->failRequest($requestId, 'grounding_failed', $e->getMessage());
            return;
        }

        $snapshot = $this->snapshots->createForRequest($requestId, $groundingContext);

        // --- Route selection ---
        $this->requests->updateStatus($requestId, 'processing');

        try {
            $decision = $this->router->route($request['task_type'], $request['content_type'] ?? null);
        } catch (\Throwable $e) {
            $this->failRequest($requestId, 'routing_failed', 'No route available: ' . $e->getMessage());
            return;
        }

        // --- Budget check ---
        $budgetContext = [
            'provider_key' => $decision->provider->getProviderKey(),
            'model_key'    => $decision->modelKey,
            'content_type' => $request['content_type'] ?? '',
        ];
        $budgetResult = $this->budget->check($budgetContext);

        if ($budgetResult->hardBlocked) {
            $this->requests->updateStatus($requestId, 'blocked');
            AuditLogger::record('ai.budget_blocked', [
                'request_id'  => $requestId,
                'scope_type'  => $budgetResult->scopeType,
                'scope_ref'   => $budgetResult->scopeRef,
                'period_type' => $budgetResult->periodType,
                'used_amount' => $budgetResult->usedAmount,
                'hard_limit'  => $budgetResult->hardLimit,
            ]);
            return;
        }

        // --- Security: scan grounding context for confidential data ---
        $groundingJson = json_encode($groundingContext);
        if (! $this->confidentialDetector->isClean($groundingJson)) {
            $this->failRequest($requestId, 'confidential_data_in_grounding', 'Confidential data detected in grounding context.');
            return;
        }

        // --- Prompt preparation ---
        $promptVersion = $this->resolvePromptVersion($request);
        $outputSchema  = $promptVersion
            ? json_decode($promptVersion['output_schema_json'] ?? '{}', true)
            : OutputSchemaRegistry::get($request['content_type'] ?? 'generic');

        $systemPrompt = $this->buildSystemPrompt($promptVersion, $groundingContext, $request);
        $userPrompt   = $this->buildUserPrompt($promptVersion, $request);

        // --- Security: injection detection on rendered prompts ---
        if ($this->injectionDetector->detect($userPrompt)) {
            $this->failRequest($requestId, 'prompt_injection_detected', 'Prompt injection pattern detected in user prompt.');
            return;
        }

        // --- Security: PII scrub user prompt ---
        $userPrompt = $this->piiScrubber->scrub($userPrompt);

        // --- Size control: hard cap on prompt lengths ---
        if (strlen($systemPrompt) > self::MAX_PROMPT_CHARS) {
            $systemPrompt = substr($systemPrompt, 0, self::MAX_PROMPT_CHARS);
        }
        if (strlen($userPrompt) > self::MAX_PROMPT_CHARS) {
            $userPrompt = substr($userPrompt, 0, self::MAX_PROMPT_CHARS);
        }

        // --- Generation with fallback ---
        $attemptedModelIds = [];
        $attemptNumber     = $this->runs->countAttemptsForRequest($requestId) + 1;
        $currentDecision   = $decision;

        while ($attemptNumber <= self::MAX_ATTEMPTS) {
            $providerKey = $currentDecision->provider->getProviderKey();

            // --- Circuit breaker: skip open circuits ---
            if ($this->circuitBreaker->isOpen($providerKey)) {
                $attemptedModelIds[] = 0;
                $nextDecision = $currentDecision->routeId
                    ? $this->fallback->resolveNext($currentDecision->routeId, 0, AiProviderError::CATEGORY_SERVICE_UNAVAILABLE, $attemptedModelIds)
                    : null;
                if (! $nextDecision) {
                    $this->failRequest($requestId, AiProviderError::CATEGORY_SERVICE_UNAVAILABLE, 'Circuit open for provider: ' . $providerKey);
                    return;
                }
                $currentDecision = $nextDecision;
                $attemptNumber++;
                continue;
            }

            [$providerId, $modelId] = $this->resolveProviderAndModelIds(
                $providerKey,
                $currentDecision->modelKey,
            );

            if ($providerId <= 0 || $modelId <= 0) {
                $this->failRequest(
                    $requestId,
                    AiProviderError::CATEGORY_CONFIGURATION,
                    "AI provider/model catalog rows missing for '{$providerKey}' / '{$currentDecision->modelKey}'.",
                );
                return;
            }

            $run = $this->runs->create($requestId, $providerId, $modelId, $attemptNumber, $promptVersion ? (int) $promptVersion['id'] : null);
            // Postgres drivers often return numeric ids as strings.
            $runId = (int) $run['id'];
            $this->runs->linkGroundingSnapshot($runId, (int) $snapshot['id']);
            $this->runs->markRunning($runId);

            // Draft-length blog generation routinely exceeds 30s under structured output.
            $timeout = in_array((string) ($request['task_type'] ?? ''), [
                'draft_generation', 'content_expansion', 'section_regeneration',
            ], true) ? 120 : 45;

            $input = new AiGenerationInput(
                systemPrompt:    $systemPrompt,
                userPrompt:      $userPrompt,
                outputSchema:    $outputSchema,
                modelKey:        $currentDecision->modelKey,
                timeoutSeconds:  $timeout,
                requestId:       $request['uuid'] ?? null,
            );

            try {
                $result = $currentDecision->provider->generate($input);
                $this->circuitBreaker->recordSuccess($providerKey);
                $this->runs->markCompleted($runId, $result);

                $artifact = $this->artifacts->store($requestId, $runId, $result, $outputSchema);

                $this->budget->recordUsage([
                    'generation_request_id' => $requestId,
                    'generation_run_id'     => $runId,
                    'provider_id'           => $providerId,
                    'model_id'              => $modelId,
                    'prompt_version_id'     => $promptVersion ? (int) $promptVersion['id'] : null,
                    'content_item_id'       => $request['content_item_id'] ?? null,
                    'content_type'          => $request['content_type'] ?? '',
                    'task_type'             => $request['task_type'],
                    'actor_type'            => $request['requested_actor_type'] ?? 'human',
                    'user_id'               => $request['requested_by_user_id'] ?? null,
                    'input_tokens'          => $result->inputTokens,
                    'output_tokens'         => $result->outputTokens,
                    'total_tokens'          => $result->totalTokens,
                    'estimated_cost'        => 0.00,
                    'currency'              => 'USD',
                ]);

                // Only mark completed if schema validation passed
                if ($artifact['schema_validation_status'] === 'passed') {
                    $this->requests->updateStatus($requestId, 'completed', ['completed_at' => date('Y-m-d H:i:s')]);

                    AuditLogger::record('ai.generation_completed', [
                        'request_id'   => $requestId,
                        'run_id'       => $runId,
                        'artifact_id'  => $artifact['id'],
                        'total_tokens' => $result->totalTokens,
                    ]);
                } else {
                    $this->failRequest($requestId, 'schema_validation_failed', 'AI output did not pass schema validation.');
                }

                return;
            } catch (AiProviderException $e) {
                $this->circuitBreaker->recordFailure($providerKey, $e->getProviderError()->category);
                $this->runs->markFailed($runId, $e->getProviderError());

                if (! $e->isRetryable()) {
                    $this->failRequest($requestId, $e->getProviderError()->category, $e->getProviderError()->message);
                    return;
                }

                // Try fallback
                $attemptedModelIds[] = $modelId;
                $nextDecision = $currentDecision->routeId
                    ? $this->fallback->resolveNext($currentDecision->routeId, $modelId, $e->getProviderError()->category, $attemptedModelIds)
                    : null;

                if (! $nextDecision) {
                    $this->failRequest($requestId, $e->getProviderError()->category, 'No further fallback providers available.');
                    return;
                }

                $currentDecision = $nextDecision;
                $attemptNumber++;
            }
        }

        $this->failRequest($requestId, AiProviderError::CATEGORY_UNKNOWN, 'Max attempts exceeded.');
    }

    private function failRequest(int $requestId, string $reason, string $message): void
    {
        $this->requests->updateStatus($requestId, 'failed');
        // Always write to app log — AuditLogger can fail in CLI contexts and
        // previously swallowed the only copy of the failure reason.
        log_message('error', "AI generation request {$requestId} failed: {$reason} — {$message}");
        AuditLogger::record('ai.generation_failed', [
            'request_id' => $requestId,
            'reason'     => $reason,
            'message'    => $message,
        ]);
    }

    private function resolveProductSlug(array $request): ?string
    {
        if (! empty($request['content_item_id'])) {
            // CRITICAL FIX: reach_content_items has no `product_id` column — the
            // FK is `primary_product_id`. The previous join referenced a
            // non-existent column, which threw a DB error for every AI
            // generation request that carried a content_item_id.
            $row = db_connect()
                ->table('reach_content_items ci')
                ->join('reach_products p', 'p.id = ci.primary_product_id', 'left')
                ->select('p.slug')
                ->where('ci.id', $request['content_item_id'])
                ->limit(1)
                ->get()
                ->getRowArray();

            return $row['slug'] ?? null;
        }

        $params = json_decode($request['request_parameters_json'] ?? '{}', true);
        return $params['product_slug'] ?? null;
    }

    private function resolvePromptVersion(array $request): ?array
    {
        if (! empty($request['prompt_version_id'])) {
            return db_connect()
                ->table('reach_ai_prompt_versions')
                ->where('id', $request['prompt_version_id'])
                ->where('status', 'approved')
                ->get()
                ->getRowArray() ?: null;
        }

        // Auto-select: find approved prompt for this task+content type
        $template = db_connect()
            ->table('reach_ai_prompt_templates pt')
            ->join('reach_ai_prompt_versions pv', 'pv.id = pt.current_version_id')
            ->select('pv.*')
            ->where('pt.task_type', $request['task_type'])
            ->where('pt.status', 'approved')
            ->where('pt.deleted_at', null)
            ->limit(1)
            ->get()
            ->getRowArray();

        return $template ?: null;
    }

    private function buildSystemPrompt(?array $promptVersion, array $groundingContext, array $request): string
    {
        if ($promptVersion) {
            try {
                return $this->renderer->render($promptVersion['system_template'], [
                    'grounding_context' => json_encode($groundingContext, JSON_PRETTY_PRINT),
                    'content_type'      => $request['content_type'] ?? '',
                    'task_type'         => $request['task_type'],
                ]);
            } catch (\InvalidArgumentException) {
                // Fall through to default
            }
        }

        return "You are a professional marketing content writer. Use only the provided grounding context to generate accurate, approved content. Never add claims or facts not present in the context.\n\nGrounding context:\n" . json_encode($groundingContext, JSON_PRETTY_PRINT);
    }

    private function buildUserPrompt(?array $promptVersion, array $request): string
    {
        if ($promptVersion) {
            $params = json_decode($request['request_parameters_json'] ?? '{}', true);
            try {
                return $this->renderer->render($promptVersion['user_template'], $params);
            } catch (\InvalidArgumentException) {
                // Fall through
            }
        }

        $params = json_decode($request['request_parameters_json'] ?? '{}', true);
        return 'Generate a ' . ($request['content_type'] ?? 'piece of content') . ' based on the grounding context provided. ' . ($params['instructions'] ?? '');
    }

    /**
     * Resolve FK ids for the chosen provider/model. When REACH_AI_MOCK=true and
     * the mock adapter is selected, ensure catalog rows exist so generation runs
     * can satisfy NOT NULL FKs without requiring a seeded production catalog.
     *
     * @return array{0:int,1:int} [provider_id, model_id]
     */
    private function resolveProviderAndModelIds(string $providerKey, string $modelKey): array
    {
        $db = db_connect();

        if (
            ($_ENV['REACH_AI_MOCK'] ?? 'false') === 'true'
            && $providerKey === MockAiProvider::PROVIDER_KEY
        ) {
            return $this->ensureMockProviderCatalog($db, $modelKey);
        }

        $providerRow = $db->table('reach_ai_providers')
            ->where('provider_key', $providerKey)
            ->where('deleted_at IS NULL', null, false)
            ->limit(1)->get()->getRowArray();
        $modelRow = $db->table('reach_ai_models')
            ->where('model_key', $modelKey)
            ->where('deleted_at IS NULL', null, false)
            ->limit(1)->get()->getRowArray();

        return [
            $providerRow ? (int) $providerRow['id'] : 0,
            $modelRow ? (int) $modelRow['id'] : 0,
        ];
    }

    /**
     * @return array{0:int,1:int} [provider_id, model_id]
     */
    private function ensureMockProviderCatalog($db, string $modelKey): array
    {
        $providerRow = $db->table('reach_ai_providers')
            ->where('provider_key', MockAiProvider::PROVIDER_KEY)
            ->where('deleted_at IS NULL', null, false)
            ->limit(1)->get()->getRowArray();

        if (! $providerRow) {
            $db->table('reach_ai_providers')->insert([
                'provider_key'               => MockAiProvider::PROVIDER_KEY,
                'display_name'               => 'Mock AI Provider',
                'adapter_class'              => MockAiProvider::class,
                'secret_env_reference'       => '',
                'status'                     => 'enabled',
                'supports_structured_output' => true,
                'supports_health_check'      => true,
                'configuration_status'       => 'configured',
                'created_actor_type'         => 'system',
                'created_at'                 => date('Y-m-d H:i:s'),
                'updated_at'                 => date('Y-m-d H:i:s'),
            ]);
            $providerId = (int) $db->insertID();
        } else {
            $providerId = (int) $providerRow['id'];
            if (($providerRow['status'] ?? '') !== 'enabled') {
                $db->table('reach_ai_providers')->where('id', $providerId)->update([
                    'status'       => 'enabled',
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $modelRow = $db->table('reach_ai_models')
            ->where('provider_id', $providerId)
            ->where('model_key', $modelKey)
            ->where('deleted_at IS NULL', null, false)
            ->limit(1)->get()->getRowArray();

        if (! $modelRow) {
            // Prefer an existing mock-model under this provider if key mismatches.
            $modelRow = $db->table('reach_ai_models')
                ->where('provider_id', $providerId)
                ->where('model_key', 'mock-model')
                ->where('deleted_at IS NULL', null, false)
                ->limit(1)->get()->getRowArray();
        }

        if (! $modelRow) {
            $db->table('reach_ai_models')->insert([
                'provider_id'                => $providerId,
                'model_key'                  => $modelKey !== '' ? $modelKey : 'mock-model',
                'display_name'               => 'Mock Model',
                'model_family'               => 'mock',
                'supports_structured_output' => true,
                'enabled'                    => true,
                'approval_status'            => 'approved',
                'created_at'                 => date('Y-m-d H:i:s'),
                'updated_at'                 => date('Y-m-d H:i:s'),
            ]);
            $modelId = (int) $db->insertID();
        } else {
            $modelId = (int) $modelRow['id'];
            if (! ($modelRow['enabled'] ?? false)) {
                $db->table('reach_ai_models')->where('id', $modelId)->update([
                    'enabled'     => true,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return [$providerId, $modelId];
    }
}
