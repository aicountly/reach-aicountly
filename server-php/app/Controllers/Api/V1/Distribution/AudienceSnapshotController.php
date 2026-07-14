<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\AudienceSnapshotService;
use App\Models\Distribution\AudienceSnapshotModel;
use App\Models\Distribution\AudienceRecipientModel;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\HTTP\ResponseInterface;

class AudienceSnapshotController extends BaseApiController
{
    private AudienceSnapshotService $service;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new AudienceSnapshotService(
            new AudienceSnapshotModel(),
            new AudienceRecipientModel(),
            new ChannelSuppressionModel(),
            new AuditLogger(),
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    public function show(string $campaignId): ResponseInterface
    {
        $snapshot = $this->service->get((int) $campaignId, $this->tenantId());
        if ($snapshot === null) {
            return $this->fail('Not found', 404);
        }
        return $this->ok($snapshot);
    }

    public function create(string $campaignId): ResponseInterface
    {
        $body = $this->input() ?: [];
        try {
            $snapshot = $this->service->createSnapshot(
                (int) $campaignId,
                $this->tenantId(),
                (string) ($body['channel'] ?? 'email'),
                isset($body['campaign_version_id']) ? (int) $body['campaign_version_id'] : null,
                $body['criteria'] ?? null,
                $this->userId(),
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($snapshot, 201);
    }

    public function freeze(string $campaignId): ResponseInterface
    {
        $snapshot = $this->service->get((int) $campaignId, $this->tenantId());
        if ($snapshot === null) {
            return $this->fail('Not found', 404);
        }
        try {
            $frozen = $this->service->freeze((int) $snapshot['id'], $this->tenantId(), $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($frozen);
    }
}
