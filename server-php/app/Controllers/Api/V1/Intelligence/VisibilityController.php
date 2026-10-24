<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\AiVisibilityPromptModel;
use App\Models\Intelligence\AiVisibilityPromptVersionModel;
use App\Models\Intelligence\AiVisibilityRunModel;
use App\Libraries\Intelligence\Visibility\AiVisibilityService;
use App\Libraries\Intelligence\Visibility\VisibilityRecordsService;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class VisibilityController extends BaseApiController
{
    public function prompts(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model    = new AiVisibilityPromptModel();
        $status   = (string) ($this->request->getGet('status') ?? 'active');
        $rows     = $status === 'all'
            ? $model->where('tenant_id', $tenantId)->where('status !=', 'archived')->orderBy('id')->findAll()
            : $model->getActiveForTenant($tenantId);

        // Each prompt with the text that is actually asked (its active version).
        $versions = [];
        if ($rows !== []) {
            foreach ((new AiVisibilityPromptVersionModel())->whereIn('prompt_id', array_column($rows, 'id'))->where('is_active', true)->findAll() as $v) {
                $versions[(int) $v['prompt_id']] = $v;
            }
        }
        foreach ($rows as &$row) {
            $v                         = $versions[(int) $row['id']] ?? null;
            $row['active_version']     = $v === null ? null : ['id' => (int) $v['id'], 'version_number' => (int) $v['version_number'], 'prompt_text' => $v['prompt_text']];
            $row['has_active_version'] = $v !== null;
        }
        unset($row);

        return $this->response->setJSON(['data' => $rows]);
    }

    /** PUT intelligence/visibility/prompts/{id} — name, topic, market (persona), locale, intent, status. Text changes go through versions. */
    public function updatePrompt(int $id): ResponseInterface
    {
        $model  = new AiVisibilityPromptModel();
        $prompt = $model->find($id);
        if (! $prompt) {
            return $this->fail('Not found', 404);
        }
        $body    = $this->input();
        $allowed = array_intersect_key($body, array_flip(['name', 'topic', 'intent', 'persona', 'locale', 'status']));
        if (isset($allowed['status']) && ! in_array($allowed['status'], ['draft', 'active', 'paused', 'archived'], true)) {
            return $this->fail('status must be draft, active, paused or archived', 422);
        }
        if ($allowed === []) {
            return $this->fail('Nothing to update', 422);
        }
        if (! $model->update($id, $allowed)) {
            return $this->fail('Unable to update prompt', 422, $model->errors());
        }
        $this->audit('visibility.prompt.updated', 'ai_visibility_prompt', $id, $prompt, $model->find($id));

        return $this->ok($model->find($id));
    }

    /** GET intelligence/visibility/providers — which assistants run, which are skipped and why. */
    public function providers(): ResponseInterface
    {
        return $this->ok((new AiVisibilityService())->providers());
    }

    /** GET intelligence/visibility/summary?days=28 */
    public function summary(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->fail('AI visibility tables are not migrated yet. Run database migrations.', 503);
        }
        $days = (int) ($this->request->getGet('days') ?? 28);

        return $this->ok((new AiVisibilityService())->summary($days > 0 ? $days : 28));
    }

    /** GET intelligence/visibility/answers?limit=50&provider=&prompt_id= */
    public function answers(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->ok([]);
        }
        $promptId = $this->request->getGet('prompt_id');

        return $this->ok((new AiVisibilityService())->answers(
            (int) ($this->request->getGet('limit') ?? 50),
            $this->request->getGet('provider') ? (string) $this->request->getGet('provider') : null,
            $promptId !== null && $promptId !== '' ? (int) $promptId : null,
        ));
    }

    /** GET intelligence/visibility/batches */
    public function batches(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->ok([]);
        }

        return $this->ok((new AiVisibilityService())->batches((int) ($this->request->getGet('limit') ?? 20)));
    }

    /**
     * POST intelligence/visibility/batches — "Run now": queue a manual pass
     * over today's not-yet-asked prompts. 409 when no assistant is
     * configured, 429 when the daily cap is used — never a fake success.
     */
    public function runBatch(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->fail('AI visibility tables are not migrated yet. Run database migrations.', 503);
        }
        $svc = new AiVisibilityService();
        if ($svc->providers() === [] || array_filter($svc->providers(), static fn (array $p): bool => $p['will_run']) === []) {
            return $this->fail('No assistant is configured: add a Gemini, OpenAI or Perplexity API key in Settings → AI & Automation.', 409, ['providers' => $svc->providers()]);
        }
        $batch = $svc->startBatch('manual', $this->userId());
        $this->audit('visibility.batch.started', 'ai_visibility_batch', (int) ($batch['id'] ?? 0), null, null, ['status' => $batch['status'] ?? null, 'runs' => count($batch['run_ids'] ?? [])]);
        if (($batch['status'] ?? '') === 'skipped') {
            $code = str_contains((string) ($batch['note'] ?? ''), 'cap') ? 429 : 409;
            return $this->fail((string) $batch['note'], $code, ['batch' => $batch]);
        }

        return $this->ok($batch, 202);
    }

    private function installed(): bool
    {
        $db = \Config\Database::connect();

        return SchemaGuard::hasTable($db, 'reach_ai_visibility_batches') && SchemaGuard::hasTable($db, 'reach_ai_visibility_runs');
    }

    public function createPrompt(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $body['purpose'] = 'ai_visibility_monitoring';
        $model = new AiVisibilityPromptModel();
        $id    = $model->insert($body);
        if (!$id) return $this->response->setStatusCode(422)->setJSON(['errors' => $model->errors()]);

        (new AuditLogger())->log(null, AuditLogger::VISIBILITY_PROMPT_CREATED, 'ai_visibility_prompt', (int)$id,
            null, $model->find($id), null, 'human');

        return $this->response->setStatusCode(201)->setJSON(['data' => $model->find($id)]);
    }

    public function prompt(int $id): ResponseInterface
    {
        $model  = new AiVisibilityPromptModel();
        $prompt = $model->find($id);
        if (!$prompt) return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        return $this->response->setJSON(['data' => $prompt]);
    }

    public function createVersion(int $promptId): ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $text  = trim($body['prompt_text'] ?? '');
        if (empty($text)) return $this->response->setStatusCode(422)->setJSON(['error' => 'prompt_text required']);

        $vModel  = new AiVisibilityPromptVersionModel();
        $version = $vModel->getNextVersionNumber($promptId);
        $hash    = hash('sha256', $text);

        $id = $vModel->insert([
            'prompt_id'      => $promptId,
            'version_number' => $version,
            'prompt_text'    => $text,
            'content_hash'   => $hash,
            'is_active'      => false,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setStatusCode(201)->setJSON(['data' => $vModel->find($id)]);
    }

    public function approveVersion(int $promptId, int $versionId): ResponseInterface
    {
        $vModel  = new AiVisibilityPromptVersionModel();
        $version = $vModel->find($versionId);
        if (!$version || $version['prompt_id'] !== $promptId) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        $vModel->db->query(
            "UPDATE reach_ai_visibility_prompt_versions SET is_active = FALSE WHERE prompt_id = ?",
            [$promptId]
        );
        $vModel->update($versionId, ['is_active' => true, 'approved_at' => date('Y-m-d H:i:s')]);

        (new AuditLogger())->log(null, AuditLogger::VISIBILITY_PROMPT_VERSION_APPROVED, 'ai_visibility_prompt_version', $versionId,
            null, null, null, 'human');

        return $this->response->setJSON(['data' => $vModel->find($versionId)]);
    }

    /**
     * POST intelligence/visibility/runs {prompt_version_id} — ask one prompt
     * version to every configured assistant now. Each run is queued as a job
     * the worker executes; 409 when no assistant is configured.
     */
    public function queueRun(): ResponseInterface
    {
        $body      = $this->request->getJSON(true) ?? [];
        $versionId = (int) ($body['prompt_version_id'] ?? 0);

        if (!$versionId) return $this->response->setStatusCode(422)->setJSON(['error' => 'prompt_version_id required']);

        try {
            $ids = (new AiVisibilityService())->queuePromptVersion($versionId, $this->userId());
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\OverflowException $e) {
            return $this->fail($e->getMessage(), 429);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404);
        }

        $runModel = new AiVisibilityRunModel();
        foreach ($ids as $id) {
            (new AuditLogger())->log($this->userId(), AuditLogger::VISIBILITY_RUN_QUEUED, 'ai_visibility_run', $id, null, null, null, 'human');
        }

        return $this->response->setStatusCode(201)->setJSON(['data' => $runModel->whereIn('id', $ids)->findAll()]);
    }

    /**
     * GET intelligence/visibility/runs — the answers behind every overview
     * number. Filters: days, status, provider, prompt_id, market, batch_id,
     * q, and the facets entity, competitor_id, brand (named|not_named|cited),
     * gap=1, cited_domain; page, per_page. `data` stays the row list so older
     * callers keep working; `meta` carries the total and the applied filters.
     */
    public function runs(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_ai_visibility_runs')) {
                return $this->response->setJSON(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 50, 'filters' => []]]);
            }

            $result = (new VisibilityRecordsService($db, $tenantId))->runs(
                $this->request->getGet() ?? [],
                (int) ($this->request->getGet('page') ?? 1),
                (int) ($this->request->getGet('per_page') ?? 50),
            );

            return $this->response->setJSON(['data' => $result['rows'], 'meta' => array_diff_key($result, ['rows' => true])]);
        } catch (\Throwable $e) {
            log_message('error', 'VisibilityController::runs: ' . $e->getMessage());
            return $this->response->setJSON(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 50, 'filters' => []]]);
        }
    }

    /** GET intelligence/visibility/runs/{id} — one answer: question, raw text, observations, citations. */
    public function run(int $id): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->fail('Not found', 404);
        }
        $run = (new VisibilityRecordsService(null, (int) ($this->request->getGet('tenant_id') ?? 1)))->run($id);

        return $run === null ? $this->fail('Not found', 404) : $this->ok($run);
    }

    /**
     * GET intelligence/visibility/observations — one row per name per answer.
     * Filters: run_id, coverage_state, mention_type, entity, competitor_id,
     * provider, prompt_id, days, counted=1; page, per_page.
     */
    public function observations(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $empty    = ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 50, 'filters' => []]];
        try {
            $db = \Config\Database::connect();
            if (
                ! SchemaGuard::hasTable($db, 'reach_ai_visibility_observations')
                || ! SchemaGuard::hasTable($db, 'reach_ai_visibility_runs')
            ) {
                return $this->response->setJSON($empty);
            }

            $result = (new VisibilityRecordsService($db, $tenantId))->observations(
                $this->request->getGet() ?? [],
                (int) ($this->request->getGet('page') ?? 1),
                (int) ($this->request->getGet('per_page') ?? 50),
            );

            return $this->response->setJSON(['data' => $result['rows'], 'meta' => array_diff_key($result, ['rows' => true])]);
        } catch (\Throwable $e) {
            log_message('error', 'VisibilityController::observations: ' . $e->getMessage());
            return $this->response->setJSON($empty);
        }
    }
}
