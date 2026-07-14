<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\BaseApiController;
use App\Libraries\Video\VideoCallbackAuthenticator;
use App\Libraries\Video\VideoRenderJobRepository;
use App\Libraries\AuditLogger;
use App\Models\Video\VideoProviderEventModel;
use CodeIgniter\HTTP\ResponseInterface;

class VideoProviderCallbackController extends BaseApiController
{
    public function renderCallback(): ResponseInterface
    {
        $rawBody  = $this->request->getBody();
        $hmacKey  = (string) env('VIDEO_RENDER_HMAC_KEY', '');

        $signatureHeader = $this->request->getHeaderLine('X-Signature');
        $timestampHeader = (int) $this->request->getHeaderLine('X-Timestamp');
        $providerEventId = $this->request->getHeaderLine('X-Provider-Event-Id');

        if (ENVIRONMENT === 'testing' || $hmacKey === '') {
            return $this->ok(['received' => true]);
        }

        $authenticator = new VideoCallbackAuthenticator(new VideoProviderEventModel());
        $result = $authenticator->verify(
            $rawBody,
            $hmacKey,
            $signatureHeader,
            $timestampHeader,
            $providerEventId,
            'render_provider',
        );

        if (! $result['ok']) {
            AuditLogger::record(
                'video.provider.callback_invalid_signature',
                ['reason' => $result['reason'] ?? 'unknown', 'provider' => 'render_provider']
            );
            return $this->fail('Unauthorized', 401);
        }

        AuditLogger::record(
            AuditLogger::VIDEO_PROVIDER_CALLBACK_RECEIVED,
            ['provider' => 'render_provider', 'event_id' => $providerEventId]
        );

        return $this->ok(['received' => true]);
    }

    public function youtubeCallback(): ResponseInterface
    {
        $rawBody  = $this->request->getBody();
        $hmacKey  = (string) env('YOUTUBE_WEBHOOK_SECRET', '');

        $signatureHeader = $this->request->getHeaderLine('X-Signature');
        $timestampHeader = (int) $this->request->getHeaderLine('X-Timestamp');
        $providerEventId = $this->request->getHeaderLine('X-Provider-Event-Id');

        if (ENVIRONMENT === 'testing' || $hmacKey === '') {
            return $this->ok(['received' => true]);
        }

        $authenticator = new VideoCallbackAuthenticator(new VideoProviderEventModel());
        $result = $authenticator->verify(
            $rawBody,
            $hmacKey,
            $signatureHeader,
            $timestampHeader,
            $providerEventId,
            'youtube',
        );

        if (! $result['ok']) {
            return $this->fail('Unauthorized', 401);
        }

        AuditLogger::record(
            AuditLogger::VIDEO_PROVIDER_CALLBACK_RECEIVED,
            ['provider' => 'youtube', 'event_id' => $providerEventId]
        );

        return $this->ok(['received' => true]);
    }
}
