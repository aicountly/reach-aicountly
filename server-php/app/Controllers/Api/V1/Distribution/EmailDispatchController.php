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

    // POST /distribution/email/test/:campaign_id  {to?: email}
    // Sends one copy to the named address (or CAMPAIGN_DISPATCH_TEST_EMAIL when
    // `to` is omitted). The campaign's status is not changed.
    public function test(string $campaignId): ResponseInterface
    {
        $to = trim((string) ($this->input()['to'] ?? ''));
        if ($to === '') {
            $to = trim((string) (getenv('CAMPAIGN_DISPATCH_TEST_EMAIL') ?: $_ENV['CAMPAIGN_DISPATCH_TEST_EMAIL'] ?? ''));
        }
        if ($to === '') {
            return $this->fail('Give a test address in "to" (or set CAMPAIGN_DISPATCH_TEST_EMAIL).', 422);
        }
        try {
            $result = $this->service->testSend((int) $campaignId, $this->tenantId(), $to, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            return $this->fail('Provider error: ' . $e->getMessage(), 502);
        }
        return $this->ok($result);
    }
}
