<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Models\Video\VideoScriptModel;
use App\Models\Video\VideoScriptVersionModel;
use App\Models\Video\VideoSegmentModel;
use App\Models\Video\VideoCaptionTrackModel;
use App\Models\Video\VideoChapterMarkerModel;

class VideoScriptRepository
{
    public function __construct(
        private readonly VideoScriptModel        $scriptModel,
        private readonly VideoScriptVersionModel $versionModel,
        private readonly VideoSegmentModel       $segmentModel,
        private readonly VideoCaptionTrackModel  $captionModel,
        private readonly VideoChapterMarkerModel $chapterModel,
    ) {}

    public function findScriptByProjectId(int $projectId): ?array
    {
        return $this->scriptModel->findByProjectId($projectId);
    }

    public function findScriptByUuid(string $uuid): ?array
    {
        return $this->scriptModel->findByUuid($uuid);
    }

    public function createScript(array $data): int
    {
        $this->scriptModel->insert($data);
        return (int) $this->scriptModel->insertID();
    }

    public function updateScript(int $id, array $data): bool
    {
        return (bool) $this->scriptModel->update($id, $data);
    }

    public function createVersion(array $data): int
    {
        $this->versionModel->clearCurrentFlag((int) $data['script_id']);
        $data['is_current'] = true;
        $this->versionModel->insert($data);
        return (int) $this->versionModel->insertID();
    }

    public function findVersionByUuid(string $uuid): ?array
    {
        return $this->versionModel->findByUuid($uuid);
    }

    public function getCurrentVersion(int $scriptId): ?array
    {
        return $this->versionModel->getCurrentVersion($scriptId);
    }

    public function listVersions(int $scriptId): array
    {
        return $this->versionModel->listVersions($scriptId);
    }

    public function getVersionByNumber(int $scriptId, int $versionNumber): ?array
    {
        return $this->versionModel->getByVersionNumber($scriptId, $versionNumber);
    }

    public function updateVersion(int $versionId, array $data): bool
    {
        return (bool) $this->versionModel->update($versionId, $data);
    }

    public function getVersionWithDetail(int $versionId): ?array
    {
        $version = $this->versionModel->find($versionId);
        if ($version === null) {
            return null;
        }
        $version['segments']  = $this->segmentModel->listForVersion($versionId);
        $version['captions']  = $this->captionModel->listForVersion($versionId);
        $version['chapters']  = $this->chapterModel->listForVersion($versionId);
        return $version;
    }
}
