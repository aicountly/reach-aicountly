<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoProjectRepository;
use App\Libraries\Video\VideoRenderJobRepository;
use App\Libraries\Video\VideoRenderJobService;
use App\Models\Video\VideoProjectModel;
use App\Models\Video\VideoRenderJobModel;
use App\Models\Video\VideoRenderAttemptModel;
use App\Models\Video\VideoRenderProfileModel;
use CodeIgniter\HTTP\ResponseInterface;

class VideoRenderController extends BaseApiController
{
    private VideoProjectRepository   $projectRepo;
    private VideoRenderJobRepository $jobRepo;
    private VideoRenderProfileModel  $profileModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->projectRepo  = new VideoProjectRepository(new VideoProjectModel());
        $this->jobRepo      = new VideoRenderJobRepository(new VideoRenderJobModel(), new VideoRenderAttemptModel());
        $this->profileModel = new VideoRenderProfileModel();
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    private function renderService(): VideoRenderJobService
    {
        return new VideoRenderJobService($this->jobRepo, $this->projectRepo);
    }

    public function queue(string $projectUuid): ResponseInterface
    {
        $project = $this->projectRepo->findByUuid($projectUuid);
        if ($project === null || (int) $project['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }

        $body            = $this->input() ?: [];
        $scriptVersionId = (int) ($body['script_version_id'] ?? 0);
        $renderProfileId = isset($body['render_profile_id']) ? (int) $body['render_profile_id'] : null;

        if ($scriptVersionId === 0) {
            return $this->fail('script_version_id is required', 422);
        }

        try {
            $job = $this->renderService()->queue(
                $project,
                $scriptVersionId,
                $renderProfileId,
                $this->userId(),
            );
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($job, 201);
    }

    public function showJob(string $jobUuid): ResponseInterface
    {
        $job = $this->jobRepo->findByUuid($jobUuid);
        if ($job === null) {
            return $this->fail('Not found', 404);
        }
        $project = $this->projectRepo->findById((int) $job['project_id']);
        if ($project === null || (int) $project['tenant_id'] !== $this->tenantId()) {
            return $this->fail('Not found', 404);
        }
        return $this->ok($job);
    }

    public function cancel(string $jobUuid): ResponseInterface
    {
        $job = $this->jobRepo->findByUuid($jobUuid);
        if ($job === null) {
            return $this->fail('Not found', 404);
        }
        try {
            $result = $this->renderService()->cancel($job, $this->userId());
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function retry(string $jobUuid): ResponseInterface
    {
        $job = $this->jobRepo->findByUuid($jobUuid);
        if ($job === null) {
            return $this->fail('Not found', 404);
        }
        try {
            $result = $this->renderService()->retry($job, $this->userId());
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    // -------------------------------------------------------------------------
    // Render profiles
    // -------------------------------------------------------------------------

    public function listProfiles(): ResponseInterface
    {
        $profiles = $this->profileModel
            ->where('is_active', true)
            ->orderBy('name', 'ASC')
            ->findAll();
        return $this->ok(['data' => $profiles]);
    }

    public function createProfile(): ResponseInterface
    {
        $body = $this->input() ?: [];
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            return $this->fail('name is required', 422);
        }
        $id = $this->profileModel->insert([
            'name'        => $name,
            'description' => $body['description'] ?? null,
            'resolution'  => $body['resolution'] ?? '1920x1080',
            'frame_rate'  => (int) ($body['frame_rate'] ?? 30),
            'bitrate_kbps'=> (int) ($body['bitrate_kbps'] ?? 4000),
            'format'      => $body['format'] ?? 'mp4',
            'is_default'  => (bool) ($body['is_default'] ?? false),
            'is_active'   => true,
        ]);
        return $this->ok($this->profileModel->find($id), 201);
    }

    public function showProfile(string $uuid): ResponseInterface
    {
        $profile = $this->profileModel->findByUuid($uuid);
        if ($profile === null) {
            return $this->fail('Not found', 404);
        }
        return $this->ok($profile);
    }

    public function updateProfile(string $uuid): ResponseInterface
    {
        $profile = $this->profileModel->findByUuid($uuid);
        if ($profile === null) {
            return $this->fail('Not found', 404);
        }
        $body = $this->input() ?: [];
        $this->profileModel->update((int) $profile['id'], $body);
        return $this->ok($this->profileModel->find((int) $profile['id']));
    }

    public function deleteProfile(string $uuid): ResponseInterface
    {
        $profile = $this->profileModel->findByUuid($uuid);
        if ($profile === null) {
            return $this->fail('Not found', 404);
        }
        $this->profileModel->update((int) $profile['id'], ['is_active' => false]);
        return $this->ok(['deleted' => true]);
    }
}
