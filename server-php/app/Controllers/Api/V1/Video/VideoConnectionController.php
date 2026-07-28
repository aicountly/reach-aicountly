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

    public function initController(
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
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_publication_connections')) {
                return $this->ok(['data' => []]);
            }

            $connections = $this->service->listConnections($this->tenantId());
            return $this->ok(['data' => is_array($connections) ? $connections : []]);
        } catch (\Throwable $e) {
            log_message('error', 'VideoConnectionController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Video UI.
            return $this->ok(['data' => []]);
        }
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
        try {
            $row = $this->service->findByUuid($uuid, $this->tenantId());
        } catch (\Throwable $e) {
            log_message('error', 'VideoConnectionController::show: ' . $e->getMessage());
            return $this->fail('Not found', 404);
        }
        if ($row === null) {
            return $this->fail('Not found', 404);
        }
        unset($row['credentials'], $row['secret_env_reference'], $row['signing_key_env_reference'], $row['key_id_env_reference']);
        if (! isset($row['name']) && isset($row['display_name'])) {
            $row['name'] = $row['display_name'];
        }
        return $this->ok($row);
    }

    public function revoke(string $uuid): ResponseInterface
    {
        try {
            $row = $this->service->findByUuid($uuid, $this->tenantId());
        } catch (\Throwable $e) {
            log_message('error', 'VideoConnectionController::revoke: ' . $e->getMessage());
            return $this->fail('Not found', 404);
        }
        if ($row === null) {
            return $this->fail('Not found', 404);
        }
        $this->service->revoke((int) $row['id'], $this->userId());
        return $this->ok(['revoked' => true]);
    }

    public function health(string $uuid): ResponseInterface
    {
        try {
            $row = $this->service->findByUuid($uuid, $this->tenantId());
        } catch (\Throwable $e) {
            log_message('error', 'VideoConnectionController::health: ' . $e->getMessage());
            return $this->fail('Not found', 404);
        }
        if ($row === null) {
            return $this->fail('Not found', 404);
        }
        $health = $this->service->checkHealth((int) $row['id']);
        return $this->ok($health);
    }
}
