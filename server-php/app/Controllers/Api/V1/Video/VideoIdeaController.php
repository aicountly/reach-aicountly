<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoIdeaRepository;
use App\Libraries\Video\VideoIdeationService;
use App\Libraries\Video\VideoScoringService;
use CodeIgniter\HTTP\ResponseInterface;

class VideoIdeaController extends BaseApiController
{
    private VideoIdeaRepository  $repo;
    private VideoIdeationService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);

        $auditLogger = \Config\Services::auditLogger();
        $validator   = new \App\Libraries\Video\VideoLifecycleValidator();
        $ideaModel   = new \App\Models\Video\VideoIdeaModel();
        $ideaSourceModel = new \App\Models\Video\VideoIdeaSourceModel();
        $projectModel    = new \App\Models\Video\VideoProjectModel();

        $this->repo    = new VideoIdeaRepository($ideaModel, $ideaSourceModel);
        $projectRepo   = new \App\Libraries\Video\VideoProjectRepository($projectModel);

        $this->service = new VideoIdeationService(
            $this->repo,
            $projectRepo,
            $validator,
            $auditLogger,
        );
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
            'status'    => $this->request->getGet('status') ?? '',
            'min_score' => $this->request->getGet('min_score'),
            'search'    => $this->request->getGet('search') ?? '',
        ];

        $result = $this->repo->listForTenant($this->tenantId(), $filters, $page, $perPage);
        return $this->ok($result);
    }

    public function show(string $uuid): ResponseInterface
    {
        $idea = $this->repo->findByUuid($uuid);
        if ($idea === null || (int) $idea['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        $idea['sources'] = $this->repo->listSources((int) $idea['id']);
        return $this->ok($idea);
    }

    public function store(): ResponseInterface
    {
        $body = $this->input();
        if (empty($body['title'])) {
            return $this->fail('title is required', 422);
        }

        $body['tenant_id'] = $this->tenantId();
        $idea = $this->service->createIdea($body, $this->userId());
        return $this->ok($idea, 201);
    }

    public function update(string $uuid): ResponseInterface
    {
        $idea = $this->repo->findByUuid($uuid);
        if ($idea === null || (int) $idea['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }

        $body    = $this->input();
        $allowed = ['title', 'summary', 'source_type', 'source_ref_id'];
        $update  = array_intersect_key($body, array_flip($allowed));
        if (! empty($update)) {
            $this->repo->update((int) $idea['id'], $update);
        }
        return $this->ok($this->repo->findById((int) $idea['id']));
    }

    public function accept(string $uuid): ResponseInterface
    {
        $idea = $this->repo->findByUuid($uuid);
        if ($idea === null || (int) $idea['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        try {
            $updated = $this->service->acceptIdea($idea, $this->userId());
            return $this->ok($updated);
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function reject(string $uuid): ResponseInterface
    {
        $idea = $this->repo->findByUuid($uuid);
        if ($idea === null || (int) $idea['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        $body   = $this->input();
        $reason = $body['reason'] ?? '';
        try {
            $updated = $this->service->rejectIdea($idea, $reason, $this->userId());
            return $this->ok($updated);
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function convert(string $uuid): ResponseInterface
    {
        $idea = $this->repo->findByUuid($uuid);
        if ($idea === null || (int) $idea['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        try {
            $project = $this->service->convertToProject($idea, $this->userId());
            return $this->ok($project, 201);
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function addSource(string $uuid): ResponseInterface
    {
        $idea = $this->repo->findByUuid($uuid);
        if ($idea === null || (int) $idea['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        $body = $this->input();
        if (empty($body['source_type'])) {
            return $this->fail('source_type is required', 422);
        }
        $sources = $this->service->addSource((int) $idea['id'], $body, $this->userId());
        return $this->ok($sources, 201);
    }
}
