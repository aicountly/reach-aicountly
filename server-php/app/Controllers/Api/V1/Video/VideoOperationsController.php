<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class VideoOperationsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $renderProvider = env('VIDEO_RENDER_PROVIDER', 'mock');
        $youtubeEnabled = filter_var(env('YOUTUBE_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

        return $this->ok([
            'render_provider'   => $renderProvider ?: 'mock',
            'youtube_publisher' => $youtubeEnabled ? 'live' : 'mock',
            'youtube_enabled'   => $youtubeEnabled,
            'status'            => 'operational',
        ]);
    }

    public function auditForProject(string $projectUuid): ResponseInterface
    {
        return $this->ok([
            'project_uuid' => $projectUuid,
            'events'       => [],
            'message'      => 'No audit events recorded for this project yet.',
        ]);
    }
}
