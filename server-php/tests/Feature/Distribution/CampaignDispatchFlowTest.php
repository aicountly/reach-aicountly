<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\AudienceResolverService;
use App\Libraries\Distribution\ChannelDispatchService;
use App\Libraries\Distribution\DistributionAuditService;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\ProviderReceipt;
use App\Libraries\Distribution\Providers\SendSkippedException;
use App\Libraries\Distribution\SocialPublisherService;
use App\Libraries\JobHandlerRegistry;
use App\Libraries\JobService;
use App\Libraries\Settings\ReachConfig;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;
use App\Models\Distribution\ChannelSuppressionModel;
use Tests\Support\DatabaseTestCase;

/**
 * Campaign readiness — real audiences, batched job dispatch, honest statuses.
 *
 * @internal
 */
final class CampaignDispatchFlowTest extends DatabaseTestCase
{
    /** @var list<ChannelMessage> */
    private array $sent = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $this->setEnv('REACH_APP_URL', 'https://reach.example.test');
        $this->setEnv('DISTRIBUTION_POSTAL_ADDRESS', 'AICOUNTLY, 1 Test Street, Pune 411001');
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        ProviderGuard::forceProduction(null);
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        $this->savedEnv = [];
        ReachConfig::flush();
        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function lead(string $name, ?string $email = null, ?string $mobile = null): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_leads')->insert(['name' => $name, 'email' => $email, 'mobile' => $mobile]);

