<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use Config\Services;

/**
 * Wraps the synchronous MarketingBotService::execute() so the same core
 * logic runs from the async job queue. The enqueue path lives in
 * MarketingBotService::enqueue() (called from MarketingBotController::dispatch).
 *
 * Payload contract:
 *   {
 *     "action":   "<one of MarketingBotService::ACTIONS>",
 *     "payload":  { ... action payload ... },
 *     "user_id":  <int|null enqueued_by_user_id>,
 *     "queue_id": <int marketing_bot_queue row created during enqueue>
 *   }
 */
class MarketingBotDispatchJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $action     = (string) ($payload['action'] ?? '');
        $actionArgs = (array) ($payload['payload'] ?? []);
        $userId     = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        $queueId    = isset($payload['queue_id']) ? (int) $payload['queue_id'] : null;

        if ($action === '') {
            throw new \InvalidArgumentException('MarketingBotDispatchJob requires payload.action');
        }

        return Services::marketingBot()->execute($action, $actionArgs, $userId, $queueId, $ctx->jobId);
    }
}
