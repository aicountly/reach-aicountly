<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\EmailSenderService;
use App\Libraries\Distribution\SuppressionService;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\HTTP\ResponseInterface;

class EmailDispatchController extends BaseApiController
{
    private EmailSenderService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $audit = new AuditLogger();
        $this->service = new EmailSenderService(
            new CampaignDeliveryAttemptModel(),
            new ChannelSuppressionModel(),
            new SuppressionService(new ChannelSuppressionModel(), $audit),
            $audit,
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    // POST /distribution/email/dispatch/:campaign_id
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

    // GET /distribution/email/status/:campaign_id
    public function status(string $campaignId): ResponseInterface
    {
        return $this->ok($this->service->getStatus((int) $campaignId));
    }

    // POST /distribution/email/test/:campaign_id — stub
    public function test(string $campaignId): ResponseInterface
    {
        return $this->fail('Test send will be implemented with production email provider.', 501);
    }
}
