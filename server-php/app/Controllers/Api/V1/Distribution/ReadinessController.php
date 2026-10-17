<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Distribution;

use App\Controllers\BaseApiController;
use App\Libraries\Distribution\CampaignReadinessService;
use CodeIgniter\HTTP\ResponseInterface;

class ReadinessController extends BaseApiController
{
    // GET /v1/distribution/readiness — per-channel live status (no secret values)
    public function index(): ResponseInterface
    {
        return $this->ok((new CampaignReadinessService())->report());
    }
}
