<?php

namespace Tests\Unit\Publishing;

use App\Libraries\JobService;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use PHPUnit\Framework\TestCase;

final class PublicationEnqueueWiringTest extends TestCase
{
    public function testEnqueuePublicationJobUsesReachPublicationType(): void
    {
        $captured = [];

        $mock = $this->createMock(JobService::class);
        $mock->expects($this->once())
            ->method('enqueue')
            ->with(
                'reach.publication',
                ['deployment_id' => 42],
                $this->callback(function (array $opts) use (&$captured): bool {
                    $captured = $opts;
                    return ($opts['queue'] ?? '') === 'publishing'
                        && ($opts['priority'] ?? 0) === 10
                        && str_contains((string) ($opts['idempotency_key'] ?? ''), 'deploy-key');
                }),
            )
            ->willReturn(999);

        $svc = new PublicationDeploymentService($mock);
        $jobId = $svc->enqueuePublicationJob(42, 'deploy-key');

        $this->assertSame(999, $jobId);
        $this->assertSame('publishing', $captured['queue']);
    }

    public function testEnqueuePublicationJobRetryUsesEnqueueAt(): void
    {
        $mock = $this->createMock(JobService::class);
        $mock->expects($this->once())
            ->method('enqueueAt')
            ->with(
                'reach.publication',
                ['deployment_id' => 7, 'is_retry' => true],
                '2026-07-30 12:00:00',
                $this->callback(fn (array $opts) => ($opts['queue'] ?? '') === 'publishing'),
            )
            ->willReturn(1001);

        $svc = new PublicationDeploymentService($mock);
        $jobId = $svc->enqueuePublicationJob(7, 'retry-key', '2026-07-30 12:00:00', true);

        $this->assertSame(1001, $jobId);
    }
}
