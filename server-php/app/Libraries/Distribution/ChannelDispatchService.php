<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\MockEmailSender;
use App\Libraries\Distribution\Providers\MockSmsSender;
use App\Libraries\Distribution\Providers\MockWhatsAppSender;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\SendSkippedException;
use App\Libraries\JobService;
use App\Libraries\Settings\ReachConfig;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Campaign sends for email, SMS and WhatsApp.
 *
 *   start()        validate → resolve + freeze the audience snapshot → create a
 *                  `reach_campaign_dispatches` row (status `queued`) → enqueue
 *                  the first `distribution.dispatch.<channel>` job.
 *   processBatch() (job handler) send the next N eligible recipients, one
 *                  `reach_campaign_delivery_attempts` row each, then queue the
 *                  next batch or finalise.
 *   finalize()     dispatch → completed / partially_completed / failed; the
 *                  channel campaign is `sent` only when at least one message
 *                  was accepted by a real provider.
 *
 * Honesty rules: a mock never counts in production (start() refuses); a
 * SKIP_SEND gateway records `skipped` and stops the dispatch; a provider that
 * is not configured records `failed/not_configured` and stops the dispatch.
 */
class ChannelDispatchService
{
    public const SOURCE_EMAIL_CAMPAIGN    = 'email_campaign';
    public const SOURCE_WHATSAPP_CAMPAIGN = 'whatsapp_campaign';
    public const SOURCE_SMS_CAMPAIGN      = 'sms_campaign';
    public const SOURCE_VARIANT           = 'variant';

    public const QUEUE = 'distribution';

    private const JOB_TYPES = [
        'email'    => DistributionJobTypes::CAMPAIGN_DISPATCH_EMAIL,
        'sms'      => DistributionJobTypes::CAMPAIGN_DISPATCH_SMS,
        'whatsapp' => DistributionJobTypes::CAMPAIGN_DISPATCH_WA,
    ];

    private const SMS_MAX_BODY = 160;

    private BaseConnection $db;
    private JobService $jobs;
    private AudienceResolverService $resolver;
    private AuditLogger $audit;

    /** @var (\Closure(string): object)|null channel → sender, for tests */
    private ?\Closure $senderFactory;

    /** @var array<string, object> */
    private array $senders = [];

    public function __construct(
        ?JobService $jobs = null,
        ?AudienceResolverService $resolver = null,
        ?AuditLogger $audit = null,
        ?\Closure $senderFactory = null,
        ?BaseConnection $db = null,
    ) {
        $this->db            = $db ?? \Config\Database::connect();
        $this->jobs          = $jobs ?? new JobService();
        $this->audit         = $audit ?? new AuditLogger();
        $this->resolver      = $resolver ?? new AudienceResolverService($this->db, $this->audit);
        $this->senderFactory = $senderFactory;
    }

