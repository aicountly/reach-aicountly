<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Libraries\Video\VideoAssetGuard;
use App\Libraries\Video\VideoRenderJobRepository;
use App\Libraries\AuditLogger;
use App\Models\Video\VideoAssetModel;

/**
 * Phase 6 — Video asset management service.
 *
 * Wraps the VideoAssetGuard (MIME/size/SSRF security layer) with
 * persistence logic for storing approved asset metadata.
 *
 * Security contract:
 * - Only files passing the MIME allowlist and size limit are stored.
 * - Executable content types are always rejected.
 * - Storage keys are tenant-isolated.
 * - Remote URLs are blocked unless passing UrlPolicy SSRF allowlist.
 */
class VideoAssetService
{
    private VideoAssetGuard $guard;

    public function __construct(
        private readonly VideoAssetModel $assetModel = new VideoAssetModel(),
    ) {
        $this->guard = new VideoAssetGuard();
    }

    /**
     * Validate and register an uploaded asset for a video project.
     *
     * @param int    $projectId   The owning project ID.
     * @param int    $tenantId    The tenant scope.
     * @param string $tmpPath     Absolute path to the uploaded temp file.
     * @param string $originalName Original filename from the upload.
     * @param int    $byteSize    File size in bytes.
     * @param int|null $actorId   Uploader actor ID.
     *
     * @return array The stored asset record.
     */
    public function registerUpload(
        int    $projectId,
        int    $tenantId,
        string $tmpPath,
        string $originalName,
        int    $byteSize,
        ?int   $actorId = null,
    ): array {
        // Security validation via guard
        $validated = $this->guard->validate($tmpPath, $originalName, $byteSize);

        if (! $validated['ok']) {
            throw new \InvalidArgumentException(
                'Asset upload rejected: ' . ($validated['reason'] ?? 'security policy violation')
            );
        }

        $storageKey = $this->guard->generateStorageKey($tenantId, $validated['extension']);

        $id = (int) $this->assetModel->insert([
            'project_id'   => $projectId,
            'tenant_id'    => $tenantId,
            'asset_type'   => $validated['asset_type'] ?? 'video',
            'mime_type'    => $validated['mime_type'],
            'extension'    => $validated['extension'],
            'storage_key'  => $storageKey,
            'size_bytes'   => $byteSize,
            'checksum'     => hash_file('sha256', $tmpPath),
            'status'       => 'ready',
            'created_by'   => $actorId,
        ]);

        AuditLogger::record(AuditLogger::VIDEO_IDEA_CREATED, [
            'event'      => 'asset_uploaded',
            'project_id' => $projectId,
            'asset_id'   => $id,
            'mime_type'  => $validated['mime_type'],
            'size_bytes' => $byteSize,
        ], $actorId);

        return $this->assetModel->find($id);
    }

    /**
     * List assets for a project.
     */
    public function listForProject(int $projectId): array
    {
        return $this->assetModel
            ->where('project_id', $projectId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    /**
     * Find a single asset by UUID.
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->assetModel->findByUuid($uuid);
    }
}
