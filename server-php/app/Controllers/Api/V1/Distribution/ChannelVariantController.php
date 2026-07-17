<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\CampaignChannelVariantService;
use App\Models\Distribution\CampaignChannelVariantModel;
use CodeIgniter\HTTP\ResponseInterface;

class ChannelVariantController extends BaseApiController
{
    private CampaignChannelVariantService $service;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->service = new CampaignChannelVariantService(new CampaignChannelVariantModel(), new AuditLogger());
    }

    // PUT /distribution/variants/:id
    public function update(string $id): ResponseInterface
    {
        return $this->fail('Variant update is not permitted (immutable).', 405);
    }

    // POST /distribution/variants/:id/validate
    public function validate(string $id): ResponseInterface
    {
        try {
            $variant = $this->service->validate((int) $id, $this->userId());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->ok($variant);
    }
}
