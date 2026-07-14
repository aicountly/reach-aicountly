<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class VideoOperationsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->fail('Video operations dashboard requires CP8 implementation', 501);
    }

    public function auditForProject(string $projectUuid): ResponseInterface
    {
        return $this->fail('Video audit requires CP8 implementation', 501);
    }
}
