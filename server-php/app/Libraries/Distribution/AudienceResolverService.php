<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Gateways\SmsSender;
use App\Libraries\Settings\ReachConfig;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Turns a campaign's audience definition into a frozen recipient snapshot.
 *
 * Source of people: `reach_leads` (the only contact store Reach has). The
 * audience must be explicit — `{"segment_id": N}`, `{"lead_ids": [...]}`,
 * `{"filters": [rules]}` or `{"all_leads": true}`; an empty definition is
 * refused rather than read as "everyone".
 *
 * Per recipient, in order:
 *   1. address for the channel (email / mobile / whatsapp→mobile), normalised
 *      and validated                                → invalid_address
 *   2. suppression list (unsubscribe, bounce, complaint, manual) → suppressed
 *   3. consent (`reach_channel_consents`, subject_type=lead, purpose=marketing):
 *        SMS / WhatsApp: a current `granted` row is required (opt-in);
 *        email: a revoked/expired row excludes; with
 *        distribution.email.require_opt_in on, `granted` is required → no_consent
 *   4. dedupe on the normalised address              → duplicate
 *   5. per-send cap (distribution.send_cap.<channel>) → ineligible (over cap)
 *
 * Recipients store only a hash + masked address and the lead id; the real
 * address is read from the lead at send time.
 */
class AudienceResolverService
{
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    /** Consent is required before a marketing send on these channels. */
    public const OPT_IN_CHANNELS = ['sms', 'whatsapp'];

    /** reach_leads columns a segment rule may test (rule field → column). */
    private const RULE_COLUMNS = [
        'email'            => 'email',
        'phone'            => 'mobile',
        'created_at'       => 'created_at',
        'source_kind'      => 'source_kind',
        'product_interest' => 'product_interest',
        'priority'         => 'priority',
        'organization'     => 'organization',
        'campaign_id'      => 'campaign_id',
    ];

