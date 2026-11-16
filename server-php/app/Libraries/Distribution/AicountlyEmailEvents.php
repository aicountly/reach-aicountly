<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Enums\SuppressionReason;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\AicountlyEmailSender;
use App\Models\Distribution\ChannelConsentModel;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Delivery events from AICOUNTLY's Email API (email-aicountly docs/DELIVERY_API.md § Events),
 * POSTed at least once to v1/inbound/aicountly-email/events:
 *
 *   {"event_id": 991, "event": "bounced", "delivery_id": "…", "idempotency_key": "reach:d12:r34",
 *    "category": "marketing", "recipient": "x@example.com", "dsn": "5.1.1", "bounce_class": "hard", "at": "…"}
 *   X-Aicountly-Timestamp: <unix seconds>
 *   X-Aicountly-Signature: sha256=hex(HMAC_SHA256(AICOUNTLY_EMAIL_WEBHOOK_SECRET, timestamp + "." + raw_body))
 *
 * verifySignature() checks the signature and rejects timestamps more than 5 minutes away.
 * ingest() then, in one transaction:
 *   - dedupes on event_id (reach_campaign_provider_events, provider aicountly_email,
 *     connection_id 0 so the existing unique (provider, connection_id, provider_event_id) index
 *     applies) — a repeat changes nothing and answers `duplicate`;
 *   - finds the delivery attempt by delivery id (provider_message_id), else by the
 *     Idempotency-Key we sent (reach:<attempt idempotency_key>);
 *   - moves the attempt forward (submitted → sent, relayed → delivered, bounced, complained,
 *     unsubscribed, suppressed, expired/cancelled → failed) — never backwards, so out-of-order
 *     redeliveries cannot undo a later state;
 *   - mirrors bounced(hard), complained and unsubscribed into Reach: the address goes on the
 *     tenant's email suppression list (reason bounce / complaint / unsubscribe — what every later
 *     audience snapshot and the send-time check honour, exactly like the unsubscribe link) and, for
 *     complained / unsubscribed, the lead's granted email marketing consent is revoked.
 * The stored event keeps only a masked recipient.
 */
final class AicountlyEmailEvents
{
    public const MAX_SKEW_SECONDS = 300;

    /** connection_id used for this provider's rows: one connection, and non-NULL so the unique dedupe index applies. */
    public const CONNECTION_ID = 0;

    public const EVENTS = ['queued', 'submitted', 'relayed', 'deferred', 'bounced', 'complained', 'unsubscribed', 'suppressed', 'expired', 'cancelled', 'uncertain', 'failover'];

    /** Higher rank wins; equal or lower is ignored (at-least-once, any order). */
    private const RANK = [
        'queued' => 0, 'sending' => 0, 'accepted' => 1, 'sent' => 2, 'delivered' => 3,
        'failed' => 4, 'bounced' => 4, 'suppressed' => 4, 'complained' => 5, 'unsubscribed' => 5,
    ];

    private BaseConnection $db;
    private AuditLogger $audit;