        return (int) $db->insertID();
    }

    private function consent(int $leadId, string $channel, string $status): void
    {
        \Config\Database::connect()->table('reach_channel_consents')->insert([
            'tenant_id' => 0, 'subject_type' => 'lead', 'subject_id' => $leadId, 'channel' => $channel,
            'purpose' => 'marketing', 'status' => $status, 'source' => 'test', 'captured_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function suppress(string $channel, string $address): void
    {
        \Config\Database::connect()->table('reach_channel_suppressions')->insert([
            'tenant_id' => 0, 'channel' => $channel,
            'address_hash' => ChannelSuppressionModel::hashAddress(0, $channel, $address),
            'address_masked' => ChannelSuppressionModel::maskAddress($address), 'reason' => 'unsubscribe', 'source' => 'test',
        ]);
    }

    private function emailCampaign(array $audience, string $status = 'approved'): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_email_campaigns')->insert([
            'subject' => 'Launch day', 'body_html' => '<p>Hello from AICOUNTLY</p>', 'body_text' => 'Hello from AICOUNTLY',
            'audience_filter' => json_encode($audience), 'status' => $status, 'tenant_id' => 0,
        ]);

        return (int) $db->insertID();
    }

    private function smsCampaign(array $audience): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_campaigns')->insert(['name' => 'SMS launch', 'campaign_type' => 'multi', 'status' => 'approved', 'tenant_id' => 0, 'creative_copy' => 'AICOUNTLY is live. Visit aicountly.com']);
        $campaignId = (int) $db->insertID();
        $db->table('reach_sms_campaigns')->insert([
            'campaign_id' => $campaignId, 'tenant_id' => 0, 'status' => 'approved',
            'dlt_entity_id' => '1101000000000001', 'dlt_template_id' => '1107000000000001', 'dlt_sender_id' => 'AICNTY',
            'audience_filter' => json_encode($audience),
        ]);

        return $campaignId;
    }

    /** A sender double that records what it was asked to send. */
    private function recordingSender(?\Throwable $throw = null): object
    {
        $test = $this;

        return new class ($test, $throw) {
            public function __construct(private $test, private ?\Throwable $throw)
            {
            }

            public function send(ChannelMessage $m): ProviderReceipt
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                $this->test->record($m);

                return new ProviderReceipt('prov-' . substr(md5($m->idempotencyKey), 0, 10), 'accepted', new \DateTimeImmutable());
            }

            public function providerName(): string
            {
                return 'fake_provider';
            }
        };
    }

    public function record(ChannelMessage $m): void
    {
        $this->sent[] = $m;
    }

    private function service(?object $sender = null): ChannelDispatchService
    {
        $sender ??= $this->recordingSender();

        return new ChannelDispatchService(new JobService(), null, new AuditLogger(), static fn (string $channel) => $sender);
    }

    private function row(string $table, int $id): array
    {
        return \Config\Database::connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    // ── tests ───────────────────────────────────────────────────────────────

    public function testEmailCampaignResolvesRealRecipientsHonouringSuppressionAndDedupe(): void
    {
        $a = $this->lead('Asha', 'asha@example.com');
        $this->lead('Asha again', 'ASHA@example.com');           // duplicate address
        $this->lead('Bina', 'bina@example.com');
        $c = $this->lead('Chetan', 'chetan@example.com');         // unsubscribed
        $d = $this->lead('Dev', 'dev@example.com');               // revoked consent
        $this->lead('No email', null, '9876543210');               // no address
        $this->suppress('email', 'chetan@example.com');
        $this->consent($d, 'email', 'revoked');

        $campaignId = $this->emailCampaign(['all_leads' => true]);
        $svc        = $this->service();
        $start      = $svc->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, 0, null);

        $this->assertSame('queued', $start['status']);
        $this->assertSame('distribution', $start['queue']);
        $this->assertSame(2, $start['snapshot']['eligible_count']);
        $this->assertSame(1, $start['snapshot']['breakdown']['suppressed']);
        $this->assertSame(1, $start['snapshot']['breakdown']['no_consent']);
        $this->assertSame(1, $start['snapshot']['breakdown']['duplicate']);
        $this->assertSame(1, $start['snapshot']['breakdown']['invalid_address']);

        $dispatch = $this->row('reach_campaign_dispatches', $start['dispatch_id']);
        $this->assertSame('queued', $dispatch['status'], "dispatch rows use the enum's 'queued', not 'pending'");
        $this->assertSame('sending', $this->row('reach_email_campaigns', $campaignId)['status']);

        $job = \Config\Database::connect()->table('reach_jobs')->where('id', $start['job_id'])->get()->getRowArray();
        $this->assertSame('distribution.dispatch.email', $job['job_type']);
        $this->assertSame('distribution', $job['queue']);

        $result = $svc->processBatch($start['dispatch_id']);
        $this->assertSame('finished', $result['status']);

        $to = array_map(static fn (ChannelMessage $m) => $m->recipientAddress, $this->sent);
        sort($to);
        $this->assertSame(['asha@example.com', 'bina@example.com'], $to);
        $this->assertNotContains('list@broadcast', $to);
        $this->assertStringContainsString('/api/v1/public/unsubscribe?', $this->sent[0]->content);
        $this->assertStringContainsString('1 Test Street', $this->sent[0]->content);

        $dispatch = $this->row('reach_campaign_dispatches', $start['dispatch_id']);
        $this->assertSame('completed', $dispatch['status']);
        $this->assertSame(2, (int) $dispatch['sent_count']);
        $this->assertSame(1, (int) $dispatch['suppressed_count']);
        $attempts = \Config\Database::connect()->table('reach_campaign_delivery_attempts')->where('dispatch_id', $start['dispatch_id'])->get()->getResultArray();
        $this->assertCount(2, $attempts, 'one delivery attempt per recipient');
        $this->assertSame(['accepted'], array_values(array_unique(array_column($attempts, 'status'))));

        $email = $this->row('reach_email_campaigns', $campaignId);
        $this->assertSame('sent', $email['status']);
        $this->assertNotNull($email['campaign_id'], 'a parent campaign is linked for the dispatch FK');
        $this->assertSame('completed', $this->row('reach_campaigns', (int) $email['campaign_id'])['status']);

        // A sent campaign is not sent again.
        try {
            $svc->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, 0, null);
            $this->fail('A sent campaign must not be dispatched twice.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        unset($a, $c);
    }

    public function testCampaignWithoutAnExplicitAudienceIsRefused(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $campaignId = $this->emailCampaign([]);

        $this->expectExceptionCode(422);
        $this->service()->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, 0, null);
    }

    public function testProductionWithOnlyAMockProviderRefusesToQueue(): void
    {
        ProviderGuard::forceProduction(true);
        $this->lead('Asha', 'asha@example.com');
        $campaignId = $this->emailCampaign(['all_leads' => true]);

        try {
            (new ChannelDispatchService())->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, 0, null);
            $this->fail('No real email provider is configured; the send must not be queued.');
        } catch (ProviderNotConfiguredException $e) {
            $this->assertSame(503, $e->getCode());
        }
        $this->assertSame('approved', $this->row('reach_email_campaigns', $campaignId)['status']);
        $this->assertSame(0, \Config\Database::connect()->table('reach_campaign_dispatches')->countAllResults());
    }

    public function testSmsNeedsOptInAndSkipSendIsNeverReportedSent(): void
    {
        $opted = $this->lead('Opted in', null, '9876543210');
        $this->lead('No consent', null, '9876500000');
        $revoked = $this->lead('Revoked', null, '9876511111');
        $this->consent($opted, 'sms', 'granted');
        $this->consent($revoked, 'sms', 'granted');
        $this->consent($revoked, 'sms', 'revoked');

        $campaignId = $this->smsCampaign(['all_leads' => true]);
        $svc   = $this->service($this->recordingSender(new SendSkippedException('SMS_SKIP_SEND is on — SMS not sent.')));
        $start = $svc->start(ChannelDispatchService::SOURCE_SMS_CAMPAIGN, $campaignId, 0, null);
        $this->assertSame(1, $start['snapshot']['eligible_count']);
        $this->assertSame(2, $start['snapshot']['breakdown']['no_consent']);

        $result = $svc->processBatch($start['dispatch_id']);
        $this->assertSame('stopped', $result['status']);

        $attempt = \Config\Database::connect()->table('reach_campaign_delivery_attempts')->where('dispatch_id', $start['dispatch_id'])->get()->getRowArray();
        $this->assertSame('skipped', $attempt['status']);
        $dispatch = $this->row('reach_campaign_dispatches', $start['dispatch_id']);
        $this->assertSame('failed', $dispatch['status']);
        $this->assertStringContainsString('SMS_SKIP_SEND', (string) $dispatch['last_error']);
        $this->assertSame('failed', $this->row('reach_campaigns', $campaignId)['status']);
        $sms = \Config\Database::connect()->table('reach_sms_campaigns')->where('campaign_id', $campaignId)->get()->getRowArray();
        $this->assertSame('failed', $sms['status']);
        $this->assertNull($sms['sent_at']);
    }

    public function testBatchesRunThroughTheRegisteredJobHandler(): void
    {
        ReachConfig::set('distribution.batch_size', 2);
        foreach (['a', 'b', 'c'] as $n) {
            $this->lead($n, "{$n}@example.com");
        }
        $campaignId = $this->emailCampaign(['filters' => [['field' => 'email', 'operator' => 'ends_with', 'value' => '@example.com']]]);
        // Default wiring (testing env → development mock sender, which accepts).
        $start = (new ChannelDispatchService())->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, 0, null);
        $this->assertSame(3, $start['snapshot']['eligible_count']);

        $jobs     = new JobService();
        $registry = new JobHandlerRegistry();
        $runs     = [];
        while (($job = $jobs->reserve('distribution', 'test-worker', 60)) !== null) {
            $runs[] = $registry->execute($job);
            $jobs->markCompleted((int) $job['id'], end($runs));
            $this->assertLessThan(5, count($runs), 'runaway batches');
        }

        $this->assertSame(['continuing', 'finished'], array_column($runs, 'status'));
        $dispatch = $this->row('reach_campaign_dispatches', $start['dispatch_id']);
        $this->assertSame('completed', $dispatch['status']);
        $this->assertSame(3, (int) $dispatch['sent_count']);
    }

    public function testLateUnsubscribeIsHonouredAtSendTime(): void
    {
        $this->lead('Asha', 'asha@example.com');
        $this->lead('Bina', 'bina@example.com');
        $campaignId = $this->emailCampaign(['all_leads' => true]);
        $svc   = $this->service();
        $start = $svc->start(ChannelDispatchService::SOURCE_EMAIL_CAMPAIGN, $campaignId, 0, null);
        $this->suppress('email', 'bina@example.com');

        $svc->processBatch($start['dispatch_id']);
        $this->assertSame(['asha@example.com'], array_map(static fn ($m) => $m->recipientAddress, $this->sent));
        $statuses = array_column(\Config\Database::connect()->table('reach_campaign_delivery_attempts')
            ->where('dispatch_id', $start['dispatch_id'])->orderBy('id')->get()->getResultArray(), 'status');
        $this->assertSame(['accepted', 'suppressed'], $statuses);
    }

    public function testNoEligibleRecipientsFailsTheCampaignInsteadOfSendingToAPlaceholder(): void
    {
        $this->lead('Unconsented', null, '9876543210');
        $campaignId = $this->smsCampaign(['all_leads' => true]);
        $result = $this->service()->start(ChannelDispatchService::SOURCE_SMS_CAMPAIGN, $campaignId, 0, null);

        $this->assertSame('failed', $result['status']);
        $this->assertSame([], $this->sent);
        $this->assertStringContainsString('No eligible recipients', (string) $result['dispatch']['last_error']);
    }

    public function testWhatsAppOptInCheckUsesLeadConsent(): void
    {
        $lead = $this->lead('Wa', null, '9876543210');
        $resolver = new AudienceResolverService();
        $this->assertFalse($resolver->hasOptIn(0, 'whatsapp', '+91 98765 43210'));
        $this->consent($lead, 'whatsapp', 'granted');
        $this->assertTrue($resolver->hasOptIn(0, 'whatsapp', '+91 98765 43210'));
        (new \App\Libraries\Distribution\SuppressionService(new ChannelSuppressionModel(), new AuditLogger()))
            ->suppress(0, 'whatsapp', '+91 98765 43210', \App\Enums\SuppressionReason::OptOut, 'test', null);
        $this->assertFalse($resolver->hasOptIn(0, 'whatsapp', '9876543210'), 'a hand-typed suppression format still matches');
    }

    public function testSocialPostFailsClosedInProductionAndAuditFindsLegacyMockPosts(): void
    {
        $db = \Config\Database::connect();
        $db->table('reach_social_posts')->insert(['channel' => 'linkedin', 'content' => 'Launch!', 'status' => 'approved', 'approval_status' => 'approved', 'tenant_id' => 0]);
        $postId = (int) $db->insertID();

        ProviderGuard::forceProduction(true);
        $service = new SocialPublisherService(new CampaignDispatchModel(), new CampaignDeliveryAttemptModel(), new CampaignOperationalMetricsModel(), new AuditLogger());
        try {
            $service->dispatch($postId, 0, null);
            $this->fail('Nothing may be posted without a real provider.');
        } catch (ProviderNotConfiguredException) {
        }
        $post = $this->row('reach_social_posts', $postId);
        $this->assertSame('failed', $post['status']);
        $this->assertNull($post['external_post_id']);
        $this->assertNotEmpty($post['error_message']);
        ProviderGuard::forceProduction(null);

        // A row the old mock fallthrough produced in production:
        $db->table('reach_social_posts')->insert([
            'channel' => 'facebook', 'content' => 'old', 'status' => 'posted', 'external_post_id' => 'mock-post-abc123', 'provider' => 'mock_social', 'tenant_id' => 0,
        ]);
        $legacyId = (int) $db->insertID();
        $db->table('reach_campaign_delivery_attempts')->insert([
            'dispatch_id' => null, 'status' => 'accepted', 'provider' => 'mock_email', 'provider_message_id' => 'mock-email-1', 'idempotency_key' => 'legacy-1',
        ]);

        $audit    = new DistributionAuditService();
        $findings = $audit->findings();
        $this->assertSame([$legacyId], array_map('intval', array_column($findings['social_mock_posted'], 'id')));
        $this->assertCount(1, $findings['attempt_mock_accepted']);

        $changed = $audit->fix();
        $this->assertSame(1, $changed['social_mock_posted']);
        $this->assertSame(1, $changed['attempt_mock_accepted']);
        $this->assertSame('failed', $this->row('reach_social_posts', $legacyId)['status']);
        $this->assertSame(DistributionAuditService::FIX_REASON, $this->row('reach_social_posts', $legacyId)['error_message']);
        $this->assertSame([], $audit->findings()['social_mock_posted']);
    }
}
