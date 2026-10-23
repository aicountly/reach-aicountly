<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Video\Automation\VideoProviderException;
use App\Libraries\Video\Automation\YouTubeChannelService;

/**
 * Connect Reach to the Aicountly YouTube channel.
 *
 *   GET  v1/video/youtube/status                 connection state (no secrets)
 *   GET  v1/video/youtube/connect                Google consent URL (admin)
 *   POST v1/video/youtube/disconnect             revoke + forget the token (admin)
 *   GET  v1/public/video/youtube/oauth-callback  Google redirects here (public;
 *                                                 the single-use state is the guard)
 */
class YouTubeChannelController extends BaseApiController
{
    public function status()
    {
        return $this->ok((new YouTubeChannelService())->status());
    }

    public function connect()
    {
        try {
            $url = (new YouTubeChannelService())->startConnect($this->userId());
        } catch (VideoProviderException $e) {
            return $this->fail($e->getMessage(), 409);
        }
        $this->audit('video.youtube.connect_started', 'video_youtube');
        return $this->ok(['consent_url' => $url]);
    }

    public function disconnect()
    {
        (new YouTubeChannelService())->disconnect($this->userId());
        return $this->ok((new YouTubeChannelService())->status());
    }

    public function oauthCallback()
    {
        $reason = (new YouTubeChannelService())->completeConnect(
            (string) $this->request->getGet('state'),
            (string) $this->request->getGet('code'),
            (string) $this->request->getGet('error'),
        );
        $base = rtrim(trim((string) (env('REACH_APP_URL') ?: 'https://reach.aicountly.org')), '/');
        $to   = $base . '/video?' . http_build_query($reason === null ? ['youtube' => 'connected'] : ['youtube' => 'error', 'reason' => $reason]);

        return $this->response->setStatusCode(302)->setHeader('Location', $to)->setHeader('Cache-Control', 'no-store')->setBody('');
    }
}
