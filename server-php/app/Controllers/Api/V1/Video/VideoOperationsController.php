<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\Automation\VideoPipelineStatus;
use CodeIgniter\HTTP\ResponseInterface;

class VideoOperationsController extends BaseApiController
{
    /**
     * Pipeline health from what is really configured (keys, channel, signing
     * key, mocks) — the old version said "live" whenever an env flag was set.
     */
    public function index(): ResponseInterface
    {
        $status = VideoPipelineStatus::describe();

        $path = $status['youtube_path'];
        return $this->ok($status + [
            'youtube_publisher' => $path['mode'] === 'direct'
                ? ($status['youtube']['connected'] ? 'connected' : 'not connected')
                : ($path['ready'] ? 'social-api.ai' : 'social-api.ai (not ready)'),
            'status'            => $status['ready'] ? 'ready' : 'not_ready',
        ]);
    }

    public function auditForProject(string $projectUuid): ResponseInterface
    {
        $events = [];
        try {
            $db = \Config\Database::connect();
            $project = $db->table('reach_video_projects')->select('id')->where('uuid', $projectUuid)->get()->getRowArray();
            if ($project) {
                $events = $db->table('reach_audit_logs')
                    ->select('action, created_at, user_id')
                    ->groupStart()->like('action', 'video.', 'after')->groupEnd()
                    ->where('entity_id', (int) $project['id'])
                    ->orderBy('created_at', 'DESC')->limit(100)->get()->getResultArray();
            }
        } catch (\Throwable) {
            $events = [];
        }

        return $this->ok(['project_uuid' => $projectUuid, 'events' => $events]);
    }
}
