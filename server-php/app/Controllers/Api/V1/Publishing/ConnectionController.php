<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\AuditLogger;

class ConnectionController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->db->table('reach_publication_connections')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        // Never expose secret references to frontend
        foreach ($rows as &$row) {
            unset($row['secret_env_reference'], $row['signing_key_env_reference'], $row['key_id_env_reference']);
        }

        return $this->ok($rows);
    }

    public function healthCheck(string $connectionKey): \CodeIgniter\HTTP\ResponseInterface
    {
        $connection = $this->db->table('reach_publication_connections')
            ->where('connection_key', $connectionKey)
            ->get()->getRowArray();

        if (!$connection) {
            return $this->notFound('Connection not found');
        }

        $publisher = PublicSitePublisherFactory::make();
        $healthy   = $publisher->healthCheck();

        $status = $healthy ? 'healthy' : 'unhealthy';

        $this->db->table('reach_publication_connections')
            ->where('id', $connection['id'])
            ->update([
                'health_status'         => $status,
                'last_health_checked_at'=> date('Y-m-d H:i:s'),
                'last_health_error'     => $healthy ? null : 'Health check returned false',
                'updated_at'            => date('Y-m-d H:i:s'),
            ]);

        $actor = $this->request->actor ?? null;
        AuditLogger::log('publishing.health_checked', [
            'connection_key' => $connectionKey,
            'health_status'  => $status,
        ], $actor?->id);

        return $this->ok(['health_status' => $status]);
    }
}
