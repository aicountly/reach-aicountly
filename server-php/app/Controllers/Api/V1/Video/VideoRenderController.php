<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class VideoRenderController extends BaseApiController
{
    public function queue(string $projectUuid): ResponseInterface
    {
        return $this->fail('Video rendering requires CP6 implementation', 501);
    }

    public function showJob(string $jobUuid): ResponseInterface
    {
        return $this->fail('Video render jobs require CP6 implementation', 501);
    }

    public function cancel(string $jobUuid): ResponseInterface
    {
        return $this->fail('Render job cancellation requires CP6 implementation', 501);
    }

    public function retry(string $jobUuid): ResponseInterface
    {
        return $this->fail('Render job retry requires CP6 implementation', 501);
    }

    public function listProfiles(): ResponseInterface
    {
        return $this->fail('Render profiles require CP6 implementation', 501);
    }

    public function createProfile(): ResponseInterface
    {
        return $this->fail('Render profiles require CP6 implementation', 501);
    }

    public function showProfile(string $uuid): ResponseInterface
    {
        return $this->fail('Render profiles require CP6 implementation', 501);
    }

    public function updateProfile(string $uuid): ResponseInterface
    {
        return $this->fail('Render profiles require CP6 implementation', 501);
    }

    public function deleteProfile(string $uuid): ResponseInterface
    {
        return $this->fail('Render profiles require CP6 implementation', 501);
    }
}
