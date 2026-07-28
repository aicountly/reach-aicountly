<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\AuditLogger;

class ConnectionController extends BaseApiController
{
    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = $this->db();

            if (! $db->tableExists('reach_publication_connections')) {
                return $this->ok([]);
            }

            $rows = $db->table('reach_publication_connections')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            if (! is_array($rows)) {
                return $this->ok([]);
            }

            // Never expose secret references to frontend
            foreach ($rows as &$row) {
                unset($row['secret_env_reference'], $row['signing_key_env_reference'], $row['key_id_env_reference']);
            }
            unset($row);

            return $this->ok($rows);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectionController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Publishing UI.
            return $this->ok([]);
        }
    }

    public function healthCheck(string $connectionKey): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = $this->db();

            if (! $db->tableExists('reach_publication_connections')) {
                return $this->notFound('Connection not found');
            }

            $connection = $db->table('reach_publication_connections')
                ->where('connection_key', $connectionKey)
                ->get()
                ->getRowArray();
        } catch (\Throwable $e) {
            log_message('error', 'ConnectionController::healthCheck: ' . $e->getMessage());
            return $this->notFound('Connection not found');
        }

        if (! $connection) {
            return $this->notFound('Connection not found');
        }

        try {
            $publisher = PublicSitePublisherFactory::make();
            $healthy   = $publisher->healthCheck();
        } catch (\Throwable $e) {
            log_message('error', 'ConnectionController::healthCheck publisher: ' . $e->getMessage());
            $healthy = false;
        }

        $status = $healthy ? 'healthy' : 'unhealthy';

        try {
            $this->db()->table('reach_publication_connections')
                ->where('id', $connection['id'])
                ->update([
                    'health_status'           => $status,
                    'last_health_checked_at'  => date('Y-m-d H:i:s'),
                    'last_health_error'       => $healthy ? null : 'Health check returned false',
                    'updated_at'              => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectionController::healthCheck update: ' . $e->getMessage());
        }

        $actor = $this->request->actor ?? null;
        try {
            AuditLogger::record('publishing.health_checked', [
                'connection_key' => $connectionKey,
                'health_status'  => $status,
            ], $actor?->id);
        } catch (\Throwable $e) {
            log_message('error', 'ConnectionController::healthCheck audit: ' . $e->getMessage());
        }

        return $this->ok(['health_status' => $status]);
    }
}
