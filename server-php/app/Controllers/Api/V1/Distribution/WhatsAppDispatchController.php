<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\WhatsAppSenderService;
use App\Libraries\Distribution\SuppressionService;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\ChannelConsentModel;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\HTTP\ResponseInterface;

class WhatsAppDispatchController extends BaseApiController
{
    private WhatsAppSenderService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $audit = new AuditLogger();
        $this->service = new WhatsAppSenderService(
            new CampaignDeliveryAttemptModel(),
            new ChannelConsentModel(),
            new SuppressionService(new ChannelSuppressionModel(), $audit),
            $audit,
        );
    }

    private function tenantId(): int
    {
        return (int) ($this->user()['tenant_id'] ?? 0);
    }

    // POST /distribution/whatsapp/dispatch/:id
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

    // GET /distribution/whatsapp/status/:id
    public function status(string $campaignId): ResponseInterface
    {
        return $this->ok($this->service->getStatus((int) $campaignId));
    }

    // GET /distribution/whatsapp/templates
    public function templates(): ResponseInterface
    {
        try {
            $templates = $this->service->listTemplates();
        } catch (\Throwable $e) {
            return $this->fail('Could not fetch templates: ' . $e->getMessage(), 502);
        }
        return $this->ok($templates);
    }

    // POST /distribution/whatsapp/opt-in-check
    public function optInCheck(): ResponseInterface
    {
        $body  = $this->input();
        $phone = trim($body['phone'] ?? '');
        if (empty($phone)) {
            return $this->fail('phone is required.', 422);
        }
        $granted = $this->service->validateOptIn($this->tenantId(), $phone);
        return $this->ok(['phone' => $phone, 'opt_in' => $granted]);
    }
}