    private BaseConnection $db;
    private AuditLogger $audit;

    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? \Config\Database::connect();
        $this->audit = $audit ?? new AuditLogger();
    }

    // ── addresses ───────────────────────────────────────────────────────────

    /** Normalised address of a lead on a channel, or '' when it has none / it is invalid. */
    public static function addressFor(array $lead, string $channel): string
    {
        if ($channel === 'email') {
            $email = strtolower(trim((string) ($lead['email'] ?? '')));
            return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        $raw = $channel === 'whatsapp'
            ? (trim((string) ($lead['whatsapp'] ?? '')) ?: trim((string) ($lead['mobile'] ?? '')))
            : trim((string) ($lead['mobile'] ?? ''));

        return self::normalisePhone($raw);
    }

    public static function normalisePhone(string $raw): string
    {
        $phone = SmsSender::normalizePhone($raw);
        $len   = strlen($phone);

        return $len >= 10 && $len <= 15 ? $phone : '';
    }

    /**
     * Forms an operator may have typed when suppressing a number by hand
     * ("+91 98765 43210", "9876543210", …) so all of them match.
     *
     * @return list<string>
     */
    public static function addressVariants(string $channel, string $address): array
    {
        if ($channel === 'email') {
            return [strtolower(trim($address))];
        }
        $digits = preg_replace('/\D+/', '', $address) ?? '';
        $out    = [$address, $digits, '+' . $digits];
        if (strlen($digits) > 10) {
            $local = substr($digits, -10);
            $out[] = $local;
            $out[] = '0' . $local;
        }

        return array_values(array_unique(array_filter($out, static fn ($v) => $v !== '' && $v !== '+')));
    }

    // ── checks reused at send time ──────────────────────────────────────────

    public function isSuppressed(int $tenantId, string $channel, string $address): bool
    {
        $hashes = array_map(
            static fn (string $v) => ChannelSuppressionModel::hashAddress($tenantId, $channel, $v),
            self::addressVariants($channel, $address)
        );
        $count = $this->db->table('reach_channel_suppressions')
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->whereIn('address_hash', $hashes)
            ->groupStart()->where('expires_at', null)->orWhere('expires_at >', date('Y-m-d H:i:s'))->groupEnd()
            ->countAllResults();

        return $count > 0;
    }

    /**
     * Latest marketing consent per lead for a channel: lead id → granted|revoked.
     * Leads without a row are absent (unknown).
     *
     * @param list<int> $leadIds
     * @return array<int, string>
     */
    public function consentMap(int $tenantId, string $channel, array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($leadIds, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->query(
                "SELECT DISTINCT ON (subject_id) subject_id, status, expires_at
                   FROM reach_channel_consents
                  WHERE tenant_id = ? AND subject_type = 'lead' AND channel = ? AND purpose = 'marketing'
                    AND subject_id IN ({$placeholders})
                  ORDER BY subject_id, captured_at DESC, id DESC",
                array_merge([$tenantId, $channel], $chunk)
            )->getResultArray();
            foreach ($rows as $row) {
                $granted = $row['status'] === 'granted'
                    && ($row['expires_at'] === null || strtotime((string) $row['expires_at']) > time());
                $out[(int) $row['subject_id']] = $granted ? 'granted' : 'revoked';
            }
        }

        return $out;
    }

    /** Opt-in check for one number (WhatsApp opt-in-check endpoint). */
    public function hasOptIn(int $tenantId, string $channel, string $phone): bool
    {
        $phone = self::normalisePhone($phone);
        if ($phone === '' || $this->isSuppressed($tenantId, $channel, $phone)) {
            return false;
        }
        $local = substr($phone, -10);
        $leads = $this->db->table('reach_leads')->select('id, mobile, whatsapp')
            ->groupStart()->like('mobile', $local, 'before')->orLike('whatsapp', $local, 'before')->groupEnd()
            ->get()->getResultArray();
        $ids = [];
        foreach ($leads as $lead) {
            if (self::addressFor($lead, $channel) === $phone) {
                $ids[] = (int) $lead['id'];
            }
        }
        foreach ($this->consentMap($tenantId, $channel, $ids) as $status) {
            if ($status === 'granted') {
                return true;
            }
        }

        return false;
    }

    // ── candidates ──────────────────────────────────────────────────────────

    /**
     * Leads selected by an audience definition (before consent/suppression).
     *
     * @param array<string,mixed> $criteria
     * @return list<array<string,mixed>>
     */
    public function candidateLeads(array $criteria, int $tenantId): array
    {
        $builder = $this->db->table('reach_leads')->select('id, name, email, mobile, whatsapp');

        if (! empty($criteria['lead_ids']) && is_array($criteria['lead_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $criteria['lead_ids']), static fn ($id) => $id > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('id', $ids);
        } elseif (! empty($criteria['segment_id'])) {
            $segment = $this->db->table('reach_audience_segments')->where('id', (int) $criteria['segment_id'])->get()->getRowArray();
            if ($segment === null || (int) ($segment['tenant_id'] ?? 0) !== $tenantId) {
                throw new \RuntimeException('Audience segment not found.', 404);
            }
            if (! in_array($segment['is_active'] ?? true, [true, 't', 'true', 1, '1'], true)) {
                throw new \RuntimeException('Audience segment is inactive.', 422);
            }
            $rules = $this->db->table('reach_audience_segment_rules')
                ->where('segment_id', (int) $segment['id'])->orderBy('rule_group', 'ASC')->orderBy('id', 'ASC')
                ->get()->getResultArray();
            if ($rules === []) {
                throw new \RuntimeException('Audience segment has no rules; refusing to treat it as "everyone".', 422);
            }
            $this->applyRules($builder, $rules);
        } elseif (! empty($criteria['filters']) && is_array($criteria['filters'])) {
            $this->applyRules($builder, $criteria['filters']);
        } elseif (($criteria['all_leads'] ?? false) === true) {
            // explicit "everyone" — consent/suppression still apply
        } else {
            throw new \RuntimeException(
                'Campaign has no audience. Set audience_filter to {"segment_id": N}, {"lead_ids": [...]}, {"filters": [...]} or {"all_leads": true}.',
                422
            );
        }

        return $builder->orderBy('id', 'ASC')->get()->getResultArray();
    }

    /**
     * Rules in the same rule_group are ANDed; groups are ORed. Unknown fields
     * are refused — silently ignoring a rule would widen the audience.
     *
     * @param list<array<string,mixed>> $rules
     */
    private function applyRules($builder, array $rules): void
    {
        $groups = [];
        foreach ($rules as $rule) {
            $groups[(int) ($rule['rule_group'] ?? 0)][] = $rule;
        }
        $builder->groupStart();
        $firstGroup = true;
        foreach ($groups as $groupRules) {
            $firstGroup ? $builder->groupStart() : $builder->orGroupStart();
            $firstGroup = false;
            foreach ($groupRules as $rule) {
                $this->applyRule($builder, $rule);
            }
            $builder->groupEnd();
        }
        $builder->groupEnd();
    }

    private function applyRule($builder, array $rule): void
    {
        $field = (string) ($rule['field'] ?? '');
        if (! isset(self::RULE_COLUMNS[$field])) {
            throw new \RuntimeException("Segment rule field '{$field}' cannot be evaluated against reach_leads.", 422);
        }
        $col     = self::RULE_COLUMNS[$field];
        $op      = strtolower((string) ($rule['operator'] ?? 'eq'));
        $value   = $rule['value'] ?? null;
        $negated = in_array($rule['negated'] ?? false, [true, 't', 'true', 1, '1'], true);
        $str     = is_scalar($value) ? (string) $value : '';

        if ($negated) {
            $builder->notGroupStart();
        } else {
            $builder->groupStart();
        }
        switch ($op) {
            case 'eq':          $builder->where($col, $str); break;
            case 'neq':         $builder->where($col . ' !=', $str); break;
            case 'contains':    $builder->like($col, $str, 'both', null, true); break;
            case 'starts_with': $builder->like($col, $str, 'after', null, true); break;
            case 'ends_with':   $builder->like($col, $str, 'before', null, true); break;
            case 'gte':         $builder->where($col . ' >=', $str); break;
            case 'lte':         $builder->where($col . ' <=', $str); break;
            case 'is_set':      $builder->where($col . ' IS NOT NULL', null, false)->where($col . " <> ''", null, false); break;
            case 'in':
                $list = is_array($value) ? $value : array_map('trim', explode(',', $str));
                $list = array_values(array_filter(array_map('strval', $list), static fn ($v) => $v !== ''));
                if ($list === []) {
                    $builder->where('1 = 0', null, false);
                } else {
                    $builder->whereIn($col, $list);
                }
                break;
            default:
                throw new \RuntimeException("Segment rule operator '{$op}' is not supported.", 422);
        }
        $builder->groupEnd();
    }

    // ── snapshot ────────────────────────────────────────────────────────────

    /**
     * Build and freeze the recipient snapshot for one channel send.
     *
     * @param array<string,mixed> $criteria
     * @return array<string,mixed> the frozen snapshot row
     */
    public function buildSnapshot(int $campaignId, int $tenantId, string $channel, array $criteria, ?int $actorId, ?int $versionId = null): array
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException("Audience snapshots are for email, sms and whatsapp, not '{$channel}'.");
        }

        $leads    = $this->candidateLeads($criteria, $tenantId);
        $cap      = max(0, (int) ReachConfig::get('distribution.send_cap.' . $channel, 0));
        $emailOptIn = $channel === 'email' && (bool) ReachConfig::get('distribution.email.require_opt_in', false);
        $consents = $this->consentMap($tenantId, $channel, array_map(static fn ($l) => (int) $l['id'], $leads));
        $suppressedHashes = $this->suppressedHashSet($tenantId, $channel);

        $this->db->table('reach_campaign_audience_snapshots')->insert([
            'campaign_id'         => $campaignId,
            'campaign_version_id' => $versionId,
            'tenant_id'           => $tenantId,
            'channel'             => $channel,
            'snapshot_criteria'   => json_encode($criteria + ['send_cap' => $cap]),
        ]);
        $snapshotId = (int) $this->db->insertID();

        $seen = [];
        $rows = [];
        $counts = ['total' => 0, 'eligible' => 0, 'suppressed' => 0, 'no_consent' => 0, 'invalid_address' => 0, 'duplicate' => 0, 'over_cap' => 0];
        foreach ($leads as $lead) {
            $leadId  = (int) $lead['id'];
            $address = self::addressFor($lead, $channel);
            $consent = $consents[$leadId] ?? 'unknown';
            $hash    = $address !== '' ? ChannelSuppressionModel::hashAddress($tenantId, $channel, $address) : hash('sha256', 'none:' . $leadId);
            $status  = 'eligible';
            $reason  = null;
            $suppressed = false;

            if ($address === '') {
                $status = 'invalid_address';
                $reason = "lead has no valid {$channel} address";
            } elseif ($this->matchesSuppression($tenantId, $channel, $address, $suppressedHashes)) {
                $status = 'suppressed';
                $reason = 'on the suppression list';
                $suppressed = true;
            } elseif (in_array($channel, self::OPT_IN_CHANNELS, true) && $consent !== 'granted') {
                $status = 'no_consent';
                $reason = $consent === 'revoked' ? 'marketing consent revoked/expired' : 'no recorded marketing opt-in';
            } elseif ($channel === 'email' && $consent === 'revoked') {
                $status = 'no_consent';
                $reason = 'email marketing consent revoked/expired';
            } elseif ($emailOptIn && $consent !== 'granted') {
                $status = 'no_consent';
                $reason = 'no recorded email opt-in (distribution.email.require_opt_in is on)';
            } elseif (isset($seen[$hash])) {
                $status = 'duplicate';
                $reason = 'same address as lead #' . $seen[$hash];
            } elseif ($cap > 0 && $counts['eligible'] >= $cap) {
                $status = 'ineligible';
                $reason = "over the per-send cap of {$cap}";
            }

            // Only an eligible recipient claims its address: a second lead with
            // the same number but a recorded opt-in must still be reachable.
            if ($status === 'eligible') {
                $seen[$hash] = $leadId;
            }

            $counts['total']++;
            $key = $status === 'ineligible' ? 'over_cap' : $status;
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            $rows[] = [
                'snapshot_id'            => $snapshotId,
                'tenant_id'              => $tenantId,
                'channel'                => $channel,
                'channel_address_hash'   => $hash,
                'channel_address_masked' => $address !== '' ? substr(ChannelSuppressionModel::maskAddress($address), 0, 100) : null,
                'consent_status'         => $consent,
                'suppressed'             => $suppressed,
                'suppression_reason'     => $suppressed ? 'suppression_list' : null,
                'eligibility_status'     => $status,
                'eligibility_reason'     => $reason,
                'dedup_key'              => 's' . $snapshotId . ':' . $hash . ($status === 'eligible' ? '' : ':' . $leadId),
                'subject_type'           => 'lead',
                'subject_id'             => $leadId,
            ];
            if (count($rows) >= 500) {
                $this->db->table('reach_campaign_audience_recipients')->insertBatch($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->db->table('reach_campaign_audience_recipients')->insertBatch($rows);
        }

        $this->db->table('reach_campaign_audience_snapshots')->where('id', $snapshotId)->update([
            'frozen_at'        => date('Y-m-d H:i:s'),
            'frozen_by'        => $actorId,
            'recipient_count'  => $counts['total'],
            'eligible_count'   => $counts['eligible'],
            'suppressed_count' => $counts['suppressed'],
        ]);

        $this->audit->record(AuditLogger::DISTRIBUTION_SNAPSHOT_FROZEN, [
            'snapshot_id'     => $snapshotId,
            'campaign_id'     => $campaignId,
            'channel'         => $channel,
            'recipient_count' => $counts['total'],
            'eligible_count'  => $counts['eligible'],
            'excluded'        => array_diff_key($counts, ['total' => 1, 'eligible' => 1]),
        ], $actorId);

        $snapshot = $this->db->table('reach_campaign_audience_snapshots')->where('id', $snapshotId)->get()->getRowArray() ?? [];
        $snapshot['breakdown'] = $counts;

        return $snapshot;
    }

    /** How many leads a definition selects (segment preview), before consent/suppression. */
    public function countCandidates(array $criteria, int $tenantId): int
    {
        return count($this->candidateLeads($criteria, $tenantId));
    }

    /** @return array<string, true> */
    private function suppressedHashSet(int $tenantId, string $channel): array
    {
        $rows = $this->db->table('reach_channel_suppressions')->select('address_hash')
            ->where('tenant_id', $tenantId)->where('channel', $channel)
            ->groupStart()->where('expires_at', null)->orWhere('expires_at >', date('Y-m-d H:i:s'))->groupEnd()
            ->get()->getResultArray();
        $set = [];
        foreach ($rows as $row) {
            $set[(string) $row['address_hash']] = true;
        }

        return $set;
    }

    /** @param array<string, true> $set */
    private function matchesSuppression(int $tenantId, string $channel, string $address, array $set): bool
    {
        if ($set === []) {
            return false;
        }
        foreach (self::addressVariants($channel, $address) as $variant) {
            if (isset($set[ChannelSuppressionModel::hashAddress($tenantId, $channel, $variant)])) {
                return true;
            }
        }

        return false;
    }
}
