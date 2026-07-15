<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseController;
use App\Libraries\Intelligence\SitemapSnapshotService;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\SitemapSnapshotModel;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class SitemapController extends BaseController
{
    private SitemapSnapshotService $sitemapService;

    public function __construct()
    {
        $this->sitemapService = new SitemapSnapshotService(
            new ContentIdentityModel(),
            new SitemapSnapshotModel(),
            new AuditLogger()
        );
    }

    public function index(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
        $snapshot = $this->sitemapService->getLatestSnapshot($tenantId);

        return $this->response->setJSON([
            'data'    => $snapshot,
            'message' => $snapshot ? 'Latest sitemap snapshot retrieved' : 'No snapshot found',
        ]);
    }

    public function generate(): ResponseInterface
    {
        $tenantId = (int) ($this->request->getJSON(true)['tenant_id'] ?? 1);

        try {
            $snapshot = $this->sitemapService->generateSnapshot($tenantId, 'manual');
            return $this->response->setStatusCode(201)->setJSON(['data' => $snapshot, 'message' => 'Snapshot generated']);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    public function entries(int $snapshotId): ResponseInterface
    {
        $includedOnly = $this->request->getGet('included_only') !== 'false';
        $entries      = $this->sitemapService->getSnapshotEntries($snapshotId, $includedOnly);

        return $this->response->setJSON([
            'data'  => $entries,
            'count' => count($entries),
        ]);
    }
}
