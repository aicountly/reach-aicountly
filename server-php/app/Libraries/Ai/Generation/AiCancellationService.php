<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

use App\Libraries\AuditLogger;

/**
 * Phase 3 — Cancels generation requests that are still cancellable.
 *
 * Active running jobs cannot be cancelled mid-flight; only pending/queued requests can be.
 */
class AiCancellationService
{
    private AiGenerationRequestService $requests;

    public function __construct()
    {
        $this->requests = new AiGenerationRequestService();
    }

    /**
     * Cancel a generation request by ID.
     *
     * @throws \RuntimeException if the request is not in a cancellable state
     */
    public function cancel(int $requestId, string $reason, array $actor): array
    {
        $request = $this->requests->findById($requestId);

        $cancellable = ['pending', 'grounding', 'queued', 'blocked'];
        if (! in_array($request['status'], $cancellable, true)) {
            throw new \RuntimeException(
                "Cannot cancel request in status '{$request['status']}'. Only pending/grounding/queued/blocked requests can be cancelled."
            );
        }

        $this->requests->cancel($requestId, $reason);

        AuditLogger::log('ai.generation_cancelled', [
            'request_id' => $requestId,
            'reason'     => $reason,
        ], $actor);

        return $this->requests->findById($requestId);
    }

    public function isCancellable(array $request): bool
    {
        return in_array($request['status'] ?? '', ['pending', 'grounding', 'queued', 'blocked'], true);
    }
}