    // ── start ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public function start(string $sourceType, int $sourceId, int $tenantId, ?int $actorId): array
    {
        $source  = $this->loadSource($sourceType, $sourceId);
        $channel = $source['channel'];

        if ((int) ($source['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException(ucfirst($channel) . ' campaign not found.', 404);
        }
        if (! in_array($source['status'], ['approved', 'scheduled'], true)) {
            throw new \RuntimeException('Campaign must be approved before dispatch.', 409);
        }

        $this->assertProviderUsable($channel);
        $this->validateContent($channel, $source['content']);

        $previous = $this->db->table('reach_campaign_dispatches')
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->orderBy('id', 'DESC')->get()->getResultArray();
        if ($previous !== [] && in_array($previous[0]['status'], ['queued', 'dispatching', 'completed', 'partially_completed'], true)) {
            return ['status' => 'already_dispatched', 'dispatch' => $previous[0]];
        }

        $campaignId = $this->ensureParentCampaign($source, $tenantId, $actorId);
        $snapshot   = $this->resolver->buildSnapshot(
            $campaignId,
            $tenantId,
            $channel,
            $source['criteria'],
            $actorId,
            $source['campaign_version_id'] ?? null,
        );

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_campaign_dispatches')->insert([
            'campaign_id'         => $campaignId,
            'campaign_version_id' => $source['campaign_version_id'] ?? null,
            'snapshot_id'         => (int) $snapshot['id'],
            'tenant_id'           => $tenantId,
            'channel'             => $channel,
            'status'              => 'queued',
            'idempotency_key'     => substr($sourceType . ':' . $sourceId . ':' . (count($previous) + 1), 0, 128),
            'total_recipients'    => (int) $snapshot['eligible_count'],
            'suppressed_count'    => (int) $snapshot['suppressed_count'],
            'source_type'         => $sourceType,
            'source_id'           => $sourceId,
            'created_by'          => $actorId,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $dispatchId = (int) $this->db->insertID();

        $this->audit->record(AuditLogger::DISTRIBUTION_DISPATCH_CREATED, [
            'dispatch_id' => $dispatchId,
            'campaign_id' => $campaignId,
            'channel'     => $channel,
            'source'      => $sourceType . ':' . $sourceId,
            'eligible'    => (int) $snapshot['eligible_count'],
        ], $actorId);

        if ((int) $snapshot['eligible_count'] === 0) {
            $final = $this->finalize($dispatchId, 'No eligible recipients: ' . $this->describeBreakdown($snapshot['breakdown'] ?? []));
            return ['status' => 'failed', 'dispatch' => $final, 'snapshot' => $this->snapshotSummary($snapshot)];
        }

        $this->markSourceSending($source);
        $jobId = $this->enqueueBatch($dispatchId, $channel, 0);

        return [
            'status'      => 'queued',
            'dispatch_id' => $dispatchId,
            'job_id'      => $jobId,
            'queue'       => self::QUEUE,
            'snapshot'    => $this->snapshotSummary($snapshot),
        ];
    }

    // ── batches ─────────────────────────────────────────────────────────────

    /**
     * Send the next batch for a dispatch. Called by the distribution.dispatch.* job handler.
     *
     * @return array<string,mixed>
     */
    public function processBatch(int $dispatchId, int $batchNo = 0): array
    {
        $dispatch = $this->dispatchRow($dispatchId);
        if (in_array($dispatch['status'], ['completed', 'partially_completed', 'failed', 'cancelled', 'dead_lettered', 'paused'], true)) {
            return ['status' => 'noop', 'dispatch_status' => $dispatch['status']];
        }
        if ($dispatch['status'] === 'queued') {
            $this->db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update([
                'status'     => 'dispatching',
                'started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->record(AuditLogger::DISTRIBUTION_DISPATCH_STARTED, ['dispatch_id' => $dispatchId, 'channel' => $dispatch['channel']], null);
        }

        $channel  = (string) $dispatch['channel'];
        $tenantId = (int) $dispatch['tenant_id'];
        $source   = $this->loadSource((string) $dispatch['source_type'], (int) $dispatch['source_id']);
        $sender   = $this->sender($channel);
        $limit    = max(1, min(1000, (int) ReachConfig::get('distribution.batch_size', 100)));

        $batch = $this->pendingRecipients($dispatchId, (int) $dispatch['snapshot_id'], $limit);
        $leads = $this->leadsById(array_map(static fn ($r) => (int) $r['subject_id'], $batch));

        $systemic   = null;
        $rateLimited = false;
        $processed  = 0;
        foreach ($batch as $recipient) {
            $outcome = $this->sendOne($dispatch, $source, $sender, $recipient, $leads[(int) $recipient['subject_id']] ?? null, $tenantId);
            $processed++;
            if ($outcome['stop'] === 'rate_limit') {
                $rateLimited = true;
                break;
            }
            if ($outcome['stop'] !== null) {
                $systemic = $outcome['stop'];
                break;
            }
        }

        $this->refreshCounts($dispatchId);

        if ($systemic !== null) {
            $final = $this->finalize($dispatchId, $systemic);
            return ['status' => 'stopped', 'reason' => $systemic, 'processed' => $processed, 'dispatch' => $final];
        }
        if ($rateLimited) {
            $jobId = $this->enqueueBatch($dispatchId, $channel, $batchNo + 1, 60);
            return ['status' => 'rate_limited', 'processed' => $processed, 'next_job_id' => $jobId];
        }
        if ($this->pendingRecipients($dispatchId, (int) $dispatch['snapshot_id'], 1) !== []) {
            $jobId = $this->enqueueBatch($dispatchId, $channel, $batchNo + 1);
            return ['status' => 'continuing', 'processed' => $processed, 'next_job_id' => $jobId];
        }

        $final = $this->finalize($dispatchId, null);
        return ['status' => 'finished', 'processed' => $processed, 'dispatch' => $final];
    }

    /**
     * @return array{stop: ?string}
     */
    private function sendOne(array $dispatch, array $source, object $sender, array $recipient, ?array $lead, int $tenantId): array
    {
        $channel   = (string) $dispatch['channel'];
        $dispatchId = (int) $dispatch['id'];
        $key       = 'd' . $dispatchId . ':r' . (int) $recipient['id'];
        $now       = date('Y-m-d H:i:s');
        $base      = [
            'dispatch_id'     => $dispatchId,
            'recipient_id'    => (int) $recipient['id'],
            'attempt_number'  => 1,
            'provider'        => method_exists($sender, 'providerName') ? $sender->providerName() : null,
            'idempotency_key' => $key,
            'created_at'      => $now,
        ];

        $address = $lead !== null ? AudienceResolverService::addressFor($lead, $channel) : '';
        if ($address === '' || ChannelSuppressionModel::hashAddress($tenantId, $channel, $address) !== $recipient['channel_address_hash']) {
            $this->insertAttempt($base + ['status' => 'failed', 'failure_class' => 'permanent', 'failure_detail' => 'Lead address removed or changed since the audience snapshot.', 'failed_at' => $now]);
            return ['stop' => null];
        }
        // Someone may have unsubscribed between snapshot and send.
        if ($this->resolver->isSuppressed($tenantId, $channel, $address)) {
            $this->insertAttempt($base + ['status' => 'suppressed', 'failure_detail' => 'Suppressed after the snapshot was taken.']);
            return ['stop' => null];
        }

        $message   = $this->buildMessage($channel, $source['content'], $key, $address, $lead, $tenantId);
        $attemptId = $this->insertAttempt($base + ['status' => 'sending']);
        $started   = microtime(true);

        try {
            $receipt = $sender->send($message);
            if (! $receipt->isAccepted()) {
                throw new \RuntimeException('Provider did not accept the message (status ' . $receipt->status . ').', 502);
            }
            $this->updateAttempt($attemptId, [
                'status'              => 'accepted',
                'provider_message_id' => substr($receipt->providerMessageId, 0, 255),
                'provider_latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'accepted_at'         => date('Y-m-d H:i:s'),
            ]);
            return ['stop' => null];
        } catch (SendSkippedException $e) {
            $this->updateAttempt($attemptId, [
                'status' => 'skipped', 'failure_class' => 'skipped', 'failure_detail' => $e->getMessage(), 'failed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->record(AuditLogger::DISTRIBUTION_SEND_SKIPPED, ['dispatch_id' => $dispatchId, 'channel' => $channel, 'reason' => $e->getMessage()], null);
            return ['stop' => $e->getMessage()];
        } catch (ProviderNotConfiguredException $e) {
            $this->updateAttempt($attemptId, [
                'status' => 'failed', 'failure_class' => 'not_configured', 'failure_detail' => $e->getMessage(), 'failed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->audit->record(AuditLogger::DISTRIBUTION_PROVIDER_NOT_CONFIGURED, ['dispatch_id' => $dispatchId, 'channel' => $channel, 'reason' => $e->getMessage()], null);
            return ['stop' => $e->getMessage()];
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            if ($code === 429) {
                // Nothing was sent: drop the in-flight row so the recipient is retried.
                $this->db->table('reach_campaign_delivery_attempts')->where('id', $attemptId)->delete();
                return ['stop' => 'rate_limit'];
            }
            $class = match (true) {
                $code === 422            => 'rejected',
                $code >= 400 && $code < 500 => 'permanent',
                $code >= 500             => 'transient',
                default                  => 'unknown',
            };
            $this->updateAttempt($attemptId, [
                'status' => 'failed', 'failure_class' => $class, 'failure_detail' => substr($e->getMessage(), 0, 2000), 'failed_at' => date('Y-m-d H:i:s'),
            ]);
            return ['stop' => null];
        }
    }

    // ── finalise / reconcile ────────────────────────────────────────────────

    /**
     * @return array<string,mixed> the final dispatch row
     */
    public function finalize(int $dispatchId, ?string $systemicError): array
    {
        $counts   = $this->refreshCounts($dispatchId);
        $dispatch = $this->dispatchRow($dispatchId);
        $notTried = max(0, (int) $dispatch['total_recipients'] - $counts['attempted']);

        if ($counts['accepted'] > 0 && $counts['failed'] === 0 && $counts['skipped'] === 0 && $notTried === 0) {
            $status = 'completed';
        } elseif ($counts['accepted'] > 0) {
            $status = 'partially_completed';
        } else {
            $status = 'failed';
        }
        $error = $systemicError;
        if ($error === null && $status === 'failed') {
            $error = $counts['attempted'] === 0 ? 'Nothing was sent.' : 'No message was accepted by the provider.';
        }

        $this->db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update([
            'status'       => $status,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'last_error'   => $error,
        ]);

        $this->audit->record($status === 'failed' ? AuditLogger::DISTRIBUTION_DISPATCH_FAILED : AuditLogger::DISTRIBUTION_DISPATCH_COMPLETED, [
            'dispatch_id' => $dispatchId,
            'channel'     => $dispatch['channel'],
            'status'      => $status,
            'accepted'    => $counts['accepted'],
            'failed'      => $counts['failed'],
            'skipped'     => $counts['skipped'],
            'not_tried'   => $notTried,
            'error'       => $error,
        ], null);

        $this->settleSource($dispatch, $status, $counts, $error);
        $this->settleParentCampaign((int) $dispatch['campaign_id']);

        return $this->dispatchRow($dispatchId);
    }

    /**
     * Repair a dispatch from local data: fail attempts stuck in `sending` (the
     * worker died mid-call; the provider outcome is unknown), queue a batch
     * when recipients remain, or finalise when nothing remains.
     *
     * @return array<string,mixed>
     */
    public function reconcile(int $dispatchId): array
    {
        $dispatch = $this->dispatchRow($dispatchId);
        $stale    = date('Y-m-d H:i:s', time() - 900);
        $stuck    = $this->db->table('reach_campaign_delivery_attempts')
            ->where('dispatch_id', $dispatchId)->where('status', 'sending')->where('created_at <', $stale)
            ->update([
                'status'         => 'failed',
                'failure_class'  => 'unknown',
                'failure_detail' => 'Worker interrupted mid-send; provider outcome unknown. Not retried to avoid a duplicate send.',
                'failed_at'      => date('Y-m-d H:i:s'),
            ]);
        $stuckCount = $stuck ? $this->db->affectedRows() : 0;

        if (! in_array($dispatch['status'], ['queued', 'dispatching'], true)) {
            $this->refreshCounts($dispatchId);
            return ['dispatch_id' => $dispatchId, 'action' => 'none', 'status' => $dispatch['status'], 'stuck_failed' => $stuckCount];
        }
        if ($this->pendingRecipients($dispatchId, (int) $dispatch['snapshot_id'], 1) !== []) {
            $jobId = $this->enqueueBatch($dispatchId, (string) $dispatch['channel'], 0);
            return ['dispatch_id' => $dispatchId, 'action' => 'requeued', 'job_id' => $jobId, 'stuck_failed' => $stuckCount];
        }
        $inFlight = $this->db->table('reach_campaign_delivery_attempts')->where('dispatch_id', $dispatchId)->where('status', 'sending')->countAllResults();
        if ($inFlight > 0) {
            return ['dispatch_id' => $dispatchId, 'action' => 'waiting', 'in_flight' => $inFlight];
        }
        $final = $this->finalize($dispatchId, null);

        return ['dispatch_id' => $dispatchId, 'action' => 'finalized', 'status' => $final['status'], 'stuck_failed' => $stuckCount];
    }

    /**
     * One message to an explicitly named test address. Recorded as a delivery
     * attempt with no dispatch; the campaign's status is not touched.
     *
     * @return array<string,mixed>
     */
    public function testSend(string $sourceType, int $sourceId, int $tenantId, string $address, ?int $actorId): array
    {
        $source  = $this->loadSource($sourceType, $sourceId);
        $channel = $source['channel'];
        if ((int) ($source['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException(ucfirst($channel) . ' campaign not found.', 404);
        }
        $address = $channel === 'email' ? strtolower(trim($address)) : AudienceResolverService::normalisePhone($address);
        if ($address === '' || ($channel === 'email' && ! filter_var($address, FILTER_VALIDATE_EMAIL))) {
            throw new \RuntimeException('A valid test address is required.', 422);
        }
        $this->validateContent($channel, $source['content']);

        $sender = $this->sender($channel);
        $key    = substr('test:' . $sourceType . ':' . $sourceId . ':' . bin2hex(random_bytes(6)), 0, 128);
        $message = $this->buildMessage($channel, $source['content'], $key, $address, null, $tenantId, true);
        $attemptId = $this->insertAttempt([
            'dispatch_id' => null, 'attempt_number' => 1, 'status' => 'sending',
            'provider' => $sender->providerName(), 'idempotency_key' => $key, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $receipt = $sender->send($message);
        } catch (SendSkippedException $e) {
            $this->updateAttempt($attemptId, ['status' => 'skipped', 'failure_class' => 'skipped', 'failure_detail' => $e->getMessage(), 'failed_at' => date('Y-m-d H:i:s')]);
            throw $e;
        } catch (\Throwable $e) {
            $this->updateAttempt($attemptId, [
                'status' => 'failed',
                'failure_class' => $e instanceof ProviderNotConfiguredException ? 'not_configured' : 'unknown',
                'failure_detail' => substr($e->getMessage(), 0, 2000),
                'failed_at' => date('Y-m-d H:i:s'),
            ]);
            throw $e;
        }
        $this->updateAttempt($attemptId, ['status' => 'accepted', 'provider_message_id' => $receipt->providerMessageId, 'accepted_at' => date('Y-m-d H:i:s')]);
        $this->audit->record(AuditLogger::DISTRIBUTION_TEST_SEND, [
            'source' => $sourceType . ':' . $sourceId, 'channel' => $channel,
            'address_masked' => ChannelSuppressionModel::maskAddress($address), 'provider' => $sender->providerName(),
        ], $actorId);

        return [
            'status'              => 'accepted',
            'test'                => true,
            'provider'            => $sender->providerName(),
            'provider_message_id' => $receipt->providerMessageId,
            'to_masked'           => ChannelSuppressionModel::maskAddress($address),
            'mock'                => $this->isMock($sender),
        ];
    }

    // ── sources ─────────────────────────────────────────────────────────────

    /**
     * @return array{channel:string, status:string, tenant_id:int, campaign_id:?int, campaign_version_id:?int, criteria:array, content:array, source_type:string, source_id:int, row:array}
     */
    public function loadSource(string $sourceType, int $sourceId): array
    {
        switch ($sourceType) {
            case self::SOURCE_EMAIL_CAMPAIGN:
                $row = $this->db->table('reach_email_campaigns')->where('id', $sourceId)->get()->getRowArray();
                if ($row === null) {
                    throw new \RuntimeException('Email campaign not found.', 404);
                }
                return [
                    'channel' => 'email', 'status' => (string) $row['status'], 'tenant_id' => (int) ($row['tenant_id'] ?? 0),
                    'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null, 'campaign_version_id' => null,
                    'criteria' => self::json($row['audience_filter'] ?? null),
                    'content' => [
                        'subject' => (string) ($row['subject'] ?? ''), 'body_html' => (string) ($row['body_html'] ?? ''),
                        'body_text' => (string) ($row['body_text'] ?? ''), 'name' => 'Email: ' . (string) ($row['subject'] ?? ''),
                    ],
                    'source_type' => $sourceType, 'source_id' => $sourceId, 'row' => $row,
                ];
            case self::SOURCE_WHATSAPP_CAMPAIGN:
                $row = $this->db->table('reach_whatsapp_campaigns')->where('id', $sourceId)->get()->getRowArray();
                if ($row === null) {
                    throw new \RuntimeException('WhatsApp campaign not found.', 404);
                }
                $params = self::json($row['template_params'] ?? null);
                return [
                    'channel' => 'whatsapp', 'status' => (string) $row['status'], 'tenant_id' => (int) ($row['tenant_id'] ?? 0),
                    'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null, 'campaign_version_id' => null,
                    'criteria' => self::json($row['audience_filter'] ?? null),
                    'content' => [
                        'template_name' => (string) ($row['template_name'] ?? ''),
                        'placeholders'  => array_is_list($params) ? $params : (array) ($params['placeholders'] ?? []),
                        'language'      => array_is_list($params) ? 'en' : (string) ($params['language'] ?? 'en'),
                        'name'          => 'WhatsApp: ' . (string) ($row['template_name'] ?? ''),
                    ],
                    'source_type' => $sourceType, 'source_id' => $sourceId, 'row' => $row,
                ];
            case self::SOURCE_SMS_CAMPAIGN:
                $campaign = $this->db->table('reach_campaigns')->where('id', $sourceId)->get()->getRowArray();
                $sms      = $this->db->table('reach_sms_campaigns')->where('campaign_id', $sourceId)->orderBy('id', 'DESC')->get()->getRowArray();
                if ($campaign === null || $sms === null) {
                    throw new \RuntimeException('SMS campaign not found.', 404);
                }
                $vars = self::json($sms['template_variables'] ?? null);
                return [
                    'channel' => 'sms', 'status' => (string) $campaign['status'], 'tenant_id' => (int) ($sms['tenant_id'] ?? 0),
                    'campaign_id' => (int) $campaign['id'], 'campaign_version_id' => null,
                    'criteria' => self::json($sms['audience_filter'] ?? null),
                    'content' => [
                        'body' => trim((string) ($vars['body'] ?? $campaign['creative_copy'] ?? '')),
                        'dlt_entity_id' => (string) ($sms['dlt_entity_id'] ?? ''), 'dlt_template_id' => (string) ($sms['dlt_template_id'] ?? ''),
                        'dlt_sender_id' => (string) ($sms['dlt_sender_id'] ?? ''), 'name' => (string) ($campaign['name'] ?? ''),
                    ],
                    'source_type' => $sourceType, 'source_id' => $sourceId, 'row' => $sms + ['_campaign' => $campaign],
                ];
            case self::SOURCE_VARIANT:
                $variant = $this->db->table('reach_campaign_channel_variants')->where('id', $sourceId)->get()->getRowArray();
                if ($variant === null) {
                    throw new \RuntimeException('Channel variant not found.', 404);
                }
                $version  = $this->db->table('reach_campaign_versions')->where('id', (int) $variant['campaign_version_id'])->get()->getRowArray();
                $campaign = $version !== null ? $this->db->table('reach_campaigns')->where('id', (int) $version['campaign_id'])->get()->getRowArray() : null;
                if ($campaign === null) {
                    throw new \RuntimeException('Campaign for channel variant not found.', 404);
                }
                $content = self::json($variant['content_json'] ?? null);
                $status  = (string) $campaign['status'];
                return [
                    'channel' => (string) $variant['channel'],
                    // fan-out moves the campaign to `dispatching` before the per-channel starts
                    'status' => in_array($status, ['dispatching'], true) ? 'approved' : $status,
                    'tenant_id' => (int) ($campaign['tenant_id'] ?? 0),
                    'campaign_id' => (int) $campaign['id'], 'campaign_version_id' => (int) $variant['campaign_version_id'],
                    'criteria' => self::json($content['audience'] ?? $campaign['target_audience'] ?? null),
                    'content' => $content + [
                        'subject' => (string) ($content['subject'] ?? ''), 'body_html' => (string) ($content['body_html'] ?? $content['html'] ?? ''),
                        'body_text' => (string) ($content['body_text'] ?? $content['text'] ?? ''), 'body' => (string) ($content['body'] ?? $content['text'] ?? ''),
                        'template_name' => (string) ($content['template_name'] ?? ''), 'placeholders' => (array) ($content['placeholders'] ?? []),
                        'language' => (string) ($content['language'] ?? 'en'),
                        'dlt_entity_id' => (string) ($content['dlt_entity_id'] ?? ''), 'dlt_template_id' => (string) ($content['dlt_template_id'] ?? ''),
                        'dlt_sender_id' => (string) ($content['dlt_sender_id'] ?? ''), 'name' => (string) ($campaign['name'] ?? ''),
                    ],
                    'source_type' => $sourceType, 'source_id' => $sourceId, 'row' => $variant,
                ];
        }

        throw new \InvalidArgumentException("Unknown dispatch source '{$sourceType}'.");
    }

    /** @param array<string,mixed> $content */
    public function validateContent(string $channel, array $content): void
    {
        if ($channel === 'email') {
            if (trim((string) $content['subject']) === '' || (trim((string) $content['body_html']) === '' && trim((string) $content['body_text']) === '')) {
                throw new \RuntimeException('Email campaign needs a subject and a body.', 422);
            }
            if (trim((string) ReachConfig::get('distribution.email.postal_address', '')) === '') {
                throw new \RuntimeException('Set the postal address for the email footer (Settings → Distribution → distribution.email.postal_address) before sending campaign email.', 422);
            }
            if (! UnsubscribeLinks::isAvailable()) {
                throw new \RuntimeException('Unsubscribe links cannot be generated: set REACH_APP_URL (or distribution.email.unsubscribe_base_url) and REACH_SETTINGS_KEY/JWT_SECRET.', 422);
            }
            return;
        }
        if ($channel === 'sms') {
            foreach (['dlt_entity_id', 'dlt_template_id', 'dlt_sender_id'] as $field) {
                if (trim((string) ($content[$field] ?? '')) === '') {
                    throw new \RuntimeException("DLT compliance: {$field} is required for SMS (TRAI).", 422);
                }
            }
            $body = (string) ($content['body'] ?? '');
            if ($body === '') {
                throw new \RuntimeException('SMS body is empty (template_variables.body must hold the DLT-approved text).', 422);
            }
            if (mb_strlen($body) > self::SMS_MAX_BODY) {
                throw new \RuntimeException('SMS body exceeds ' . self::SMS_MAX_BODY . ' characters. Shorten the message.', 422);
            }
            return;
        }
        if ($channel === 'whatsapp') {
            if (trim((string) ($content['template_name'] ?? '')) === '') {
                throw new \RuntimeException('WhatsApp campaign must name an approved template. Freeform messages are not permitted.', 422);
            }
            return;
        }

        throw new \RuntimeException("Channel '{$channel}' is not dispatched by this service.", 422);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function assertProviderUsable(string $channel): void
    {
        $sender = $this->sender($channel);
        if ($this->isMock($sender) && ProviderGuard::isProduction()) {
            $need = match ($channel) {
                'email'    => 'set EMAIL_API_KEY and SENDER_EMAIL',
                'sms'      => 'set SMS_API_KEY and SMS_SENDER_ID',
                default    => 'set WHATSAPP_API_KEY and WHATSAPP_WABA_ID',
            };
            throw new ProviderNotConfiguredException(ucfirst($channel) . " provider not configured: {$need}. Nothing was queued.");
        }
    }

    private function isMock(object $sender): bool
    {
        return $sender instanceof MockEmailSender || $sender instanceof MockSmsSender || $sender instanceof MockWhatsAppSender
            || str_starts_with((string) (method_exists($sender, 'providerName') ? $sender->providerName() : ''), 'mock');
    }

    private function sender(string $channel): object
    {
        if (isset($this->senders[$channel])) {
            return $this->senders[$channel];
        }
        if ($this->senderFactory !== null) {
            return $this->senders[$channel] = ($this->senderFactory)($channel);
        }

        return $this->senders[$channel] = match ($channel) {
            'email'    => ChannelProviderFactory::makeEmailSender(),
            'sms'      => ChannelProviderFactory::makeSmsSender(),
            'whatsapp' => ChannelProviderFactory::makeWhatsAppSender(),
            default    => throw new \RuntimeException("No sender for channel '{$channel}'.", 422),
        };
    }

    private function buildMessage(string $channel, array $content, string $key, string $address, ?array $lead, int $tenantId, bool $test = false): ChannelMessage
    {
        if ($channel === 'email') {
            $unsubscribe = (string) UnsubscribeLinks::url($tenantId, $address);
            $postal      = trim((string) ReachConfig::get('distribution.email.postal_address', ''));
            $html        = (string) $content['body_html'];
            $text        = (string) $content['body_text'];
            if ($html === '') {
                $html = nl2br(htmlspecialchars($text, ENT_QUOTES));
            }
            $html = str_replace('{{unsubscribe_url}}', htmlspecialchars($unsubscribe, ENT_QUOTES), $html) . UnsubscribeLinks::footerHtml($unsubscribe, $postal);
            $text = $text !== '' ? str_replace('{{unsubscribe_url}}', $unsubscribe, $text) . UnsubscribeLinks::footerText($unsubscribe, $postal) : '';
            $subject = (string) $content['subject'];

            return new ChannelMessage(
                idempotencyKey: $key,
                recipientAddress: $address,
                content: $html,
                metadata: ['subject' => $test ? '[TEST] ' . $subject : $subject, 'body_text' => $text, 'unsubscribe_url' => $unsubscribe],
            );
        }
        if ($channel === 'sms') {
            return new ChannelMessage(
                idempotencyKey: $key,
                recipientAddress: $address,
                content: (string) $content['body'],
                templateId: (string) $content['dlt_template_id'],
                metadata: [
                    'dlt_entity_id' => $content['dlt_entity_id'], 'dlt_template_id' => $content['dlt_template_id'], 'dlt_sender_id' => $content['dlt_sender_id'],
                ],
            );
        }
        $placeholders = array_map(
            static fn ($p) => str_replace('{{name}}', (string) ($lead['name'] ?? ''), (string) $p),
            array_values((array) ($content['placeholders'] ?? []))
        );

        return new ChannelMessage(
            idempotencyKey: $key,
            recipientAddress: $address,
            content: '',
            templateId: (string) $content['template_name'],
            templateVars: $placeholders,
            metadata: ['template_name' => $content['template_name'], 'language' => $content['language'] ?? 'en'],
        );
    }

    /** @return list<array<string,mixed>> */
    private function pendingRecipients(int $dispatchId, int $snapshotId, int $limit): array
    {
        return $this->db->query(
            "SELECT r.* FROM reach_campaign_audience_recipients r
              WHERE r.snapshot_id = ? AND r.eligibility_status = 'eligible' AND r.suppressed = false
                AND NOT EXISTS (SELECT 1 FROM reach_campaign_delivery_attempts a WHERE a.dispatch_id = ? AND a.recipient_id = r.id)
              ORDER BY r.id ASC LIMIT " . (int) $limit,
            [$snapshotId, $dispatchId]
        )->getResultArray();
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string,mixed>>
     */
    private function leadsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table('reach_leads')->select('id, name, email, mobile, whatsapp')->whereIn('id', $ids)->get()->getResultArray() as $lead) {
            $out[(int) $lead['id']] = $lead;
        }

        return $out;
    }

    /** @return array{accepted:int, failed:int, skipped:int, late_suppressed:int, attempted:int} */
    private function refreshCounts(int $dispatchId): array
    {
        $rows = $this->db->query(
            'SELECT status, COUNT(*) AS n FROM reach_campaign_delivery_attempts WHERE dispatch_id = ? GROUP BY status',
            [$dispatchId]
        )->getResultArray();
        $by = [];
        foreach ($rows as $row) {
            $by[(string) $row['status']] = (int) $row['n'];
        }
        $accepted = ($by['accepted'] ?? 0) + ($by['sent'] ?? 0) + ($by['delivered'] ?? 0) + ($by['read'] ?? 0);
        $failed   = ($by['failed'] ?? 0) + ($by['bounced'] ?? 0) + ($by['complained'] ?? 0);
        $counts   = [
            'accepted'        => $accepted,
            'failed'          => $failed,
            'skipped'         => $by['skipped'] ?? 0,
            'late_suppressed' => ($by['suppressed'] ?? 0) + ($by['unsubscribed'] ?? 0),
            'attempted'       => array_sum($by) - ($by['sending'] ?? 0),
        ];

        $dispatch = $this->dispatchRow($dispatchId);
        $snapshotSuppressed = 0;
        if (! empty($dispatch['snapshot_id'])) {
            $snapshotSuppressed = (int) ($this->db->table('reach_campaign_audience_snapshots')->select('suppressed_count')
                ->where('id', (int) $dispatch['snapshot_id'])->get()->getRowArray()['suppressed_count'] ?? 0);
        }
        $this->db->table('reach_campaign_dispatches')->where('id', $dispatchId)->update([
            'sent_count'       => $counts['accepted'],
            'failed_count'     => $counts['failed'],
            'skipped_count'    => $counts['skipped'],
            'suppressed_count' => $snapshotSuppressed + $counts['late_suppressed'],
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return $counts;
    }

    private function dispatchRow(int $dispatchId): array
    {
        $row = $this->db->table('reach_campaign_dispatches')->where('id', $dispatchId)->get()->getRowArray();
        if ($row === null) {
            throw new \RuntimeException('Dispatch not found.', 404);
        }

        return $row;
    }

    private function insertAttempt(array $row): int
    {
        $this->db->table('reach_campaign_delivery_attempts')->insert($row);

        return (int) $this->db->insertID();
    }

    private function updateAttempt(int $id, array $fields): void
    {
        $this->db->table('reach_campaign_delivery_attempts')->where('id', $id)->update($fields);
    }

    private function enqueueBatch(int $dispatchId, string $channel, int $batchNo, int $delaySeconds = 0): int
    {
        $opts = [
            'queue'           => self::QUEUE,
            'max_attempts'    => 3,
            'priority'        => 60,
            'idempotency_key' => 'dispatch:' . $dispatchId . ':batch:' . $batchNo . ':' . bin2hex(random_bytes(3)),
        ];
        if ($delaySeconds > 0) {
            $opts['available_at'] = date('Y-m-d H:i:s', time() + $delaySeconds);
        }

        return $this->jobs->enqueue(self::JOB_TYPES[$channel], ['dispatch_id' => $dispatchId, 'batch' => $batchNo], $opts);
    }

    private function ensureParentCampaign(array $source, int $tenantId, ?int $actorId): int
    {
        if (! empty($source['campaign_id'])) {
            return (int) $source['campaign_id'];
        }
        // Email / WhatsApp campaigns may stand alone; a dispatch needs a parent
        // reach_campaigns row (FK), so create one and link it.
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_campaigns')->insert([
            'name'          => substr((string) ($source['content']['name'] ?? ucfirst($source['channel']) . ' campaign'), 0, 200) ?: 'Campaign',
            'campaign_type' => $source['channel'] === 'email' ? 'email' : 'whatsapp',
            'status'        => 'approved',
            'tenant_id'     => $tenantId,
            'channels'      => json_encode([$source['channel']]),
            'created_by'    => $actorId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $id    = (int) $this->db->insertID();
        $table = $source['source_type'] === self::SOURCE_EMAIL_CAMPAIGN ? 'reach_email_campaigns' : 'reach_whatsapp_campaigns';
        $this->db->table($table)->where('id', (int) $source['source_id'])->update(['campaign_id' => $id]);

        return $id;
    }

    private function markSourceSending(array $source): void
    {
        $now = date('Y-m-d H:i:s');
        switch ($source['source_type']) {
            case self::SOURCE_EMAIL_CAMPAIGN:
                $this->db->table('reach_email_campaigns')->where('id', $source['source_id'])->update(['status' => 'sending', 'updated_at' => $now]);
                break;
            case self::SOURCE_WHATSAPP_CAMPAIGN:
                $this->db->table('reach_whatsapp_campaigns')->where('id', $source['source_id'])->update(['status' => 'sending', 'updated_at' => $now]);
                break;
            case self::SOURCE_SMS_CAMPAIGN:
                $this->db->table('reach_sms_campaigns')->where('id', (int) $source['row']['id'])->update(['status' => 'sending', 'updated_at' => $now]);
                break;
        }
        if (! empty($source['campaign_id'])) {
            $this->db->table('reach_campaigns')->where('id', (int) $source['campaign_id'])
                ->whereIn('status', ['approved', 'scheduled'])
                ->update(['status' => 'dispatching', 'updated_at' => $now]);
        }
    }

    /** @param array{accepted:int, failed:int, skipped:int, late_suppressed:int, attempted:int} $counts */
    private function settleSource(array $dispatch, string $status, array $counts, ?string $error): void
    {
        $now   = date('Y-m-d H:i:s');
        $sent  = $counts['accepted'] > 0;
        $stats = json_encode([
            'dispatch_id' => (int) $dispatch['id'],
            'outcome'     => $status,
            'accepted'    => $counts['accepted'],
            'failed'      => $counts['failed'],
            'skipped'     => $counts['skipped'],
            'suppressed'  => (int) ($dispatch['suppressed_count'] ?? 0),
            'eligible'    => (int) $dispatch['total_recipients'],
            'error'       => $error,
            'finished_at' => date('c'),
        ]);
        $fields = ['status' => $sent ? 'sent' : 'failed', 'stats' => $stats, 'updated_at' => $now] + ($sent ? ['sent_at' => $now] : []);

        switch ($dispatch['source_type']) {
            case self::SOURCE_EMAIL_CAMPAIGN:
                $this->db->table('reach_email_campaigns')->where('id', (int) $dispatch['source_id'])->update($fields + ['dispatch_id' => (int) $dispatch['id']]);
                break;
            case self::SOURCE_WHATSAPP_CAMPAIGN:
                $this->db->table('reach_whatsapp_campaigns')->where('id', (int) $dispatch['source_id'])->update($fields + ['dispatch_id' => (int) $dispatch['id']]);
                break;
            case self::SOURCE_SMS_CAMPAIGN:
                $this->db->table('reach_sms_campaigns')->where('campaign_id', (int) $dispatch['source_id'])->update($fields + ['dispatch_id' => (int) $dispatch['id']]);
                break;
        }
    }

    /** Parent campaign status follows its dispatches once all of them are finished. */
    public function settleParentCampaign(int $campaignId): void
    {
        $rows = $this->db->table('reach_campaign_dispatches')->select('status')->where('campaign_id', $campaignId)->get()->getResultArray();
        $statuses = array_column($rows, 'status');
        if ($statuses === [] || array_intersect($statuses, ['queued', 'dispatching', 'paused']) !== []) {
            return;
        }
        $status = match (true) {
            array_diff($statuses, ['completed']) === []                       => 'completed',
            array_intersect($statuses, ['completed', 'partially_completed']) !== [] => 'partially_completed',
            default                                                         => 'failed',
        };
        $this->db->table('reach_campaigns')->where('id', $campaignId)
            ->whereIn('status', ['approved', 'scheduled', 'dispatching', 'partially_completed'])
            ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string,mixed> */
    private function snapshotSummary(array $snapshot): array
    {
        return [
            'snapshot_id'     => (int) $snapshot['id'],
            'recipient_count' => (int) $snapshot['recipient_count'],
            'eligible_count'  => (int) $snapshot['eligible_count'],
            'breakdown'       => $snapshot['breakdown'] ?? [],
        ];
    }

    private function describeBreakdown(array $b): string
    {
        $parts = [];
        foreach (['suppressed' => 'suppressed', 'no_consent' => 'without consent', 'invalid_address' => 'without a valid address', 'duplicate' => 'duplicates', 'over_cap' => 'over cap'] as $k => $label) {
            if (! empty($b[$k])) {
                $parts[] = $b[$k] . ' ' . $label;
            }
        }

        return ($b['total'] ?? 0) . ' selected' . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '');
    }

    /** @return array<string,mixed> */
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true); // tolerate legacy double-encoding
            }
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
