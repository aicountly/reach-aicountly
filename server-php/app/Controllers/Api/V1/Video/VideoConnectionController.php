<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoConnectionService;
use App\Libraries\Video\VideoPublicationRepository;
use App\Models\Video\VideoPublicationProfileModel;
use CodeIgniter\HTTP\ResponseInterface;

class VideoConnectionController extends BaseApiController
{
    private VideoConnectionService $service;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new VideoConnectionService(
            new VideoPublicationRepository(new VideoPublicationProfileModel())
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    public function index(): ResponseInterface
    {
        $connections = $this->service->listConnections($this->tenantId());
        return $this->ok(['data' => $connections]);
    }

    public function store(): ResponseInterface
    {
        $body = $this->input() ?: [];
        if (empty($body['name'])) {
            return $this->fail('name is required', 422);
        }
        try {
            $conn = $this->service->create($this->tenantId(), $body, $this->userId());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($conn, 201);
    }

    public function show(string $uuid): ResponseInterface
    {
        $db  = \Config\Database::connect();
        $row = $db->table('reach_publication_connections')
            ->where('uuid', $uuid)
            ->where('tenant_id', $this->tenantId())
            ->get()->getRowArray();
        if ($row === null) {
            return $this->fail('Not found', 404);
        }
        unset($row['credentials']);
        return $this->ok($row);
    }

    public function revoke(string $uuid): ResponseInterface
    {
        $db  = \Config\Database::connect();
        $row = $db->table('reach_publication_connections')
            ->where('uuid', $uuid)
            ->where('tenant_id', $this->tenantId())
            ->get()->getRowArray();
        if ($row === null) {
            return $this->fail('Not found', 404);
        }
        $this->service->revoke((int) $row['id'], $this->userId());
        return $this->ok(['revoked' => true]);
    }

    public function health(string $uuid): ResponseInterface
    {
        $db  = \Config\Database::connect();
        $row = $db->table('reach_publication_connections')
            ->where('uuid', $uuid)
            ->where('tenant_id', $this->tenantId())
            ->get()->getRowArray();
        if ($row === null) {
            return $this->fail('Not found', 404);
        }
        $health = $this->service->checkHealth((int) $row['id']);
        return $this->ok($health);
    }
}
