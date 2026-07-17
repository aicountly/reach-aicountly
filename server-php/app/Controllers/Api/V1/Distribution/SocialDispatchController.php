<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\SocialPublisherService;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;
use CodeIgniter\HTTP\ResponseInterface;

class SocialDispatchController extends BaseApiController
{
    private SocialPublisherService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new SocialPublisherService(
            new CampaignDispatchModel(),
            new CampaignDeliveryAttemptModel(),
            new CampaignOperationalMetricsModel(),
            new AuditLogger(),
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    // POST /distribution/social/dispatch/:post_id
    public function dispatch(string $postId): ResponseInterface
    {
        try {
            $result = $this->service->dispatch((int) $postId, $this->tenantId(), $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->fail('Provider error: ' . $e->getMessage(), 502);
        }
        return $this->ok($result, 202);
    }

    // GET /distribution/social/status/:post_id
    public function status(string $postId): ResponseInterface
    {
        $result = $this->service->getStatus((int) $postId);
        return $this->ok($result);
    }
}
