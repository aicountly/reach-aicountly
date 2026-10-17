<?php

declare(strict_types=1);

namespace App\Libraries\Leads;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Stores Engage → Reach lead outcome events (POST v1/inbound/engage/lead-outcomes).
 *
 * Contract (docs/integrations/ENGAGE_OUTCOMES.md):
 *   {events:[{event_id, lead_code, reach_lead_id|null, email_sha256|null, stage, sales_status, product,
 *             value_inr|null, occurred_at, seller_code|null, campaign_code|null, source_portal}]}
 *
 * - Idempotent by event_id: a repeat is answered `duplicate` and changes nothing.
 * - Matched to a Reach lead by reach_lead_id, else engage_lead_code, else email_sha256
 *   (sha256 of the lower-cased, trimmed e-mail); otherwise stored `unmatched`.
 * - Attributed at receipt: the lead's campaign / landing page, else the lead's last
 *   attribution touchpoint (reach_attribution_conversion_links → reach_attribution_touchpoints)
 *   for campaign and content, else an Engage `campaign_code` of the form REACH-<id>.
 * - reach_leads.engage_stage / engage_sales_status move only forward in time: an older
 *   event arriving late never overwrites a newer one.
 *
 * Each event is validated and stored on its own; one bad event does not reject the batch.
 */
final class LeadOutcomeIngestService
{
    public const MAX_EVENTS = 500;

    /** Stages that count as a conversion / a confirmed payment in summaries. */
    public const STAGE_CONVERTED         = 'converted';
    public const STAGE_PAYMENT_CONFIRMED = 'payment_confirmed';
    public const KNOWN_STAGES            = ['qualified', 'demo', 'proposal_sent', 'converted', 'lost', 'payment_confirmed'];

    private const TENANT_ID = 1;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param mixed $events the decoded `events` member of the request body
     * Per event: `accepted` (stored), `duplicate` (event_id seen before — nothing changed),
     * `rejected` (invalid; permanent — do not retry) or `failed` (server error; retry).
     *
     * @return array{received:int, accepted:int, duplicate:int, rejected:int, failed:int, results:list<array<string,mixed>>}
     */
    public function ingest(mixed $events): array
    {
        $out = ['received' => 0, 'accepted' => 0, 'duplicate' => 0, 'rejected' => 0, 'failed' => 0, 'results' => []];
        foreach ($events as $raw) {
            $out['received']++;
            try {
                $result = $this->ingestOne($raw);
            } catch (\Throwable $ex) {
                if ($this->db->transDepth > 0) {
                    $this->db->transRollback();
                }
                log_message('error', 'Engage outcome ingest failed: ' . $ex->getMessage());
                $result = [
                    'event_id' => is_array($raw) && is_scalar($raw['event_id'] ?? null) ? (string) $raw['event_id'] : null,
                    'status'   => 'failed',
                    'error'    => 'Could not store the event; retry later.',
                ];
            }
            $out[$result['status']]++;
            $out['results'][] = $result;
        }

        return $out;
    }

