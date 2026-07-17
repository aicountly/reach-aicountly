<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\SmsSenderService;
use App\Libraries\Distribution\SuppressionService;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\HTTP\ResponseInterface;

class SmsController extends BaseApiController
{
    private SmsSenderService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $audit = new AuditLogger();
        $this->service = new SmsSenderService(
            new CampaignDeliveryAttemptModel(),
            new SuppressionService(new ChannelSuppressionModel(), $audit),
            $audit,
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    // POST /distribution/sms/dispatch/:campaign_id
    public function dispatch(string $campaignId): ResponseInterface
    {
        try {
            $result = $this->service->dispatch((int) $campaignId, $this->tenantId(), $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->fail('Provider error: ' . $e->getMessage(), 502);
        }
        return $this->ok($result, 202);
    }

    // GET /distribution/sms/status/:campaign_id
    public function status(string $campaignId): ResponseInterface
    {
        return $this->ok($this->service->getStatus((int) $campaignId));
    }

    // GET /distribution/sms/capabilities
    public function capabilities(): ResponseInterface
    {
        return $this->ok($this->service->getCapabilities());
    }

    // POST /distribution/sms/validate-dlt
    public function validateDlt(): ResponseInterface
    {
        $body = $this->input();
        try {
            $this->service->validateDlt($body);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok(['valid' => true]);
    }
}
