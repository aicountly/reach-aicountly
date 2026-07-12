<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

abstract class BaseApiController extends Controller
{
    /**
     * Currently-authenticated Reach user (populated by JwtFilter).
     *
     * @return array{id:int,email:string,name:string,role:string}|null
     */
    protected function user(): ?array
    {
        return $this->request->reachUser ?? null;
    }

    protected function userId(): ?int
    {
        $u = $this->user();
        return $u ? (int) $u['id'] : null;
    }

    protected function ok(mixed $data = [], int $status = 200): ResponseInterface
    {
        return json_ok($data, $status);
    }

    protected function fail(string $message, int $status = 400, mixed $details = null): ResponseInterface
    {
        return json_fail($message, $status, $details);
    }

    /**
     * Read JSON body or fall back to form data.
     */
    protected function input(): array
    {
        $json = $this->request->getJSON(true);
        if (is_array($json)) {
            return $json;
        }
        return (array) $this->request->getPost();
    }

    /**
     * Local audit log write + Console fan-out for whitelisted event families.
     * Failures are logged only — never surfaced to the caller.
     */
    protected function audit(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?array $extra = null,
        ?string $reason = null,
    ): void {
        $user = $this->user();
        Services::auditLogger()->log(
            userId:       $this->userId(),
            action:       $action,
            entityType:   $entityType,
            entityId:     $entityId,
            oldValue:     $oldValue,
            newValue:     $newValue,
            extra:        $extra,
            actorType:    $user['actor_type'] ?? 'human',
            actorService: 'reach:api',
            reason:       $reason,
            requestId:    $this->request->reachRequestId ?? null,
        );
    }

    /**
     * Common integer/limit helpers for paginated endpoints.
     */
    protected function pagination(int $defaultLimit = 25, int $maxLimit = 200): array
    {
        $page  = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit = (int) ($this->request->getGet('limit') ?? $defaultLimit);
        $limit = max(1, min($maxLimit, $limit));
        return [$page, $limit, ($page - 1) * $limit];
    }
}