    public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
    {
        $this->db    = $db ?? \Config\Database::connect();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * @return string|null null when valid, otherwise why not (no secret, header, signature or timestamp)
     */
    public static function verifySignature(string $secret, string $timestamp, string $signature, string $rawBody, ?int $now = null): ?string
    {
        if ($secret === '') {
            return 'webhook secret not configured';
        }
        $timestamp = trim($timestamp);
        if ($timestamp === '' || preg_match('/\A\d{1,13}\z/', $timestamp) !== 1) {
            return 'missing or malformed timestamp';
        }
        $signature = trim($signature);
        if (! str_starts_with(strtolower($signature), 'sha256=')) {
            return 'missing or malformed signature';
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (! hash_equals($expected, strtolower(substr($signature, 7)))) {
            return 'signature mismatch';
        }
        $ts = (int) $timestamp;
        if ($ts > 9_999_999_999) {
            $ts = intdiv($ts, 1000); // milliseconds
        }
        if (abs(($now ?? time()) - $ts) > self::MAX_SKEW_SECONDS) {
            return 'stale timestamp';
        }

        return null;
    }

    /** sha256= signature for a body — what the Email API sends (tests, manual checks). */
    public static function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * @param array<string,mixed> $event the decoded body
     * @return array{status:string, event_id:?string, event:?string, attempt_id:?int, attempt_status:?string, suppressed:bool, consent_revoked:bool, error?:string}
     *         status: processed | duplicate | rejected (malformed — answer 4xx, not retried)
     */
    public function ingest(array $event): array
    {
        $eventId = is_scalar($event['event_id'] ?? null) ? trim((string) $event['event_id']) : '';
        $type    = is_string($event['event'] ?? null) ? strtolower(trim($event['event'])) : '';
        $out     = ['status' => 'rejected', 'event_id' => $eventId !== '' ? $eventId : null, 'event' => $type !== '' ? $type : null, 'attempt_id' => null, 'attempt_status' => null, 'suppressed' => false, 'consent_revoked' => false];

        if ($eventId === '' || strlen($eventId) > 255) {
            return $out + ['error' => 'event_id is required'];
        }
        if (! in_array($type, self::EVENTS, true)) {
            return $out + ['error' => 'unknown event type'];
        }

        $deliveryId = is_scalar($event['delivery_id'] ?? null) ? trim((string) $event['delivery_id']) : '';
        $idemKey    = is_scalar($event['idempotency_key'] ?? null) ? trim((string) $event['idempotency_key']) : '';
        $recipient  = is_string($event['recipient'] ?? null) ? strtolower(trim($event['recipient'])) : '';
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $recipient = '';
        }

        if ($this->isDuplicate($eventId)) {
            return ['status' => 'duplicate'] + $out;
        }

        $this->db->transBegin();
        try {
            $attempt  = $this->findAttempt($deliveryId, $idemKey);
            $tenantId = $this->tenantFor($attempt);

            $this->db->table('reach_campaign_provider_events')->insert([
                'tenant_id'         => $tenantId,
                'dispatch_id'       => $attempt !== null && $attempt['dispatch_id'] !== null ? (int) $attempt['dispatch_id'] : null,
                'attempt_id'        => $attempt !== null ? (int) $attempt['id'] : null,
                'provider'          => AicountlyEmailSender::PROVIDER,
                'connection_id'     => self::CONNECTION_ID,
                'event_type'        => $type,
                'raw_event'         => json_encode(self::redacted($event), JSON_UNESCAPED_SLASHES),
                'normalised_status' => AicountlyEmailSender::attemptStatusFor($type),
                'provider_event_id' => $eventId,
                'received_at'       => date('Y-m-d H:i:s'),
                'processed_at'      => date('Y-m-d H:i:s'),
            ]);

            $out['status']     = 'processed';
            $out['attempt_id'] = $attempt !== null ? (int) $attempt['id'] : null;
            if ($attempt !== null) {
                $out['attempt_status'] = $this->advanceAttempt($attempt, $type, $event, $deliveryId);
            }

            $reason = match (true) {
                $type === 'bounced' && strtolower((string) ($event['bounce_class'] ?? '')) === 'hard' => SuppressionReason::Bounce,
                $type === 'complained'   => SuppressionReason::Complaint,
                $type === 'unsubscribed' => SuppressionReason::Unsubscribe,
                default                  => null,
            };
            if ($reason !== null && $recipient !== '') {
                (new SuppressionService(new ChannelSuppressionModel(), $this->audit))
                    ->suppress($tenantId, 'email', $recipient, $reason, 'aicountly_email:' . $type, null);
                $out['suppressed'] = true;
                if ($reason !== SuppressionReason::Bounce) {
                    $out['consent_revoked'] = $this->revokeConsent($tenantId, $attempt);
                }
                $this->audit->record(match ($reason) {
                    SuppressionReason::Complaint   => AuditLogger::DISTRIBUTION_EMAIL_COMPLAINED,
                    SuppressionReason::Unsubscribe => AuditLogger::DISTRIBUTION_EMAIL_UNSUBSCRIBED,
                    default                        => AuditLogger::DISTRIBUTION_EMAIL_BOUNCED,
                }, [
                    'address_masked' => ChannelSuppressionModel::maskAddress($recipient),
                    'source'         => 'aicountly_email',
                    'event_id'       => $eventId,
                    'delivery_id'    => $deliveryId !== '' ? $deliveryId : null,
                ], null);
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('database transaction failed');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            // A concurrent delivery of the same event may have won the unique index.
            if ($this->isDuplicate($eventId)) {
                return ['status' => 'duplicate'] + $out;
            }
            throw $e;
        }

        return $out;
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function isDuplicate(string $eventId): bool
    {
        return $this->db->table('reach_campaign_provider_events')
            ->where('provider', AicountlyEmailSender::PROVIDER)
            ->where('connection_id', self::CONNECTION_ID)
            ->where('provider_event_id', $eventId)
            ->countAllResults() > 0;
    }

    /** @return array<string,mixed>|null */
    private function findAttempt(string $deliveryId, string $idempotencyKey): ?array
    {
        $table = $this->db->table('reach_campaign_delivery_attempts');
        if ($deliveryId !== '') {
            $row = $table->where('provider', AicountlyEmailSender::PROVIDER)->where('provider_message_id', $deliveryId)
                ->orderBy('id', 'DESC')->get()->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }
        $key = AicountlyEmailSender::messageKeyFrom($idempotencyKey);
        if ($key !== null) {
            $row = $this->db->table('reach_campaign_delivery_attempts')->where('idempotency_key', $key)
                ->where('provider', AicountlyEmailSender::PROVIDER)->get()->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whose suppression list / consent the event belongs to: the dispatch's tenant; for a test send
     * (`test:email_campaign:<id>:…`) the campaign's; otherwise the tenant of the latest email
     * dispatch (Reach runs one tenant per deployment — staff campaigns are tenant 0), else 0.
     */
    private function tenantFor(?array $attempt): int
    {
        if ($attempt !== null && $attempt['dispatch_id'] !== null) {
            $row = $this->db->table('reach_campaign_dispatches')->select('tenant_id')->where('id', (int) $attempt['dispatch_id'])->get()->getRowArray();
            if ($row !== null) {
                return (int) $row['tenant_id'];
            }
        }
        if ($attempt !== null && preg_match('/\Atest:' . ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN . ':(\d+):/', (string) $attempt['idempotency_key'], $m) === 1) {
            $row = $this->db->table('reach_email_campaigns')->select('tenant_id')->where('id', (int) $m[1])->get()->getRowArray();
            if ($row !== null && $row['tenant_id'] !== null) {
                return (int) $row['tenant_id'];
            }
        }
        $row = $this->db->table('reach_campaign_dispatches')->select('tenant_id')->where('channel', 'email')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return $row !== null ? (int) $row['tenant_id'] : 0;
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $event
     * @return string the attempt's status afterwards
     */
    private function advanceAttempt(array $attempt, string $type, array $event, string $deliveryId): string
    {
        $current = (string) $attempt['status'];
        $fields  = [];
        if ($deliveryId !== '' && (string) ($attempt['provider_message_id'] ?? '') === '') {
            $fields['provider_message_id'] = substr($deliveryId, 0, 255);
        }

        $next = AicountlyEmailSender::attemptStatusFor($type);
        // A send the worker gave up on as "outcome unknown" is corrected by what the mail server says.
        $unknownFailure = $current === 'failed' && ($attempt['failure_class'] ?? null) === 'unknown';
        if ($next !== null && ($unknownFailure || (self::RANK[$next] ?? 0) > (self::RANK[$current] ?? 0))) {
            $at  = self::eventTime($event);
            $fields['status'] = $next;
            switch ($next) {
                case 'sent':
                    $fields['sent_at'] = $at;
                    break;
                case 'delivered':
                    $fields['delivered_at'] = $at;
                    $fields['sent_at']      = $attempt['sent_at'] ?? $at;
                    break;
                case 'bounced':
                    $fields['failed_at']      = $at;
                    $fields['failure_class']  = AicountlyEmailSender::bounceFailureClass(strtolower((string) ($event['bounce_class'] ?? '')));
                    $fields['failure_detail'] = trim('Bounced (' . ($event['bounce_class'] ?? 'unknown class') . ($event['dsn'] ?? null ? ', DSN ' . $event['dsn'] : '') . ') — AICOUNTLY Email event ' . $event['event_id']);
                    break;
                case 'complained':
                case 'unsubscribed':
                    $fields['failure_detail'] = ucfirst($next) . ' — AICOUNTLY Email event ' . $event['event_id'];
                    break;
                case 'suppressed':
                    $fields['failure_detail'] = 'The mail server did not send: the address is on its suppression list — AICOUNTLY Email event ' . $event['event_id'];
                    break;
                case 'failed':
                    $fields['failed_at']      = $at;
                    $fields['failure_class']  = $type === 'expired' ? 'transient' : 'permanent';
                    $fields['failure_detail'] = ($type === 'expired' ? 'Expired undelivered' : 'Cancelled') . ' — AICOUNTLY Email event ' . $event['event_id'];
                    break;
            }
        }

        if ($fields !== []) {
            $this->db->table('reach_campaign_delivery_attempts')->where('id', (int) $attempt['id'])->update($fields);
        }

        return (string) ($fields['status'] ?? $current);
    }

    /** Revoke the lead's latest granted email marketing consent (the attempt names the lead). */
    private function revokeConsent(int $tenantId, ?array $attempt): bool
    {
        if ($attempt === null || empty($attempt['recipient_id'])) {
            return false;
        }
        $recipient = $this->db->table('reach_campaign_audience_recipients')->select('subject_type, subject_id')
            ->where('id', (int) $attempt['recipient_id'])->get()->getRowArray();
        if ($recipient === null || empty($recipient['subject_id'])) {
            return false;
        }
        $model   = new ChannelConsentModel();
        $consent = $model->findLatest($tenantId, (string) ($recipient['subject_type'] ?: 'lead'), (int) $recipient['subject_id'], 'email');
        if ($consent === null || $consent['status'] !== 'granted') {
            return false;
        }

        return (new ConsentService($model, $this->audit))->revoke((int) $consent['id'], $tenantId, null);
    }

    private static function eventTime(array $event): string
    {
        $at = is_string($event['at'] ?? null) ? strtotime($event['at']) : false;

        return date('Y-m-d H:i:s', $at !== false ? $at : time());
    }

    /** The event as stored: recipient masked, nothing else personal. */
    private static function redacted(array $event): array
    {
        if (is_string($event['recipient'] ?? null) && $event['recipient'] !== '') {
            $event['recipient'] = ChannelSuppressionModel::maskAddress($event['recipient']);
        }

        return $event;
    }
}
