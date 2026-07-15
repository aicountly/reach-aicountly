<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Libraries\AuditLogger;
use App\Models\Intelligence\AiVisibilityPromptModel;
use App\Models\Intelligence\AiVisibilityPromptVersionModel;
use App\Models\Intelligence\AiVisibilityRunModel;
use App\Models\Intelligence\AiVisibilityObservationModel;
use CodeIgniter\HTTP\ResponseInterface;

class VisibilityController extends BaseController
{
    public function prompts(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model    = new AiVisibilityPromptModel();
        return $this->response->setJSON(['data' => $model->getActiveForTenant($tenantId)]);
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

    public function queueRun(): ResponseInterface
    {
        $body      = $this->request->getJSON(true) ?? [];
        $versionId = (int) ($body['prompt_version_id'] ?? 0);
        $tenantId  = (int) ($body['tenant_id'] ?? 1);

        if (!$versionId) return $this->response->setStatusCode(422)->setJSON(['error' => 'prompt_version_id required']);

        $runModel = new AiVisibilityRunModel();
        $id       = $runModel->insert([
            'tenant_id'        => $tenantId,
            'prompt_version_id' => $versionId,
            'run_type'         => 'manual_test',
            'status'           => 'queued',
            'queued_at'        => date('Y-m-d H:i:s'),
        ]);

        (new AuditLogger())->log(null, AuditLogger::VISIBILITY_RUN_QUEUED, 'ai_visibility_run', (int)$id, null, null, null, 'human');

        return $this->response->setStatusCode(201)->setJSON(['data' => $runModel->find($id)]);
    }

    public function runs(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model    = new AiVisibilityRunModel();
        $runs     = $model->where('tenant_id', $tenantId)->orderBy('queued_at', 'DESC')->findAll(50);
        return $this->response->setJSON(['data' => $runs]);
    }

    public function observations(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $model    = new AiVisibilityObservationModel();
        $obs      = $model->getByCoverage($tenantId, $this->request->getGet('coverage_state') ?? 'mentioned');
        return $this->response->setJSON(['data' => $obs]);
    }
}
