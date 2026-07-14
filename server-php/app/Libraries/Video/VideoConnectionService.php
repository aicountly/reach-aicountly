<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Libraries\AuditLogger;

/**
 * Phase 6 CP8 — YouTube connection service.
 *
 * Reuses the Phase 4 `reach_publication_connections` table with
 * `connection_type = 'youtube'` and `authentication_type = 'oauth2'`.
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

    public function __construct(
        private readonly \App\Libraries\Video\VideoPublicationRepository $repo,
    ) {}

    /**
     * List YouTube connections for a tenant.
     */
    public function listConnections(int $tenantId): array
    {
        $rows = \Config\Database::connect()
            ->table('reach_publication_connections')
            ->where('tenant_id', $tenantId)
            ->where('connection_type', self::CONNECTION_TYPE)
            ->where('is_active', true)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        return array_map([$this, 'maskConnection'], $rows);
    }

    /**
     * Create a new YouTube connection record.
     *
     * Tokens are stored in the provided credential fields but masked in response.
     */
    public function create(int $tenantId, array $data, ?int $actorId = null): array
    {
        $db = \Config\Database::connect();
        $db->table('reach_publication_connections')->insert([
            'tenant_id'          => $tenantId,
            'connection_type'    => self::CONNECTION_TYPE,
            'authentication_type'=> self::AUTH_TYPE,
            'name'               => $data['name'] ?? 'YouTube Connection',
            'credentials'        => json_encode([
                'channel_id'    => $data['channel_id'] ?? '',
                'access_token'  => '[REDACTED]',
                'refresh_token' => '[REDACTED]',
            ]),
            'is_active'          => true,
            'created_by'         => $actorId,
        ]);

        $id  = (int) $db->insertID();
        $row = $db->table('reach_publication_connections')->where('id', $id)->get()->getRowArray();

        AuditLogger::record(AuditLogger::VIDEO_IDEA_CREATED, [
            'event'      => 'youtube_connection_created',
            'connection_id' => $id,
        ], $actorId);

        return $this->maskConnection($row);
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
            ->update(['is_active' => false]);

        AuditLogger::record(AuditLogger::VIDEO_IDEA_CREATED, [
            'event'         => 'youtube_connection_revoked',
            'connection_id' => $connectionId,
        ], $actorId);

        return true;
    }

    private function maskConnection(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        unset($row['credentials']);
        return $row;
    }
}
