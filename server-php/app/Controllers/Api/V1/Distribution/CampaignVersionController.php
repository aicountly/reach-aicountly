<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\CampaignVersionService;
use App\Libraries\Distribution\CampaignChannelVariantService;
use App\Models\Distribution\CampaignVersionModel;
use App\Models\Distribution\CampaignChannelVariantModel;
use CodeIgniter\HTTP\ResponseInterface;

class CampaignVersionController extends BaseApiController
{
    private CampaignVersionService      $versionService;
    private CampaignChannelVariantService $variantService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $audit = new AuditLogger();
        $this->versionService = new CampaignVersionService(new CampaignVersionModel(), $audit);
        $this->variantService = new CampaignChannelVariantService(new CampaignChannelVariantModel(), $audit);
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    private function assertCampaignAccess(int $campaignId): void
    {
        $db  = \Config\Database::connect();
        $row = $db->table('reach_campaigns')->select('id,tenant_id')->where('id', $campaignId)->get()->getRowArray();
        if ($row === null || (isset($row['tenant_id']) && (int) $row['tenant_id'] !== $this->tenantId())) {
            throw new \RuntimeException('Campaign not found.', 404);
        }
    }

    // GET /campaigns/:id/versions
    public function index(string $campaignId): ResponseInterface
    {
        try {
            $this->assertCampaignAccess((int) $campaignId);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }
        $versions = $this->versionService->listForCampaign((int) $campaignId);
        return $this->ok(['data' => $versions]);
    }

    // POST /campaigns/:id/versions
    public function store(string $campaignId): ResponseInterface
    {
        try {
            $this->assertCampaignAccess((int) $campaignId);
            $body    = $this->input() ?: [];
            $version = $this->versionService->create((int) $campaignId, $body, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($version, 201);
    }

    // GET /campaigns/:id/versions/:v
    public function show(string $campaignId, string $versionId): ResponseInterface
    {
        $version = (new CampaignVersionModel())->find((int) $versionId);
        if ($version === null || (int) $version['campaign_id'] !== (int) $campaignId) {
            return $this->fail('Not found', 404);
        }
        return $this->ok($version);
    }

    // POST /campaigns/:id/versions/:v/submit
    public function submit(string $campaignId, string $versionId): ResponseInterface
    {
        try {
            $version = $this->versionService->submit((int) $versionId, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($version);
    }

    // POST /campaigns/:id/versions/:v/approve
    public function approve(string $campaignId, string $versionId): ResponseInterface
    {
        try {
            $version     = (new CampaignVersionModel())->find((int) $versionId);
            $submittedBy = $version ? (int) ($version['submitted_by'] ?? 0) : 0;
            $updated     = $this->versionService->approve((int) $versionId, $this->userId(), $submittedBy ?: null);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($updated);
    }

    // POST /campaigns/:id/versions/:v/reject
    public function reject(string $campaignId, string $versionId): ResponseInterface
    {
        $body   = $this->input() ?: [];
        $reason = (string) ($body['reason'] ?? '');
        if (empty($reason)) {
            return $this->fail('reason is required', 422);
        }
        try {
            $updated = $this->versionService->reject((int) $versionId, $reason, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($updated);
    }

    // POST /campaigns/:id/versions/:v/request-changes
    public function requestChanges(string $campaignId, string $versionId): ResponseInterface
    {
        $body  = $this->input() ?: [];
        $notes = (string) ($body['notes'] ?? '');
        try {
            $updated = $this->versionService->requestChanges((int) $versionId, $notes, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($updated);
    }

    // GET /campaigns/:id/versions/:v/variants
    public function variants(string $campaignId, string $versionId): ResponseInterface
    {
        $variants = $this->variantService->listForVersion((int) $versionId);
        return $this->ok(['data' => $variants]);
    }

    // POST /campaigns/:id/versions/:v/variants
    public function storeVariant(string $campaignId, string $versionId): ResponseInterface
    {
        $body    = $this->input() ?: [];
        $channel = (string) ($body['channel'] ?? '');
        if (empty($channel)) {
            return $this->fail('channel is required', 422);
        }
        try {
            $variant = $this->variantService->create((int) $versionId, $channel, $body, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($variant, 201);
    }
}
