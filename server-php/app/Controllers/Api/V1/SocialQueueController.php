<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SocialPostModel;

class SocialQueueController extends BaseApiController
{
    public function index()
    {
        $rows = (new SocialPostModel())
            ->whereIn('status', ['approved', 'scheduled', 'manual_queue'])
            ->orderBy('scheduled_at', 'ASC')
            ->findAll(200);
        return $this->ok(['items' => $rows]);
    }
}
