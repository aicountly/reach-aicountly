<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;
use App\Libraries\Ai\Generation\AiCancellationService;
use App\Libraries\Ai\Generation\AiGenerationOrchestrator;
use App\Libraries\Ai\Generation\AiGenerationRequestService;
use App\Libraries\AuditLogger;
use App\Libraries\JobService;

/**
 * Phase 3 — AI Generation API Controller.
 *
 * POST /api/v1/ai/generate     — create and queue a generation request (returns 202)
 * GET  /api/v1/ai/generations  — list generation requests (paginated)
 * GET  /api/v1/ai/generations/:uuid — show a single request with latest run
 * POST /api/v1/ai/generations/:uuid/cancel — cancel a pending/queued request
 */
class AiGenerationController extends BaseApiController
{
    private AiGenerationRequestService $requests;
    private AiCancellationService $cancellation;

    public function __construct()
    {
        $this->requests     = new AiGenerationRequestService();
        $this->cancellation = new AiCancellationService();
    }

    /**
     * POST /api/v1/ai/generate
     * Creates a generation request and enqueues a job. Returns 202 Accepted.
     * AI must NOT be used as the requester actor_type.
     */
    public function generate(): \CodeIgniter\HTTP\ResponseInterface
    {
        $data = $this->request->getJSON(true) ?? [];

        $required = ['task_type', 'content_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->fail("{$field} is required", 422);
            }
        }

        $actor = $this->actor();

        // Build idempotency key from content item if provided
        $idempotencyKey = null;
        if (! empty($data['content_item_id']) && ! empty($data['task_type'])) {
            $idempotencyKey = 'gen_' . $data['content_item_id'] . '_' . $data['task_type'] . '_' . date('Y-m-d');
        }

        try {
            $request = $this->requests->create([
                'task_type'       => $data['task_type'],
                'content_type'    => $data['content_type'],
                'content_item_id' => $data['content_item_id'] ?? null,
                'daily_pack_id'   => $data['daily_pack_id'] ?? null,
                'prompt_version_id' => $data['prompt_version_id'] ?? null,
                'priority'        => $data['priority'] ?? 0,
                'parameters'      => $data['parameters'] ?? [],
                'request_id'      => $this->request->getHeaderLine('X-Request-Id'),
                'idempotency_key' => $data['idempotency_key'] ?? $idempotencyKey,
            ], $actor);
        } catch (\Throwable $e) {
            return $this->fail('Could not create generation request: ' . $e->getMessage(), 500);
        }

        // Enqueue job
        try {
            $jobService = new JobService();
            $jobId = $jobService->enqueue('reach.ai_generation', ['request_id' => $request['id']], [
                'priority'    => (int) ($data['priority'] ?? 0),
                'scheduled_at' => null,
            ]);
            $this->requests->linkJob($request['id'], $jobId);
        } catch (\Throwable $e) {
            // Job queue failure should not block the response; request is in 'pending' state
        }

        AuditLogger::log('ai.generation_requested', [
            'request_id'   => $request['id'],
            'request_uuid' => $request['uuid'],
            'task_type'    => $request['task_type'],
            'content_type' => $request['content_type'],
        ], $actor);

        return $this->ok(['request' => $request], 202);
    }

    /**
     * GET /api/v1/ai/generations
     */
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db      = db_connect();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 20;

        $requests = $db->table('reach_ai_generation_requests')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $total = $db->table('reach_ai_generation_requests')->countAllResults();

        return $this->ok(['requests' => $requests, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    /**
     * GET /api/v1/ai/generations/:uuid
     */
    public function show(string $uuid): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $request = $this->requests->findByUuid($uuid);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }

        $db   = db_connect();
        $runs = $db->table('reach_ai_generation_runs')
            ->where('generation_request_id', $request['id'])
            ->orderBy('attempt_number', 'ASC')
            ->get()
            ->getResultArray();

        $artifact = null;
        if (! empty($runs)) {
            $lastRun  = end($runs);
            $artifact = $db->table('reach_ai_generation_artifacts')
                ->where('generation_run_id', $lastRun['id'])
                ->limit(1)
                ->get()
                ->getRowArray() ?: null;
        }

        return $this->ok([
            'request'  => $request,
            'runs'     => $runs,
            'artifact' => $artifact,
        ]);
    }

    /**
     * POST /api/v1/ai/generations/:uuid/cancel
     */
    public function cancel(string $uuid): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $request = $this->requests->findByUuid($uuid);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }

        try {
            $result = $this->cancellation->cancel(
                $request['id'],
                $this->request->getJSON(true)['reason'] ?? 'User requested cancellation',
                $this->actor(),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(['request' => $result]);
    }
}
