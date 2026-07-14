<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class VideoPublicationController extends BaseApiController
{
    public function show(string $projectUuid): ResponseInterface
    {
        return $this->fail('YouTube publication requires CP8 implementation', 501);
    }

    public function publish(string $projectUuid): ResponseInterface
    {
        return $this->fail('YouTube publication requires CP8 implementation', 501);
    }

    public function retry(string $projectUuid): ResponseInterface
    {
        return $this->fail('YouTube publication requires CP8 implementation', 501);
    }

    public function cancel(string $projectUuid): ResponseInterface
    {
        return $this->fail('YouTube publication requires CP8 implementation', 501);
    }

    public function list(): ResponseInterface
    {
        return $this->fail('YouTube publications list requires CP8 implementation', 501);
    }
}
