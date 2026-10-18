<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Bot;

use App\Libraries\Bot\BotActionException;
use CodeIgniter\HTTP\ResponseInterface;

/** The error envelope for a refused bot action (reason + Retry-After on 429). */
trait BotActionResponses
{
    protected function refused(BotActionException $e): ResponseInterface
    {
        $details = ['reason' => $e->reason] + $e->details;
        if ($e->retryAfter !== null) {
            $details['retry_after'] = $e->retryAfter;
        }
        $res = json_fail($e->getMessage(), $e->httpStatus, $details);
        if ($e->retryAfter !== null) {
            $res->setHeader('Retry-After', (string) max(1, $e->retryAfter));
        }
        return $res;
    }

    /** Idempotency key from the Idempotency-Key header or the body's idempotency_key. */
    protected function idempotencyKey(array $input): ?string
    {
        $header = trim((string) $this->request->getHeaderLine('Idempotency-Key'));
        if ($header !== '') {
            return $header;
        }
        $body = $input['idempotency_key'] ?? null;
        return is_string($body) && trim($body) !== '' ? trim($body) : null;
    }

    /** @return array{id: ?int, name: ?string, email: ?string, role: ?string} */
    protected function actor(): array
    {
        $u = $this->user() ?? [];
        return [
            'id'    => isset($u['id']) ? (int) $u['id'] : null,
            'name'  => isset($u['name']) ? (string) $u['name'] : null,
            'email' => isset($u['email']) ? (string) $u['email'] : null,
            'role'  => isset($u['role']) ? (string) $u['role'] : null,
        ];
    }
}
