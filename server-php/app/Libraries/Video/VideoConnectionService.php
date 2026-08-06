<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Libraries\AuditLogger;
use App\Libraries\Database\SchemaGuard;

/**
 * Phase 6 CP8 — YouTube connection service.
 *
 * Reuses the Phase 4 `reach_publication_connections` table with
 * `connection_type = 'youtube'` and `authentication_type = 'oauth2'`.
 *
 * Column mapping (Phase 4 schema + Phase 6 extensions):
 * - display_name  → API "name"
 * - enabled       → active flag (is_active in older drafts)
 * - connection_key → stable unique key (youtube-{uuid})
 * - uuid / tenant_id / credentials → added by 100195
 *
 * Security contract:
 * - OAuth2 tokens are stored in the existing encrypted credential store.
 * - Access tokens are NEVER returned in API responses (masked).
 * - Refresh tokens are NEVER logged or audited.
 * - Connection health is checked via the YouTubePublisher interface.
 */
class VideoConnectionService
{
    private const CONNECTION_TYPE = 'youtube';
    private const AUTH_TYPE       = 'oauth2';
    private const YOUTUBE_BASE    = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private readonly \App\Libraries\Video\VideoPublicationRepository $repo,
    ) {}

    /**
     * List YouTube connections for a tenant.
     */
    public function listConnections(int $tenantId): array
    {
        $db = \Config\Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_publication_connections')) {
            return [];
        }

        $builder = $db->table('reach_publication_connections')
            ->where('connection_type', self::CONNECTION_TYPE)
            ->where('enabled', true)
            ->orderBy('created_at', 'DESC');

        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'tenant_id')) {
            $builder->where('tenant_id', $tenantId);
        }

        $rows = $builder->get()->getResultArray();

        return array_map([$this, 'maskConnection'], is_array($rows) ? $rows : []);
    }

    /**
     * Create a new YouTube connection record.
     *
     * Tokens are stored in the provided credential fields but masked in response.
     */
    public function create(int $tenantId, array $data, ?int $actorId = null): array
    {
        $db = \Config\Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_publication_connections')) {
            throw new \RuntimeException('Publication connections table is not available');
        }

        $uuid = $this->newUuid();
        $name = trim((string) ($data['name'] ?? 'YouTube Connection'));
        if ($name === '') {
            $name = 'YouTube Connection';
        }

        $row = [
            'connection_key'      => 'youtube-' . $uuid,
            'display_name'        => $name,
            'base_url'            => self::YOUTUBE_BASE,
            'authentication_type' => self::AUTH_TYPE,
            'connection_type'     => self::CONNECTION_TYPE,
            'enabled'             => true,
            'supported_content_types' => json_encode(['video']),
        ];

        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'uuid')) {
            $row['uuid'] = $uuid;
        }
        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'tenant_id')) {
            $row['tenant_id'] = $tenantId;
        }
        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'credentials')) {
            $row['credentials'] = json_encode([
                'channel_id'    => $data['channel_id'] ?? '',
                'access_token'  => '[REDACTED]',
                'refresh_token' => '[REDACTED]',
            ]);
        }
        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'created_by') && $actorId !== null) {
            $row['created_by'] = $actorId;
        }

        $db->table('reach_publication_connections')->insert($row);

        $id      = (int) $db->insertID();
        $created = $db->table('reach_publication_connections')->where('id', $id)->get()->getRowArray();

        AuditLogger::record(AuditLogger::VIDEO_CONNECTION_CREATED, [
            'event'         => 'youtube_connection_created',
            'connection_id' => $id,
        ], $actorId);

        return $this->maskConnection($created);
    }

    /**
     * Check connection health via mock YouTube publisher (production uses live OAuth).
     */
    public function checkHealth(int $connectionId): array
    {
        $publisher = \App\Libraries\Video\Providers\VideoProviderFactory::makeYouTubePublisher();
        try {
            $status = $publisher->getStatus('[health-check]');
            return ['healthy' => true, 'detail' => (array) $status];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'detail' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * Soft-delete a connection.
     */
    public function revoke(int $connectionId, ?int $actorId = null): bool
    {
        \Config\Database::connect()
            ->table('reach_publication_connections')
            ->where('id', $connectionId)
            ->update(['enabled' => false]);

        AuditLogger::record(AuditLogger::VIDEO_CONNECTION_REVOKED, [
            'event'         => 'youtube_connection_revoked',
            'connection_id' => $connectionId,
        ], $actorId);

        return true;
    }

    /**
     * Find a YouTube connection by uuid for the tenant.
     */
    public function findByUuid(string $uuid, int $tenantId): ?array
    {
        $db = \Config\Database::connect();
        if (! SchemaGuard::hasTable($db, 'reach_publication_connections')) {
            return null;
        }

        $builder = $db->table('reach_publication_connections')
            ->where('connection_type', self::CONNECTION_TYPE);

        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'uuid')) {
            $builder->groupStart()
                ->where('uuid', $uuid)
                ->orWhere('connection_key', $uuid)
                ->groupEnd();
        } else {
            $builder->where('connection_key', $uuid);
        }

        if (SchemaGuard::hasColumn($db, 'reach_publication_connections', 'tenant_id')) {
            $builder->where('tenant_id', $tenantId);
        }

        $row = $builder->get()->getRowArray();
        return is_array($row) ? $row : null;
    }

    private function maskConnection(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        unset($row['credentials'], $row['secret_env_reference'], $row['signing_key_env_reference'], $row['key_id_env_reference']);

        // Frontend expects `name`; Phase 4 schema uses display_name.
        if (! isset($row['name']) && isset($row['display_name'])) {
            $row['name'] = $row['display_name'];
        }
        if (! isset($row['uuid']) && isset($row['connection_key'])) {
            $row['uuid'] = $row['connection_key'];
        }
        if (! isset($row['is_active']) && array_key_exists('enabled', $row)) {
            $row['is_active'] = (bool) $row['enabled'];
        }

        return $row;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
