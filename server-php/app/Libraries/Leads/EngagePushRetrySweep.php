<?php

declare(strict_types=1);

namespace App\Libraries\Leads;

use App\Libraries\EngageClient;
use App\Models\CampaignModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * Leads the first Engage push could not deliver were marked
 * `retry_scheduled`, but nothing ever retried them (EngagePushRetryJob was
 * registered and never enqueued), so they waited for someone to press
 * "Push" in Leads. This sweep (reach.engage_push_retry_sweep, every 15 min)
 * retries them with backoff — 5 min doubling, capped at 6 h — and marks a
 * lead `failed` after MAX_ATTEMPTS so the dashboard shows the real state.
 *
 * Without Engage configured it does nothing: the leads stay
 * retry_scheduled (honestly waiting) and no attempt is burned.
 */
class EngagePushRetrySweep
{
    public const MAX_ATTEMPTS = 8;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly ?EngageClient $client = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** Minutes to wait after the given number of attempts. */
    public static function backoffMinutes(int $attempts): int
    {
        return min(360, 5 * (2 ** max(0, min(7, $attempts - 1))));
    }

    /** @return array{retried: int, pushed: int, gave_up: int, waiting: int, skipped?: string} */
    public function run(int $limit = 25, ?int $now = null): array
    {
        $now    = $now ?? time();
        $client = $this->client ?? Services::engageClient();
        $out    = ['retried' => 0, 'pushed' => 0, 'gave_up' => 0, 'waiting' => 0];
        if (! $client->isConfigured()) {
            return $out + ['skipped' => 'engage_not_configured'];
        }

        $rows = $this->db->table('reach_leads')
            ->groupStart()
                ->where('engage_push_status', 'retry_scheduled')
                ->orGroupStart()
                    ->where('engage_push_status', 'pending_push')
                    ->where('created_at <', date('Y-m-d H:i:s', $now - 600))
                ->groupEnd()
            ->groupEnd()
            ->orderBy('id', 'ASC')->limit(max(1, $limit * 4))
            ->get()->getResultArray();

        foreach ($rows as $lead) {
            $attempts = (int) ($lead['engage_push_attempts'] ?? 0);
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->db->table('reach_leads')->where('id', $lead['id'])->update([
                    'engage_push_status' => 'failed',
                    'last_push_error'    => mb_substr('Gave up after ' . $attempts . ' attempts. Last error: ' . (string) ($lead['last_push_error'] ?? 'unknown'), 0, 1000),
                ]);
                $out['gave_up']++;
                continue;
            }
            $last = strtotime((string) ($lead['last_push_at'] ?? '')) ?: 0;
            if ($attempts > 0 && $last > $now - self::backoffMinutes($attempts) * 60) {
                $out['waiting']++;
                continue;
            }
            if ($out['retried'] >= $limit) {
                break;
            }

            $result = $client->pushLead($this->enrich($lead));
            $out['retried']++;
            if (($result['status'] ?? '') === 'pushed') {
                $out['pushed']++;
            }
        }

        return $out;
    }

    /** Same campaign fields the capture endpoint adds. */
    private function enrich(array $lead): array
    {
        if (! empty($lead['campaign_id'])) {
            $c = (new CampaignModel())->find((int) $lead['campaign_id']);
            if ($c) {
                $lead['campaign_code'] = 'REACH-' . $c['id'];
                $lead['campaign_name'] = $c['name'];
                $lead['campaign_kind'] = $c['campaign_type'];
            }
        }

        return $lead;
    }
}
