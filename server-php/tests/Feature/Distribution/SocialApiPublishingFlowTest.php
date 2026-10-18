<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiAiClient;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;
use App\Libraries\Distribution\SocialPostStatusPoller;
use App\Libraries\Distribution\SocialPublisherService;
use App\Libraries\JobContext;
use App\Libraries\Video\Automation\VideoProviders;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Video\FakeHttpTransport;
use Tests\Support\Video\VideoEnv;

/**
 * reach_social_posts through social-api.ai, against the real schema:
 * submitted → `publishing` → `posted` only when GET /posts/{id} says so;
 * failed targets and the 30-minute timeout end `failed` with the code.
 *
 * @covers \App\Libraries\Distribution\SocialPublisherService
 * @covers \App\Libraries\Distribution\SocialPostStatusPoller
 * @covers \App\Jobs\SocialPostPollJob
 *
 * @internal
 */
final class SocialApiPublishingFlowTest extends DatabaseTestCase
{
    use VideoEnv;

    private const KEY = 'sapi_key_feature_flow_secret_987';

    private FakeHttpTransport $http;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVideoEnv(['SOCIALAPI_API_KEY' => self::KEY, 'SOCIALAPI_BASE_URL' => null, 'SOCIAL_PROVIDER' => null, 'SOCIALAPI_ACCOUNT_LINKEDIN' => null]);
        SocialApiAccounts::forget();
        $this->now  = time();
        $this->http = (new FakeHttpTransport())->on('GET', '#/v1/accounts$#', FakeHttpTransport::json(200, [
            ['id' => 'acc_li', 'name' => 'AICOUNTLY', 'platform' => 'linkedin', 'status' => 'active'],
        ]));
        VideoProviders::override('http', $this->http);
    }

    protected function tearDown(): void
    {
        SocialApiAccounts::forget();
        $this->restoreVideoEnv();
        parent::tearDown();
    }

    private function publisher(): SocialApiAiPublisher
    {
        $noSleep = static function (int $s): void {
        };
        $client = new SocialApiAiClient(self::KEY, null, $this->http, $noSleep);
        return new SocialApiAiPublisher(self::KEY, null, $client, new SocialApiAccounts($client, null, null, false), $noSleep);
    }

    private function service(): SocialPublisherService
    {
        $s = new SocialPublisherService(new CampaignDispatchModel(), new CampaignDeliveryAttemptModel(), new CampaignOperationalMetricsModel(), new AuditLogger());
        $s->setPublisher($this->publisher());
        return $s;
    }

    private function poller(): SocialPostStatusPoller
    {
        return new SocialPostStatusPoller($this->publisher(), null, fn (): int => $this->now);
    }

    private function post(array $extra = []): int
    {
        $db = \Config\Database::connect();
        $db->table('reach_social_posts')->insert($extra + ['channel' => 'linkedin', 'content' => 'Close your books faster.', 'status' => 'approved', 'approval_status' => 'approved', 'tenant_id' => 0]);
        return (int) $db->insertID();
    }

    private function row(int $id, string $table = 'reach_social_posts'): array
    {
        return \Config\Database::connect()->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function attempt(string $pid): array
    {
        return \Config\Database::connect()->table('reach_campaign_delivery_attempts')->where('provider_message_id', $pid)->get()->getRowArray();
    }

    private function answerStatus(string $pid, array $body): void
    {
        $this->http->on('GET', '#/v1/posts/' . preg_quote($pid, '#') . '$#', FakeHttpTransport::json(200, $body), 1);
    }

    public function testAPostIsPublishingUntilSocialApiReportsItPublished(): void
    {
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_1', 'status' => 'pending', 'upload_url' => 'https://s3.example/x?X-Amz-Signature=sig123', 'echo' => self::KEY]));
        $id = $this->post();

        $result = $this->service()->dispatch($id, 0, null);
        $this->assertSame('submitted', $result['status']);

        $row = $this->row($id);
        $this->assertSame('publishing', $row['status'], 'a 201 is not "posted"');
        $this->assertNull($row['published_at']);
        $this->assertSame('post_1', $row['external_post_id']);
        $this->assertSame('acc_li', $row['platform_account_id']);
        $this->assertSame('social_api', $row['provider']);
        $this->assertSame('submitted', $row['provider_status']);
        $this->assertNotNull($row['submitted_at']);
        $this->assertStringNotContainsString(self::KEY, (string) $row['provider_response_json']);
        $this->assertStringNotContainsString('sig123', (string) $row['provider_response_json']);
        $this->assertSame('accepted', $this->attempt('post_1')['status']);

        // Never sent twice while in flight.
        $this->assertSame('already_dispatched', $this->service()->dispatch($id, 0, null)['status']);
        $this->assertCount(1, $this->http->to('#/v1/posts$#', 'POST'));

        $this->answerStatus('post_1', ['id' => 'post_1', 'status' => 'processing', 'targets' => [['account_id' => 'acc_li', 'status' => 'processing']]]);
        $r = $this->poller()->pollOne($id, true);
        $this->assertSame('publishing', $r['status']);
        $row = $this->row($id);
        $this->assertSame('publishing', $row['status']);
        $this->assertSame('processing', $row['provider_status']);
        $this->assertSame(1, (int) $row['poll_count']);

        $this->answerStatus('post_1', ['id' => 'post_1', 'status' => 'published', 'targets' => [['account_id' => 'acc_li', 'status' => 'published', 'url' => 'https://www.linkedin.com/feed/update/urn:li:share:123', 'platform_post_id' => 'urn:li:share:123']]]);
        $this->now += 300;
        $this->assertSame('posted', $this->poller()->pollOne($id, true)['status']);
        $row = $this->row($id);
        $this->assertSame('posted', $row['status']);
        $this->assertNotNull($row['published_at']);
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:123', $row['remote_url']);
        $this->assertSame('urn:li:share:123', $row['remote_post_id']);
        $this->assertSame('published', $row['provider_status']);
        $this->assertNull($row['error_message']);
        $this->assertSame('delivered', $this->attempt('post_1')['status']);

        // Settled posts are left alone.
        $this->assertFalse($this->poller()->pollOne($id, true)['changed']);
    }

    public function testAFailedTargetEndsFailedWithItsErrorCode(): void
    {
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_2']), 1);
        $id = $this->post();
        $this->service()->dispatch($id, 0, null);

        $this->answerStatus('post_2', ['id' => 'post_2', 'status' => 'failed', 'targets' => [['account_id' => 'acc_li', 'status' => 'failed', 'error' => ['code' => 'publishing.platform.rejected', 'message' => 'LinkedIn refused the media']]]]);
        $this->assertSame('failed', $this->poller()->pollOne($id, true)['status']);
        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('publishing.platform.rejected', $row['provider_error_code']);
        $this->assertStringContainsString('LinkedIn refused the media', (string) $row['error_message']);
        $this->assertNull($row['published_at']);
        $attempt = $this->attempt('post_2');
        $this->assertSame('failed', $attempt['status']);
        $this->assertSame('rejected', $attempt['failure_class']);

        // A failed post may be dispatched again (a new attempt; social-api.ai's
        // own duplicate check guards against a double post within 24 h).
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(409, ['error' => ['code' => 'resource.conflict']]));
        try {
            $this->service()->dispatch($id, 0, null);
            $this->fail('the duplicate is refused');
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::CONFLICT, $e->kind);
        }
        $this->assertCount(2, $this->http->to('#/v1/posts$#', 'POST'));
        $this->assertSame('resource.conflict', $this->row($id)['provider_error_code']);
    }

    public function testNoConfirmationWithinThirtyMinutesFailsWithTheTimeoutCode(): void
    {
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_3']));
        $id = $this->post();
        $this->service()->dispatch($id, 0, null);

        $this->http->on('GET', '#/v1/posts/post_3$#', FakeHttpTransport::json(200, ['id' => 'post_3', 'status' => 'processing']));
        $this->now += 29 * 60;
        $this->assertSame('publishing', $this->poller()->pollOne($id, true)['status']);

        $this->now += 2 * 60;
        $this->assertSame('failed', $this->poller()->pollOne($id, true)['status']);
        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(SocialPostStatusPoller::TIMEOUT_CODE, $row['provider_error_code']);
        $this->assertStringContainsString('within 30 minutes', (string) $row['error_message']);
        $this->assertStringContainsString('check social-api.ai before posting it again', (string) $row['error_message']);
        $this->assertSame('unknown', $this->attempt('post_3')['failure_class']);
    }

    public function testAStatusCheckOutageKeepsThePostInFlightAndSaysSo(): void
    {
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_4']));
        $id = $this->post();
        $this->service()->dispatch($id, 0, null);

        $this->http->on('GET', '#/v1/posts/post_4$#', FakeHttpTransport::json(503, ['error' => 'down']));
        $r = $this->poller()->pollOne($id, true);
        $this->assertSame('publishing', $r['status']);
        $this->assertNotNull($r['poll_error']);
        $row = $this->row($id);
        $this->assertSame('publishing', $row['status']);
        $this->assertSame('poll_error', $row['provider_status']);
        $this->assertStringStartsWith('Status check failed (will retry)', (string) $row['error_message']);
        $this->assertCount(3, $this->http->to('#/v1/posts/post_4$#'), 'an idempotent GET is retried on 5xx');
    }

    public function testARejectedCreateRecordsTheCodeAndARedactedResponse(): void
    {
        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(422, ['targets' => [['account_id' => 'acc_li', 'status' => 'failed', 'error' => ['code' => 'publishing.text.too_long', 'message' => 'Too long for ' . self::KEY]]]]));
        $id = $this->post();
        try {
            $this->service()->dispatch($id, 0, null);
            $this->fail('a 422 is a failure');
        } catch (SocialApiException $e) {
            $this->assertSame(SocialApiException::REJECTED, $e->kind);
        }
        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('publishing.text.too_long', $row['provider_error_code']);
        $this->assertStringNotContainsString(self::KEY, (string) $row['provider_response_json'] . $row['error_message']);
        $this->assertStringContainsString('publishing.text.too_long', (string) $row['provider_response_json']);
    }

    public function testThePollJobSettlesDuePostsAndTheCampaignDispatch(): void
    {
        $db = \Config\Database::connect();
        $db->table('reach_campaigns')->insert(['name' => 'Launch', 'campaign_type' => 'multi', 'status' => 'approved', 'tenant_id' => 0]);
        $campaignId = (int) $db->insertID();
        $db->table('reach_campaign_versions')->insert(['campaign_id' => $campaignId, 'version_number' => 1]);
        $versionId = (int) $db->insertID();
        $db->table('reach_campaign_channel_variants')->insert(['campaign_version_id' => $versionId, 'channel' => 'social', 'validation_status' => 'valid', 'content_json' => json_encode(['platform' => 'linkedin', 'text' => 'We are live!'])]);
        $variantId = (int) $db->insertID();
        $db->table('reach_campaign_dispatches')->insert(['campaign_id' => $campaignId, 'campaign_version_id' => $versionId, 'tenant_id' => 0, 'channel' => 'social', 'status' => 'queued', 'idempotency_key' => 'test-social-1', 'total_recipients' => 1]);
        $dispatchId = (int) $db->insertID();

        $this->http->on('POST', '#/v1/posts$#', FakeHttpTransport::json(201, ['id' => 'post_v']));
        $out = $this->service()->dispatchVariant($dispatchId, $variantId, 0);
        $this->assertSame('submitted', $out['status']);
        $this->assertSame('dispatching', $this->row($dispatchId, 'reach_campaign_dispatches')['status'], 'not completed until published');
        $post = $this->row((int) $out['post_id']);
        $this->assertSame('publishing', $post['status']);
        $this->assertSame($dispatchId, (int) $post['dispatch_id']);
        $this->assertSame('submitted', $this->service()->dispatchVariant($dispatchId, $variantId, 0)['status'], 'a re-run does not post again');
        $this->assertCount(1, $this->http->to('#/v1/posts$#', 'POST'));

        $this->answerStatus('post_v', ['id' => 'post_v', 'status' => 'published']);
        $result = (new \App\Jobs\SocialPostPollJob())->handle(['limit' => 10], JobContext::fromJobRow(['id' => 1, 'job_type' => SocialPostStatusPoller::JOB_TYPE, 'attempts' => 1], 'phpunit'));
        $this->assertSame(1, $result['posted']);
        $this->assertSame('posted', $this->row((int) $out['post_id'])['status']);
        $this->assertSame('completed', $this->row($dispatchId, 'reach_campaign_dispatches')['status']);
        $this->assertSame('completed', $this->row($campaignId, 'reach_campaigns')['status']);

        // Polled a moment ago → not due again yet.
        $this->assertSame(0, $this->poller()->pollDue()['checked']);
    }
}
