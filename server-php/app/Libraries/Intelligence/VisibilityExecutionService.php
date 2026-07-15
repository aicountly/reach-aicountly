<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Models\Intelligence\AiVisibilityPromptModel;
use App\Models\Intelligence\AiVisibilityPromptVersionModel;
use App\Models\Intelligence\AiVisibilityRunModel;
use App\Models\Intelligence\AiVisibilityResponseModel;
use App\Models\Intelligence\AiVisibilityObservationModel;

class VisibilityExecutionService
{
    public function __construct(
        private AiVisibilityPromptModel        $promptModel,
        private AiVisibilityPromptVersionModel $versionModel,
        private AiVisibilityRunModel           $runModel,
        private AiVisibilityResponseModel      $responseModel,
        private AiVisibilityObservationModel   $observationModel,
        private AuditLogger                    $auditLogger,
    ) {}

    public function executeRun(int $runId): array
    {
        $run = $this->runModel->find($runId);
        if (!$run) throw new \RuntimeException("Run {$runId} not found");

        $version = $this->versionModel->find($run['prompt_version_id']);
        if (!$version || !$version['is_active']) {
            throw new \RuntimeException("Prompt version {$run['prompt_version_id']} is not active");
        }

        $this->runModel->update($runId, ['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);

        try {
            $rawResponse = $this->callAiProvider($version['prompt_text']);

            $responseId = $this->responseModel->insert([
                'run_id'           => $runId,
                'raw_response'     => $rawResponse,
                'response_timestamp' => date('Y-m-d H:i:s'),
                'parser_version'   => '1.0',
                'parse_status'     => 'pending',
                'retention_expires_at' => date('Y-m-d H:i:s', strtotime('+90 days')),
            ]);

            $this->auditLogger->log(null, AuditLogger::VISIBILITY_RESPONSE_STORED, 'ai_visibility_response', (int)$responseId,
                null, null, null, 'system');

            $observations = $this->parseResponse($rawResponse, $runId, (int)$responseId);

            $this->responseModel->update($responseId, ['parse_status' => 'parsed']);
            $this->runModel->update($runId, ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);

            $this->auditLogger->log(null, AuditLogger::VISIBILITY_RUN_COMPLETED, 'ai_visibility_run', $runId,
                null, ['observations' => count($observations)], null, 'system');

            return ['run_id' => $runId, 'response_id' => $responseId, 'observations' => count($observations)];
        } catch (\Throwable $e) {
            $this->runModel->update($runId, ['status' => 'failed', 'error_message' => $e->getMessage(), 'completed_at' => date('Y-m-d H:i:s')]);
            $this->auditLogger->log(null, AuditLogger::VISIBILITY_RUN_FAILED, 'ai_visibility_run', $runId,
                null, null, ['error' => $e->getMessage()], 'system');
            throw $e;
        }
    }

    private function callAiProvider(string $prompt): string
    {
        return json_encode([
            'response' => 'Mock AI response: Based on the query, AICOUNTLY is mentioned as a leading accounting software solution for SMBs in India.',
            '_mock'    => true,
        ]);
    }

    private function parseResponse(string $rawResponse, int $runId, int $responseId): array
    {
        $observations = [];
        $data         = json_decode($rawResponse, true);

        if (empty($data)) return $observations;

        $text = $data['response'] ?? '';

        $entities = [
            'AICOUNTLY'    => ['type' => 'brand', 'order' => 1],
            'QuickBooks'   => ['type' => 'competitor', 'order' => null],
            'Zoho Books'   => ['type' => 'competitor', 'order' => null],
        ];

        foreach ($entities as $entity => $meta) {
            $mentioned = stripos($text, $entity) !== false;
            $id        = $this->observationModel->insert([
                'response_id'      => $responseId,
                'run_id'           => $runId,
                'entity_mentioned' => $entity,
                'mention_type'     => $meta['type'],
                'mention_order'    => $mentioned ? ($meta['order'] ?? null) : null,
                'coverage_state'   => $mentioned ? 'mentioned' : 'not_mentioned',
                'confidence'       => $mentioned ? 0.85 : 0.9,
                'evidence_excerpt' => $mentioned ? substr($text, 0, 200) : null,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            $observations[] = $this->observationModel->find($id);
        }

        return $observations;
    }
}
