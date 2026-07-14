<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoProjectRepository;
use App\Libraries\Video\VideoProjectService;
use CodeIgniter\HTTP\ResponseInterface;

class VideoProjectController extends BaseApiController
{
    private VideoProjectRepository $repo;
    private VideoProjectService    $service;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);

        $model    = new \App\Models\Video\VideoProjectModel();
        $this->repo = new VideoProjectRepository($model);

        $validator   = new \App\Libraries\Video\VideoLifecycleValidator();
        $auditLogger = \Config\Services::auditLogger();
        $this->service = new VideoProjectService($this->repo, $validator, $auditLogger);
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    public function index(): ResponseInterface
    {
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $perPage = min((int) ($this->request->getGet('per_page') ?? 25), 100);
        $filters = [
            'status' => $this->request->getGet('status') ?? '',
            'search' => $this->request->getGet('search') ?? '',
        ];

        $result = $this->repo->listForTenant($this->tenantId(), $filters, $page, $perPage);
        return $this->ok($result);
    }

    public function show(string $uuid): ResponseInterface
    {
        $project = $this->repo->findByUuid($uuid);
        if ($project === null || (int) $project['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        return $this->ok($project);
    }

    public function store(): ResponseInterface
    {
        $body = $this->input();
        if (empty($body['title'])) {
            return $this->fail('title is required', 422);
        }
        $body['tenant_id'] = $this->tenantId();
        $project = $this->service->createProject($body, $this->userId());
        return $this->ok($project, 201);
    }

    public function update(string $uuid): ResponseInterface
    {
        $project = $this->repo->findByUuid($uuid);
        if ($project === null || (int) $project['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        try {
            $updated = $this->service->updateProject($project, $this->input(), $this->userId());
            return $this->ok($updated);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function cancel(string $uuid): ResponseInterface
    {
        $project = $this->repo->findByUuid($uuid);
        if ($project === null || (int) $project['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        $body   = $this->input();
        $reason = $body['reason'] ?? '';
        try {
            $updated = $this->service->cancelProject($project, $reason, $this->userId());
            return $this->ok($updated);
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}
