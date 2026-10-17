<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Libraries\Video\Automation\VideoPipelineRepository;
use App\Libraries\Video\Automation\VideoPipelineRunner;
use CodeIgniter\Controller;

/**
 * POST v1/public/video/shotstack/callback/{item uuid}?sig=… — Shotstack calls
 * this when a render finishes. The URL carries an HMAC of the item uuid
 * (only Reach can mint it); the body is not trusted — it only brings the
 * status poll forward, and the poll asks Shotstack itself.
 */
class ShotstackCallbackController extends Controller
{
    public function render(string $uuid = '')
    {
        if (! VideoPipelineRunner::verifyCallback($uuid, (string) $this->request->getGet('sig'))) {
            return $this->response->setStatusCode(401)->setJSON(['ok' => false]);
        }
        $item = (new VideoPipelineRepository())->findByUuid($uuid);
        if ($item === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }

        $body     = json_decode((string) $this->request->getBody(), true);
        $renderId = is_array($body) ? (string) ($body['id'] ?? '') : '';
        if ($item['status'] === 'in_progress' && $item['step'] === 'render_poll' && ($renderId === '' || $renderId === $item['render_id'])) {
            VideoPipelineRunner::expedite((int) $item['id'], 'render_poll');
        }

        return $this->response->setStatusCode(200)->setJSON(['ok' => true]);
    }
}
