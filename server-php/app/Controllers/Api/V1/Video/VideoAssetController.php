<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Assets recorded against a legacy video project (reach_video_assets).
 * Uploading binaries here is not supported: the automated pipeline creates
 * its own voice-overs, stills and renders (see VideoPipelineController).
 */
class VideoAssetController extends BaseApiController
{
    private const COLUMNS = 'uuid, asset_type, mime_type, file_extension, file_size_bytes, checksum_sha256, status, rejection_reason, created_at, updated_at';

    public function listForProject(string $projectUuid): ResponseInterface
    {
        $db      = \Config\Database::connect();
        $project = $this->project($projectUuid);
        if ($project === null) {
            return $this->fail('Not found', 404);
        }
        $rows = $db->table('reach_video_assets')->select(self::COLUMNS)
            ->where('project_id', (int) $project['id'])->orderBy('created_at', 'DESC')->get()->getResultArray();

        return $this->ok(['items' => $rows, 'total' => count($rows)]);
    }

    public function upload(string $projectUuid): ResponseInterface
    {
        return $this->fail('Uploading video assets is not supported. The automated pipeline generates voice-overs, visuals and renders itself.', 409);
    }

    public function show(string $assetUuid): ResponseInterface
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $assetUuid)) {
            return $this->fail('Not found', 404);
        }
        $row = \Config\Database::connect()->table('reach_video_assets')->select(self::COLUMNS . ', project_id, tenant_id')
            ->where('uuid', $assetUuid)->get()->getRowArray();
        if (! $row || (int) $row['tenant_id'] !== (int) ($this->user()['tenant_id'] ?? 0)) {
            return $this->fail('Not found', 404);
        }
        unset($row['tenant_id']);

        return $this->ok($row);
    }

    private function project(string $uuid): ?array
    {
        if (! preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            return null;
        }
        $row = \Config\Database::connect()->table('reach_video_projects')->select('id, tenant_id')->where('uuid', $uuid)->get()->getRowArray();
        return $row && (int) $row['tenant_id'] === (int) ($this->user()['tenant_id'] ?? 0) ? $row : null;
    }
}
