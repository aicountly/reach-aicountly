<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\SuppressionService;
use App\Enums\SuppressionReason;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\HTTP\ResponseInterface;

class SuppressionController extends BaseApiController
{
    private SuppressionService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new SuppressionService(new ChannelSuppressionModel(), new AuditLogger());
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    public function index(): ResponseInterface
    {
        $page    = (int) ($this->request->getVar('page') ?? 1);
        $perPage = min(100, (int) ($this->request->getVar('per_page') ?? 25));
        return $this->ok($this->service->list($this->tenantId(), $page, $perPage));
    }

    public function store(): ResponseInterface
    {
        $body = $this->input() ?: [];
        foreach (['channel', 'address', 'reason'] as $field) {
            if (empty($body[$field])) {
                return $this->fail("{$field} is required", 422);
            }
        }

        try {
            $reason = SuppressionReason::from((string) $body['reason']);
        } catch (\ValueError) {
            return $this->fail('Invalid suppression reason', 422);
        }

        try {
            $record = $this->service->suppress(
                $this->tenantId(),
                (string) $body['channel'],
                (string) $body['address'],
                $reason,
                (string) ($body['source'] ?? 'manual'),
                $this->userId(),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($record, 201);
    }

    public function destroy(string $id): ResponseInterface
    {
        $ok = $this->service->remove((int) $id, $this->tenantId(), $this->userId());
        if (!$ok) {
            return $this->fail('Not found', 404);
        }
        return $this->ok(['removed' => true]);
    }
}
