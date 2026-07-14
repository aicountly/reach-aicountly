<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\AudienceSegmentService;
use App\Models\Distribution\AudienceSegmentModel;
use App\Models\Distribution\AudienceSegmentRuleModel;
use CodeIgniter\HTTP\ResponseInterface;

class AudienceSegmentController extends BaseApiController
{
    private AudienceSegmentService $service;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new AudienceSegmentService(
            new AudienceSegmentModel(),
            new AudienceSegmentRuleModel(),
            new AuditLogger(),
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    public function index(): ResponseInterface
    {
        $segments = $this->service->list($this->tenantId());
        return $this->ok(['data' => $segments]);
    }

    public function store(): ResponseInterface
    {
        $body = $this->input() ?: [];
        try {
            $segment = $this->service->create($this->tenantId(), $body, $this->userId());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($segment, 201);
    }

    public function show(string $id): ResponseInterface
    {
        $model   = new AudienceSegmentModel();
        $segment = $model->findByUuid($id, $this->tenantId()) ?? $model->find((int) $id);
        if ($segment === null || (int) $segment['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        return $this->ok($segment);
    }

    public function update(string $id): ResponseInterface
    {
        $body = $this->input() ?: [];
        try {
            $model   = new AudienceSegmentModel();
            $segment = $model->findByUuid($id, $this->tenantId()) ?? $model->find((int) $id);
            if ($segment === null || (int) $segment['tenant_id'] !== $this->tenantId()) {
                return $this->fail('Not found', 404);
            }
            $updated = $this->service->update((int) $segment['id'], $this->tenantId(), $body, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($updated);
    }

    public function destroy(string $id): ResponseInterface
    {
        $model   = new AudienceSegmentModel();
        $segment = $model->findByUuid($id, $this->tenantId()) ?? $model->find((int) $id);
        if ($segment === null || (int) $segment['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        $this->service->delete((int) $segment['id'], $this->tenantId(), $this->userId());
        return $this->ok(['deleted' => true]);
    }

    public function preview(string $id): ResponseInterface
    {
        $model   = new AudienceSegmentModel();
        $segment = $model->findByUuid($id, $this->tenantId()) ?? $model->find((int) $id);
        if ($segment === null || (int) $segment['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        try {
            $result = $this->service->preview((int) $segment['id'], $this->tenantId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }
}
