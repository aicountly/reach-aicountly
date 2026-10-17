<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Video\Automation\VideoAutomationService;
use App\Libraries\Video\Automation\VideoQuotaException;

/**
 * Endpoints for the reach-video Claude routine (X-Automation-Token).
 *
 *   POST v1/automation/video-scripts  submit a vlog or Short script
 *   GET  v1/automation/video-plan     quota, recent titles, topics, screens
 */
class VideoAutomationController extends BaseApiController
{
    public function storeScript()
    {
        try {
            $result = (new VideoAutomationService())->ingest($this->input());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (VideoQuotaException $e) {
            return $this->fail($e->getMessage(), 429);
        }

        $status = match ($result['status']) {
            'approved'        => 201,
            'pending_vetting' => 202,
            default           => 200,
        };
        return $this->ok($result, $status);
    }

    public function plan()
    {
        return $this->ok((new VideoAutomationService())->plan());
    }
}