    /**
     * Validate and normalise one event. Pure (no database).
     *
     * @return array{ok:true, event:array<string,mixed>}|array{ok:false, error:string}
     */
    public static function validateEvent(mixed $e): array
    {
        if (! is_array($e) || array_is_list($e) && $e !== []) {
            return ['ok' => false, 'error' => 'event must be an object'];
        }

        $str = static function (string $key, int $max, bool $required = false) use ($e): array {
            $v = $e[$key] ?? null;
            if ($v === null || $v === '') {
                return $required ? [false, "{$key} is required"] : [true, null];
            }
            if (! is_string($v) && ! is_int($v)) {
                return [false, "{$key} must be a string"];
            }
            $v = trim((string) $v);
            if ($v === '') {
                return $required ? [false, "{$key} is required"] : [true, null];
            }
            if (mb_strlen($v) > $max) {
                return [false, "{$key} is longer than {$max} characters"];
            }

            return [true, $v];
        };

        $n = [];
        foreach ([
            'event_id'      => [100, true],
            'lead_code'     => [100, false],
            'stage'         => [32, true],
            'sales_status'  => [64, false],
            'product'       => [100, false],
            'seller_code'   => [32, false],
            'campaign_code' => [100, false],
            'source_portal' => [100, false],
        ] as $key => [$max, $required]) {
            [$ok, $v] = $str($key, $max, $required);
            if (! $ok) {
                return ['ok' => false, 'error' => $v];
            }
            $n[$key] = $v;
        }

        if (preg_match('/\A[A-Za-z0-9._:\-]+\z/', $n['event_id']) !== 1) {
            return ['ok' => false, 'error' => 'event_id may contain only letters, digits and . _ : -'];
        }
        $n['stage'] = strtolower($n['stage']);
        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $n['stage']) !== 1) {
            return ['ok' => false, 'error' => 'stage must be a lower-case slug'];
        }
        if ($n['sales_status'] !== null) {
            $n['sales_status'] = strtolower($n['sales_status']);
        }
        if ($n['seller_code'] !== null) {
            $n['seller_code'] = strtoupper($n['seller_code']);
        }

        $rid = $e['reach_lead_id'] ?? null;
        if ($rid === null || $rid === '') {
            $n['reach_lead_id'] = null;
        } elseif ((is_int($rid) || (is_string($rid) && ctype_digit($rid))) && (int) $rid > 0) {
            $n['reach_lead_id'] = (int) $rid;
        } else {
            return ['ok' => false, 'error' => 'reach_lead_id must be a positive integer or null'];
        }

        $hash = $e['email_sha256'] ?? null;
        if ($hash === null || $hash === '') {
            $n['email_sha256'] = null;
        } elseif (is_string($hash) && preg_match('/\A[a-fA-F0-9]{64}\z/', $hash) === 1) {
            $n['email_sha256'] = strtolower($hash);
        } else {
            return ['ok' => false, 'error' => 'email_sha256 must be 64 hex characters or null'];
        }

        if ($n['lead_code'] === null && $n['reach_lead_id'] === null && $n['email_sha256'] === null) {
            return ['ok' => false, 'error' => 'one of lead_code, reach_lead_id or email_sha256 is required'];
        }

        $value = $e['value_inr'] ?? null;
        if ($value === null || $value === '') {
            $n['value_inr'] = null;
        } else {
            $text = is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : null);
            if ($text === null || preg_match('/\A\d{1,12}(\.\d{1,2})?\z/', $text) !== 1) {
                return ['ok' => false, 'error' => 'value_inr must be a non-negative amount with at most 2 decimals, e.g. "1234.50"'];
            }
            $n['value_inr'] = self::money($text);
        }

        $at = $e['occurred_at'] ?? null;
        if (! is_string($at) || trim($at) === '') {
            return ['ok' => false, 'error' => 'occurred_at is required (ISO-8601)'];
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?\z/i', trim($at)) !== 1) {
            return ['ok' => false, 'error' => 'occurred_at must be an ISO-8601 date-time, e.g. 2026-10-17T10:15:00+05:30'];
        }
        try {
            $when = new DateTimeImmutable(trim($at), new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'occurred_at is not a valid ISO-8601 timestamp'];
        }
        $n['occurred_at'] = $when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');

        return ['ok' => true, 'event' => $n];
    }

    /** "1234.5" → "1234.50"; integer digits kept as given (no float rounding). */
    public static function money(string $text): string
    {
        [$int, $frac] = array_pad(explode('.', $text, 2), 2, '');
        $int = ltrim($int, '0');

        return ($int === '' ? '0' : $int) . '.' . str_pad($frac, 2, '0');
    }

    /** sha256 of the lower-cased, trimmed address — what Engage sends as email_sha256. */
    public static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** @return array<string,mixed> */
    private function ingestOne(mixed $raw): array
    {
        $eventId = is_array($raw) && is_scalar($raw['event_id'] ?? null) ? (string) $raw['event_id'] : null;
        $v = self::validateEvent($raw);
        if (! $v['ok']) {
            return ['event_id' => $eventId, 'status' => 'rejected', 'error' => $v['error']];
        }
        $e = $v['event'];

        $existing = $this->db->query('SELECT id, lead_id, match_method FROM reach_lead_outcomes WHERE event_id = ?', [$e['event_id']])->getRowArray();
        if ($existing) {
            return [
                'event_id' => $e['event_id'], 'status' => 'duplicate', 'outcome_id' => (int) $existing['id'],
                'lead_id'  => $existing['lead_id'] !== null ? (int) $existing['lead_id'] : null, 'match' => $existing['match_method'],
            ];
        }

        $this->db->transStart();
        [$lead, $match] = $this->matchLead($e);
        $attr = $this->attribute($lead, $e);

        $row = $this->db->query(
            'INSERT INTO reach_lead_outcomes
                (event_id, lead_id, engage_lead_code, claimed_reach_lead_id, email_sha256, match_method, stage, sales_status,
                 product, value_inr, occurred_at, seller_code, campaign_code, source_portal, campaign_id, landing_page_id,
                 content_identity_id, source, attribution_method, raw_event)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
             ON CONFLICT (event_id) DO NOTHING
             RETURNING id',
            [
                $e['event_id'], $lead['id'] ?? null, $e['lead_code'], $e['reach_lead_id'], $e['email_sha256'], $match,
                $e['stage'], $e['sales_status'], $e['product'], $e['value_inr'], $e['occurred_at'], $e['seller_code'],
                $e['campaign_code'], $e['source_portal'], $attr['campaign_id'], $attr['landing_page_id'],
                $attr['content_identity_id'], $attr['source'], $attr['method'],
                json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null,
            ],
        )->getRowArray();

        if (! $row) { // lost a race with the same event_id
            $this->db->transComplete();

            return ['event_id' => $e['event_id'], 'status' => 'duplicate', 'lead_id' => null, 'match' => null];
        }

        if ($lead) {
            $this->db->query(
                "UPDATE reach_leads
                    SET engage_stage        = CASE WHEN engage_stage_at IS NULL OR engage_stage_at <= ?::timestamptz THEN ? ELSE engage_stage END,
                        engage_sales_status = CASE WHEN engage_stage_at IS NULL OR engage_stage_at <= ?::timestamptz THEN ? ELSE engage_sales_status END,
                        engage_stage_at     = GREATEST(COALESCE(engage_stage_at, ?::timestamptz), ?::timestamptz),
                        engage_lead_code    = COALESCE(engage_lead_code, ?),
                        updated_at          = NOW()
                  WHERE id = ?",
                [
                    $e['occurred_at'], $e['stage'], $e['occurred_at'], $e['sales_status'],
                    $e['occurred_at'], $e['occurred_at'], $e['lead_code'], (int) $lead['id'],
                ],
            );
        }
        $this->db->transComplete();

        return [
            'event_id'    => $e['event_id'],
            'status'      => 'accepted',
            'outcome_id'  => (int) $row['id'],
            'lead_id'     => isset($lead['id']) ? (int) $lead['id'] : null,
            'match'       => $match,
            'attribution' => $attr['method'],
        ];
    }

    /** @return array{0:?array<string,mixed>, 1:string} */
    private function matchLead(array $e): array
    {
        if ($e['reach_lead_id'] !== null) {
            $lead = $this->db->query('SELECT * FROM reach_leads WHERE id = ?', [$e['reach_lead_id']])->getRowArray();
            if ($lead) {
                return [$lead, 'reach_lead_id'];
            }
        }
        if ($e['lead_code'] !== null) {
            $lead = $this->db->query('SELECT * FROM reach_leads WHERE engage_lead_code = ? ORDER BY id ASC LIMIT 1', [$e['lead_code']])->getRowArray();
            if ($lead) {
                return [$lead, 'engage_lead_code'];
            }
        }
        if ($e['email_sha256'] !== null) {
            $lead = $this->db->query(
                "SELECT * FROM reach_leads
                  WHERE email IS NOT NULL AND email <> ''
                    AND encode(sha256(convert_to(lower(btrim(email)), 'UTF8')), 'hex') = ?
                  ORDER BY id ASC LIMIT 1",
                [$e['email_sha256']],
            )->getRowArray();
            if ($lead) {
                return [$lead, 'email_sha256'];
            }
        }

        return [null, 'unmatched'];
    }

    /**
     * @return array{campaign_id:?int, landing_page_id:?int, content_identity_id:?int, source:?string, method:string}
     */
    private function attribute(?array $lead, array $e): array
    {
        $campaignId    = isset($lead['campaign_id']) ? (int) $lead['campaign_id'] : null;
        $landingPageId = isset($lead['landing_page_id']) ? (int) $lead['landing_page_id'] : null;
        $contentId     = null;
        $method        = $campaignId !== null ? 'lead_campaign' : 'unattributed';
        $touch         = null;

        if ($lead) {
            $touch = $this->db->query(
                'SELECT t.campaign_id, t.content_identity_id, t.channel, t.utm_source
                   FROM reach_attribution_conversion_links l
                   JOIN reach_attribution_touchpoints t ON t.id = COALESCE(l.last_touchpoint_id, l.first_touchpoint_id)
                  WHERE l.lead_id = ? AND l.tenant_id = ?
                  ORDER BY l.converted_at DESC, l.id DESC
                  LIMIT 1',
                [(int) $lead['id'], self::TENANT_ID],
            )->getRowArray();
            if ($touch) {
                $contentId = $touch['content_identity_id'] !== null ? (int) $touch['content_identity_id'] : null;
                if ($campaignId === null && $touch['campaign_id'] !== null && $this->campaignExists((int) $touch['campaign_id'])) {
                    $campaignId = (int) $touch['campaign_id'];
                }
                if ($method === 'unattributed' && ($campaignId !== null || $contentId !== null)) {
                    $method = 'touchpoint';
                }
            }
            if ($contentId === null && $landingPageId !== null) {
                $ci = $this->db->query(
                    "SELECT id FROM reach_content_identities WHERE content_type = 'landing_page' AND source_id = ? ORDER BY id LIMIT 1",
                    [$landingPageId],
                )->getRowArray();
                $contentId = $ci ? (int) $ci['id'] : null;
            }
        }

        if ($campaignId === null && $e['campaign_code'] !== null
            && preg_match('/\AREACH-(\d{1,18})\z/', $e['campaign_code'], $m) === 1
            && $this->campaignExists((int) $m[1])) {
            $campaignId = (int) $m[1];
            if ($method === 'unattributed') {
                $method = 'campaign_code';
            }
        }

        $source = self::firstNonEmpty(
            $lead['utm_source'] ?? null,
            $touch['utm_source'] ?? null,
            $touch['channel'] ?? null,
            $lead['source_kind'] ?? null,
            ($e['seller_code'] ?? null) !== null || ! empty($lead['ref_code']) ? 'seller' : null,
        );
        if ($method === 'unattributed' && $source !== null && $lead) {
            $method = 'lead_source';
        }

        return [
            'campaign_id'         => $campaignId,
            'landing_page_id'     => $landingPageId,
            'content_identity_id' => $contentId,
            'source'              => $source !== null ? mb_substr(strtolower($source), 0, 120) : null,
            'method'              => $method,
        ];
    }

    private function campaignExists(int $id): bool
    {
        return (bool) $this->db->query('SELECT 1 FROM reach_campaigns WHERE id = ?', [$id])->getRowArray();
    }

    private static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }
}
