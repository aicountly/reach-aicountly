<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Inbound;

use App\Controllers\BaseApiController;
use App\Libraries\Distribution\AicountlyEmailEvents;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST v1/inbound/aicountly-email/events — delivery events from AICOUNTLY's Email API
 * (email-aicountly docs/DELIVERY_API.md § Events). Outside the jwt group: the HMAC signature
 * (AICOUNTLY_EMAIL_WEBHOOK_SECRET, the secret `PUT /v1/service/delivery-webhook` returned once)
 * is the guard, checked against the raw body before anything is parsed.
 *
 *   503  AICOUNTLY_EMAIL_WEBHOOK_SECRET is not set (fail closed — the sender retries later)
 *   401  bad / missing signature or a timestamp more than 5 minutes away
 *   400  not one JSON event object
 *   422  event_id missing or unknown event type (permanent: not worth resending)
 *   200  processed, or duplicate (event_id already stored — nothing changed)
 *   500  storing failed — the sender redelivers; a stored event then answers duplicate
 */
class AicountlyEmailWebhookController extends BaseApiController
{
    public function store(): ResponseInterface
    {
        $secret = trim((string) env('AICOUNTLY_EMAIL_WEBHOOK_SECRET', ''));
        if ($secret === '') {
            return $this->fail('AICOUNTLY Email events are not configured (AICOUNTLY_EMAIL_WEBHOOK_SECRET).', 503);
        }

        $raw = (string) $this->request->getBody();
        $why = AicountlyEmailEvents::verifySignature(
            $secret,
            $this->request->getHeaderLine('X-Aicountly-Timestamp'),
            $this->request->getHeaderLine('X-Aicountly-Signature'),
            $raw,
        );
        if ($why !== null) {
            log_message('warning', '[AicountlyEmailWebhook] rejected: ' . $why);

            return $this->fail('Unauthorized', 401);
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || array_is_list($event)) {
            return $this->fail('Body must be one JSON event object.', 400);
        }

        try {
            $result = (new AicountlyEmailEvents())->ingest($event);
        } catch (\Throwable $e) {
            log_message('error', '[AicountlyEmailWebhook] event ' . (is_scalar($event['event_id'] ?? null) ? (string) $event['event_id'] : '?') . ' not stored: ' . $e->getMessage());

            return $this->fail('Event could not be stored; redeliver it.', 500);
        }

        if ($result['status'] === 'rejected') {
            return $this->fail((string) ($result['error'] ?? 'Invalid event.'), 422);
        }

        return $this->ok($result);
    }
}
