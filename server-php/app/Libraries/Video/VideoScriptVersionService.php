<?php

declare(strict_types=1);

namespace App\Libraries\Video;

/**
 * Phase 6 — Script version immutability enforcement.
 *
 * Script versions are immutable once created. This service enforces that
 * guarantee and validates that a version belongs to the expected script
 * before any workflow action is taken.
 *
 * Contract:
 * - Versions cannot be mutated after creation (content_json, submitted_by, etc.).
 * - approved_by and approved_at may be set exactly once when a version is approved.
 * - A new version must be created for any content revision.
 */
class VideoScriptVersionService
{
    public function __construct(
        private readonly VideoScriptRepository $scriptRepo,
    ) {}

    /**
     * Confirm that the given version belongs to the given script.
     *
     * @throws \InvalidArgumentException if the version does not belong to the script.
     */
    public function assertVersionBelongsToScript(int $versionId, int $scriptId): void
    {
        $version = $this->scriptRepo->getVersionById($versionId);
        if ($version === null) {
            throw new \InvalidArgumentException("Script version #{$versionId} not found");
        }
        if ((int) $version['script_id'] !== $scriptId) {
            throw new \InvalidArgumentException(
                "Version #{$versionId} does not belong to script #{$scriptId}"
            );
        }
    }

    /**
     * Stamp an approval on the current version (approved_by, approved_at).
     *
     * This is the only allowed mutation after creation, and may only happen once.
     *
     * @throws \LogicException if the version is already approved.
     */
    public function stampApproval(int $versionId, int $approverId): void
    {
        $version = $this->scriptRepo->getVersionById($versionId);
        if ($version === null) {
            throw new \InvalidArgumentException("Script version #{$versionId} not found");
        }
        if (! empty($version['approved_by'])) {
            throw new \LogicException("Version #{$versionId} is already approved");
        }
        $this->scriptRepo->stampVersionApproval($versionId, $approverId);
    }

    /**
     * Create a new immutable version for the script based on revised content.
     *
     * @param int   $scriptId    The parent script ID.
     * @param array $contentJson Full structured script content.
     * @param int   $actorId     The editor creating the new version.
     *
     * @return int The new version ID.
     */
    public function createRevision(int $scriptId, array $contentJson, int $actorId): int
    {
        $existing = $this->scriptRepo->listVersions($scriptId);
        $nextNum  = count($existing) + 1;

        return $this->scriptRepo->createVersion([
            'script_id'      => $scriptId,
            'version_number' => $nextNum,
            'content_json'   => $contentJson,
            'created_by'     => $actorId,
            'is_current'     => true,
        ]);
    }
}
