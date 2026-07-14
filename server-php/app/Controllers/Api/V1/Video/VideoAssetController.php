<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class VideoAssetController extends BaseApiController
{
    public function listForProject(string $projectUuid): ResponseInterface
    {
        return $this->fail('Video asset management requires CP6 implementation', 501);
    }

    public function upload(string $projectUuid): ResponseInterface
    {
        return $this->fail('Video asset upload requires CP6 implementation', 501);
    }

    public function show(string $assetUuid): ResponseInterface
    {
        return $this->fail('Video asset download requires CP6 implementation', 501);
    }
}
