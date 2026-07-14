<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class VideoConnectionController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->fail('YouTube connections require CP8 implementation', 501);
    }

    public function store(): ResponseInterface
    {
        return $this->fail('YouTube connections require CP8 implementation', 501);
    }

    public function show(string $uuid): ResponseInterface
    {
        return $this->fail('YouTube connections require CP8 implementation', 501);
    }

    public function revoke(string $uuid): ResponseInterface
    {
        return $this->fail('YouTube connections require CP8 implementation', 501);
    }

    public function health(string $uuid): ResponseInterface
    {
        return $this->fail('YouTube connections require CP8 implementation', 501);
    }
}
