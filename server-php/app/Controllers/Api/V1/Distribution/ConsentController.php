<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\ConsentService;
use App\Models\Distribution\ChannelConsentModel;
use CodeIgniter\HTTP\ResponseInterface;

class ConsentController extends BaseApiController
{
    private ConsentService $service;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new ConsentService(new ChannelConsentModel(), new AuditLogger());
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
        $required = ['subject_type', 'subject_id', 'channel', 'purpose', 'source'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->fail("{$field} is required", 422);
            }
        }
        try {
            $record = $this->service->grant(
                $this->tenantId(),
                (string) $body['subject_type'],
                (int) $body['subject_id'],
                (string) $body['channel'],
                (string) $body['purpose'],
                (string) $body['source'],
                $body['proof_reference'] ?? null,
                $this->userId(),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($record, 201);
    }

    public function destroy(string $id): ResponseInterface
    {
        $ok = $this->service->revoke((int) $id, $this->tenantId(), $this->userId());
        if (!$ok) {
            return $this->fail('Not found', 404);
        }
        return $this->ok(['revoked' => true]);
    }
}
