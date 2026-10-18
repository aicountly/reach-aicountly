<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Libraries\EngageClient;
use App\Libraries\Leads\EngagePushRetrySweep;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Leads marked retry_scheduled are actually retried, with backoff, and given
 * up honestly; nothing is attempted while Engage is not configured.
 *
 * @internal
 */
final class EngagePushRetrySweepTest extends DatabaseTestCase
{
    private function lead(string $status, int $attempts, ?string $lastPushAt, string $createdAt = '-1 hour'): int
    {
        $db = Database::connect();
        $db->table('reach_leads')->insert([
            'name' => 'Asha', 'email' => 'asha' . bin2hex(random_bytes(3)) . '@example.com', 'engage_push_status' => $status,
            'engage_push_attempts' => $attempts, 'last_push_at' => $lastPushAt, 'last_push_error' => 'Engage HTTP 503',
            'created_at' => date('Y-m-d H:i:s', strtotime($createdAt)), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function client(bool $configured, array &$pushed): EngageClient
    {
        return new class ($configured, $pushed) extends EngageClient {
            public function __construct(private readonly bool $configured, private array &$log)
            {
                parent::__construct();
            }
            public function isConfigured(): bool { return $this->configured; }
            public function pushLead(array $lead): array
            {
                $this->log[] = (int) $lead['id'];
                Database::connect()->table('reach_leads')->where('id', $lead['id'])->update(['engage_push_status' => 'pushed', 'last_push_at' => date('Y-m-d H:i:s')]);

                return ['status' => 'pushed', 'attempt' => []];
            }
        };
    }

    private function statusOf(int $id): string
    {
        return (string) Database::connect()->table('reach_leads')->where('id', $id)->get()->getRowArray()['engage_push_status'];
    }

    public function testRetriesDueLeadsWaitsOnBackoffAndGivesUp(): void
    {
        $due      = $this->lead('retry_scheduled', 1, date('Y-m-d H:i:s', time() - 600));   // 5 min backoff passed
        $tooSoon  = $this->lead('retry_scheduled', 3, date('Y-m-d H:i:s', time() - 300));   // needs 20 min
        $stale    = $this->lead('pending_push', 0, null, '-30 minutes');                    // capture push never landed
        $fresh    = $this->lead('pending_push', 0, null, '-1 minute');
        $exhausted = $this->lead('retry_scheduled', EngagePushRetrySweep::MAX_ATTEMPTS, date('Y-m-d H:i:s', time() - 86400));

        $pushed = [];
        $result = (new EngagePushRetrySweep(null, $this->client(true, $pushed)))->run();

        $this->assertSame([$due, $stale], $pushed);
        $this->assertSame(['retried' => 2, 'pushed' => 2, 'gave_up' => 1, 'waiting' => 1], $result);
        $this->assertSame('retry_scheduled', $this->statusOf($tooSoon));
        $this->assertSame('pending_push', $this->statusOf($fresh));
        $this->assertSame('failed', $this->statusOf($exhausted));
        $this->assertSame(20, EngagePushRetrySweep::backoffMinutes(3));
        $this->assertSame(360, EngagePushRetrySweep::backoffMinutes(12));
    }

    public function testDoesNothingWhileEngageIsNotConfigured(): void
    {
        $id     = $this->lead('retry_scheduled', 1, date('Y-m-d H:i:s', time() - 7200));
        $pushed = [];
        $result = (new EngagePushRetrySweep(null, $this->client(false, $pushed)))->run();

        $this->assertSame('engage_not_configured', $result['skipped']);
        $this->assertSame([], $pushed);
        $this->assertSame('retry_scheduled', $this->statusOf($id));
    }
}
