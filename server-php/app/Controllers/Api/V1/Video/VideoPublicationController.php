<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoPublicationService;
use App\Libraries\Video\VideoPublicationRepository;
use App\Libraries\Video\VideoProjectRepository;
use App\Models\Video\VideoPublicationProfileModel;
use App\Models\Video\VideoProjectModel;
use CodeIgniter\HTTP\ResponseInterface;

class VideoPublicationController extends BaseApiController
{
    private VideoPublicationService    $service;
    private VideoProjectRepository     $projectRepo;
    private VideoPublicationRepository $pubRepo;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->pubRepo     = new VideoPublicationRepository(new VideoPublicationProfileModel());
        $this->projectRepo = new VideoProjectRepository(new VideoProjectModel());
        $this->service     = new VideoPublicationService($this->pubRepo, $this->projectRepo);
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    private function findProject(string $uuid): ?array
    {
        $p = $this->projectRepo->findByUuid($uuid);
        if ($p === null || (int) $p['tenant_id'] !== $this->tenantId()) {
            return null;
        }
        return $p;
    }

    public function show(string $projectUuid): ResponseInterface
    {
        $project = $this->findProject($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }
        $profile = $this->pubRepo->findProfileByProject((int) $project['id'], 'youtube');
        return $this->ok($profile ?? []);
    }

    public function publish(string $projectUuid): ResponseInterface
    {
        $project = $this->findProject($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }

        $body         = $this->input() ?: [];
        $connectionId = (int) ($body['connection_id'] ?? 0);
        if ($connectionId === 0) {
            return $this->fail('connection_id is required', 422);
        }

        $renderJobUuid = $body['render_job_uuid'] ?? null;
        if ($renderJobUuid === null) {
            return $this->fail('render_job_uuid is required', 422);
        }

        $db        = \Config\Database::connect();
        $renderJob = $db->table('reach_video_render_jobs')
            ->where('uuid', $renderJobUuid)
            ->get()->getRowArray();

        if ($renderJob === null) {
            return $this->fail('Render job not found', 404);
        }

        $metadata = [
            'yt_title'       => $body['yt_title'] ?? $project['title'],
            'yt_description' => $body['yt_description'] ?? '',
            'yt_tags'        => $body['yt_tags'] ?? [],
            'yt_category'    => $body['yt_category'] ?? '22',
            'yt_privacy'     => $body['yt_privacy'] ?? 'private',
        ];

        $profile = $this->service->getOrCreateProfile(
            (int) $project['id'],
            $this->tenantId(),
            $metadata,
            $this->userId()
        );

        try {
            $result = $this->service->publish(
                $project,
                $renderJob,
                $profile,
                $connectionId,
                $this->userId(),
            );
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($result, 202);
    }

    public function retry(string $projectUuid): ResponseInterface
    {
        return $this->fail('Publication retry will be implemented in a future release', 501);
    }

    public function cancel(string $projectUuid): ResponseInterface
    {
        return $this->fail('Publication cancel will be implemented in a future release', 501);
    }

    public function list(): ResponseInterface
    {
        $page    = (int) ($this->request->getVar('page') ?? 1);
        $perPage = (int) ($this->request->getVar('per_page') ?? 25);
        $result  = $this->service->listPublications($this->tenantId(), $page, $perPage);
        return $this->ok($result);
    }
}
