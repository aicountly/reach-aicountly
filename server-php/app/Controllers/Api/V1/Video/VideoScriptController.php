<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoProjectRepository;
use App\Libraries\Video\VideoScriptRepository;
use CodeIgniter\HTTP\ResponseInterface;

class VideoScriptController extends BaseApiController
{
    private VideoProjectRepository $projectRepo;
    private VideoScriptRepository  $scriptRepo;

    protected function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->projectRepo = new VideoProjectRepository(new \App\Models\Video\VideoProjectModel());
        $this->scriptRepo  = new VideoScriptRepository(
            new \App\Models\Video\VideoScriptModel(),
            new \App\Models\Video\VideoScriptVersionModel(),
            new \App\Models\Video\VideoSegmentModel(),
            new \App\Models\Video\VideoCaptionTrackModel(),
            new \App\Models\Video\VideoChapterMarkerModel(),
        );
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
        $script = $this->scriptRepo->findScriptByProjectId((int) $project['id']);
        if ($script === null) {
            return $this->fail('Script not found', 404);
        }
        $version = $this->scriptRepo->getCurrentVersion((int) $script['id']);
        if ($version !== null) {
            $script['current_version_detail'] = $this->scriptRepo->getVersionWithDetail((int) $version['id']);
        }
        return $this->ok($script);
    }

    public function store(string $projectUuid): ResponseInterface
    {
        $project = $this->findProject($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }
        $body = $this->input();
        $scriptId = $this->scriptRepo->createScript([
            'project_id'     => (int) $project['id'],
            'workflow_status' => 'draft',
            'created_by'     => $this->userId(),
        ]);
        $versionId = $this->scriptRepo->createVersion([
            'script_id'      => $scriptId,
            'version_number' => 1,
            'content_json'   => $body['content_json'] ?? [],
            'created_by'     => $this->userId(),
        ]);
        $this->scriptRepo->updateScript($scriptId, ['current_version' => 1]);
        $script = $this->scriptRepo->findScriptByProjectId((int) $project['id']);
        return $this->ok($script, 201);
    }

    public function generate(string $projectUuid): ResponseInterface
    {
        $project = $this->findProject($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }

        $body      = $this->input() ?: [];
        $overrides = [
            'target_duration_seconds' => (int) ($body['target_duration_seconds'] ?? 120),
            'style_notes'             => $body['style_notes'] ?? '',
        ];

        try {
            $service = new \App\Libraries\Video\VideoScriptGenerationService(
                $this->projectRepo,
                $this->scriptRepo,
            );
            $result = $service->requestGeneration($projectUuid, $this->userId(), $overrides);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\LogicException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->fail('Generation failed: ' . $e->getMessage(), 500);
        }

        return $this->ok($result, 202);
    }

    public function submit(string $projectUuid): ResponseInterface
    {
        return $this->fail('Script submit requires CP5 implementation', 501);
    }

    public function approve(string $projectUuid): ResponseInterface
    {
        return $this->fail('Script approval requires CP5 implementation', 501);
    }

    public function reject(string $projectUuid): ResponseInterface
    {
        return $this->fail('Script rejection requires CP5 implementation', 501);
    }

    public function requestChanges(string $projectUuid): ResponseInterface
    {
        return $this->fail('Changes request requires CP5 implementation', 501);
    }

    public function versions(string $projectUuid): ResponseInterface
    {
        $project = $this->findProject($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }
        $script = $this->scriptRepo->findScriptByProjectId((int) $project['id']);
        if ($script === null) {
            return $this->ok(['data' => []]);
        }
        return $this->ok(['data' => $this->scriptRepo->listVersions((int) $script['id'])]);
    }

    public function versionDetail(string $projectUuid, int $versionNumber): ResponseInterface
    {
        $project = $this->findProject($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }
        $script = $this->scriptRepo->findScriptByProjectId((int) $project['id']);
        if ($script === null) {
            return $this->fail('Script not found', 404);
        }
        $version = $this->scriptRepo->getVersionByNumber((int) $script['id'], $versionNumber);
        if ($version === null) {
            return $this->fail('Version not found', 404);
        }
        return $this->ok($this->scriptRepo->getVersionWithDetail((int) $version['id']));
    }
}
