<?php

namespace App\Jobs;

use App\Libraries\Distribution\ChannelDispatchService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * distribution.dispatch.email | .sms | .whatsapp (queue `distribution`).
 *
 * Payload: {dispatch_id, batch}. Sends one batch of the dispatch's audience
 * snapshot and queues the next batch itself; the last batch finalises the
 * dispatch and the channel campaign.
 */
class DistributionChannelDispatchJob implements JobHandlerInterface
{
    public function __construct(private readonly ?ChannelDispatchService $service = null)
    {
    }

    public function handle(array $payload, JobContext $ctx): array
    {
        $dispatchId = (int) ($payload['dispatch_id'] ?? 0);
        if ($dispatchId <= 0) {
            throw new \InvalidArgumentException('distribution dispatch job needs dispatch_id');
        }

        return ($this->service ?? new ChannelDispatchService())->processBatch($dispatchId, (int) ($payload['batch'] ?? 0));
    }
}
