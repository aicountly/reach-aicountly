<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\Intelligence\SitemapSnapshotService;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\SitemapSnapshotModel;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class SitemapController extends BaseApiController
{
    private ?SitemapSnapshotService $sitemapService = null;

    private function service(): SitemapSnapshotService
    {
        if ($this->sitemapService === null) {
            $this->sitemapService = new SitemapSnapshotService(
                new ContentIdentityModel(),
                new SitemapSnapshotModel(),
                new AuditLogger()
            );
        }

        return $this->sitemapService;
    }

    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_sitemap_snapshots')) {
                return $this->response->setJSON([
                    'data'    => null,
                    'message' => 'No snapshot found',
                ]);
            }

            $snapshot = $this->service()->getLatestSnapshot($tenantId);

            return $this->response->setJSON([
                'data'    => $snapshot,
                'message' => $snapshot ? 'Latest sitemap snapshot retrieved' : 'No snapshot found',
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SitemapController::index: ' . $e->getMessage());
            return $this->response->setJSON([
                'data'    => null,
                'message' => 'No snapshot found',
            ]);
        }
    }

    public function generate(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getJSON(true)['tenant_id'] ?? 1);

        try {
            $snapshot = $this->service()->generateSnapshot($tenantId, 'manual');
            return $this->response->setStatusCode(201)->setJSON(['data' => $snapshot, 'message' => 'Snapshot generated']);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function entries(int $snapshotId): ResponseInterface
    {
        try {
            $includedOnly = $this->request->getGet('included_only') !== 'false';
            $entries      = $this->service()->getSnapshotEntries($snapshotId, $includedOnly);
            if (! is_array($entries)) {
                $entries = [];
            }

            return $this->response->setJSON([
                'data'  => $entries,
                'count' => count($entries),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SitemapController::entries: ' . $e->getMessage());
            return $this->response->setJSON(['data' => [], 'count' => 0]);
        }
    }
}
