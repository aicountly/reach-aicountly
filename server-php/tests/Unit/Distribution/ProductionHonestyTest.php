<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\Distribution\AudienceResolverService;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\Distribution\Providers\AocPortalSmsSender;
use App\Libraries\Distribution\Providers\ChannelMessage;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\GatewayEmailSender;
use App\Libraries\Distribution\Providers\GatewayWhatsAppSender;
use App\Libraries\Distribution\Providers\MockEmailSender;
use App\Libraries\Distribution\Providers\MockSmsSender;
use App\Libraries\Distribution\Providers\MockSocialPublisher;
use App\Libraries\Distribution\Providers\MockWhatsAppSender;
use App\Libraries\Distribution\Providers\ProviderGuard;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\SendSkippedException;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;
use App\Libraries\Distribution\UnsubscribeLinks;
use App\Libraries\JobHandlerRegistry;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Campaign readiness — no channel may report sent/posted unless a real
 * provider accepted it.
 *
 * @internal
 */
final class ProductionHonestyTest extends CIUnitTestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        ProviderGuard::forceProduction(null);
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
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
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
        ReachConfig::flush();
    }

    private static function message(string $to = 'user@example.com', array $meta = [], ?string $template = null): ChannelMessage
    {
        return new ChannelMessage(idempotencyKey: 'honesty-' . bin2hex(random_bytes(3)), recipientAddress: $to, content: 'Hello', templateId: $template, metadata: $meta);
    }

    /** @return iterable<string, array{object}> */
    public static function mockSenders(): iterable
    {
        yield 'email'    => [new MockEmailSender()];
        yield 'sms'      => [new MockSmsSender()];
        yield 'whatsapp' => [new MockWhatsAppSender()];
    }

    /** @dataProvider mockSenders */
    public function testMockSendersRefuseInProduction(object $sender): void
    {
        ProviderGuard::forceProduction(true);
        $this->assertFalse($sender->isEnabled());

        try {
            $sender->send(self::message('+919876543210'));
            $this->fail('A mock must not accept a message in production.');
        } catch (ProviderNotConfiguredException $e) {
            $this->assertStringContainsString('provider not configured', $e->getMessage());
            $this->assertSame(503, $e->getCode());
        }
    }

    /** @dataProvider mockSenders */
    public function testMockSendersStillWorkOutsideProduction(object $sender): void
    {
        ProviderGuard::forceProduction(false);
        $this->assertTrue($sender->isEnabled());
        $this->assertTrue($sender->send(self::message('+919876543210'))->isAccepted());
    }

    public function testMockSocialPublisherRefusesInProductionInsteadOfFakingAPost(): void
    {
        ProviderGuard::forceProduction(true);
        $publisher = new MockSocialPublisher();
        $this->assertFalse($publisher->isEnabled());

        $this->expectException(ProviderNotConfiguredException::class);
        $publisher->publish(self::message('page-1'), 'linkedin');
    }

    public function testMockWhatsAppOffersNoFakeTemplatesInProduction(): void
    {
        ProviderGuard::forceProduction(true);
        $this->assertSame([], (new MockWhatsAppSender())->getTemplates());
    }

    public function testUnsetEnvironmentCountsAsProduction(): void
    {
        // In PHPUnit ENVIRONMENT is "testing"; the guard reads it rather than assuming.
        ProviderGuard::forceProduction(null);
        $this->assertFalse(ProviderGuard::isProduction());
    }

    // ── socialapi.ai seam ───────────────────────────────────────────────────

    public function testSocialApiAdapterFailsClosed(): void
    {
        $publisher = new SocialApiAiPublisher('key', 'https://api.example.test');
        $this->assertFalse($publisher->isEnabled());
        $this->assertFalse($publisher->verifyCallback([], '{}'));
        $this->assertSame('socialapi', $publisher->providerName());
        $this->assertTrue($publisher->getCapabilities()['pending']);

        try {
            $publisher->publish(self::message('page'), 'linkedin');
            $this->fail('The pending adapter must never report a post.');
        } catch (ProviderNotConfiguredException $e) {
            $this->assertStringContainsString('socialapi.ai adapter pending: API docs required', $e->getMessage());
        }
        $this->expectException(ProviderNotConfiguredException::class);
        $publisher->getStatus('x');
    }

    public function testFactorySelectsSocialApiWhenTheKeyIsSet(): void
    {
        $this->setEnv('SOCIAL_PROVIDER', null);
        $this->setEnv('SOCIALAPI_API_KEY', 'sk-test-123');
        $this->assertInstanceOf(SocialApiAiPublisher::class, ChannelProviderFactory::makeSocialPublisher());
        $this->assertFalse(ChannelProviderFactory::socialPublisherReadyFor('linkedin'));
    }

    public function testFactorySelectsSocialApiByName(): void
    {
        $this->setEnv('SOCIALAPI_API_KEY', null);
        $this->setEnv('SOCIAL_PROVIDER', 'socialapi');
        $this->assertInstanceOf(SocialApiAiPublisher::class, ChannelProviderFactory::makeSocialPublisher());
    }

    public function testSocialPostsAreNotAutoDispatchableInProduction(): void
    {
        $this->setEnv('SOCIALAPI_API_KEY', null);
        $this->setEnv('SOCIAL_PROVIDER', null);
        ProviderGuard::forceProduction(false);
        $this->assertTrue(ChannelProviderFactory::socialPublisherReadyFor('linkedin'), 'dev mock');
        ProviderGuard::forceProduction(true);
        $this->assertFalse(ChannelProviderFactory::socialPublisherReadyFor('linkedin'));
    }

    // ── skip-send and recipients ────────────────────────────────────────────

    public function testSmsSkipSendIsSkippedNotAccepted(): void
    {
        $this->setEnv('SMS_SKIP_SEND', null); // defaults to true
        $this->expectException(SendSkippedException::class);
        (new AocPortalSmsSender())->send(self::message('9876543210', ['dlt_template_id' => '1107000000000001']));
    }

    public function testSmsRequiresADltTemplate(): void
    {
        $this->expectExceptionCode(422);
        (new AocPortalSmsSender())->send(self::message('9876543210'));
    }

    public function testWhatsAppSkipSendIsSkippedNotAccepted(): void
    {
        $this->setEnv('WHATSAPP_SKIP_SEND', 'true');
        $this->expectException(SendSkippedException::class);
        (new GatewayWhatsAppSender())->send(self::message('9876543210', [], 'launch_offer'));
    }

    public function testWhatsAppRefusesFreeformCampaignMessages(): void
    {
        $this->expectExceptionCode(422);
        (new GatewayWhatsAppSender())->send(self::message('9876543210'));
    }

    /** @return iterable<string, array{string}> */
    public static function placeholderRecipients(): iterable
    {
        yield 'broadcast'      => ['broadcast'];
        yield 'empty'          => [''];
        yield 'not-an-address' => ['list-at-broadcast'];
    }

    /** @dataProvider placeholderRecipients */
    public function testGatewaysNeverFallBackToTheTestAddress(string $placeholder): void
    {
        $this->setEnv('CAMPAIGN_DISPATCH_TEST_EMAIL', 'ops@example.com');
        $this->setEnv('CAMPAIGN_DISPATCH_TEST_PHONE', '9999999999');

        foreach ([new GatewayEmailSender(), new AocPortalSmsSender()] as $sender) {
            try {
                $sender->send(self::message($placeholder, ['dlt_template_id' => '1107']));
                $this->fail(get_class($sender) . ' sent to a fallback address for ' . var_export($placeholder, true));
            } catch (\RuntimeException $e) {
                $this->assertSame(422, $e->getCode(), $e->getMessage());
            }
        }
    }

    // ── audiences / unsubscribe / jobs ──────────────────────────────────────

    public function testAddressNormalisation(): void
    {
        $this->assertSame('a.b@example.com', AudienceResolverService::addressFor(['email' => ' A.B@Example.com '], 'email'));
        $this->assertSame('', AudienceResolverService::addressFor(['email' => 'nope'], 'email'));
        $this->assertSame('919876543210', AudienceResolverService::addressFor(['mobile' => '98765 43210'], 'sms'));
        $this->assertSame('919812345678', AudienceResolverService::addressFor(['mobile' => '9876543210', 'whatsapp' => '+91 98123 45678'], 'whatsapp'));
        $this->assertSame('919876543210', AudienceResolverService::addressFor(['mobile' => '9876543210', 'whatsapp' => ''], 'whatsapp'));
        $this->assertSame('', AudienceResolverService::addressFor(['mobile' => '123'], 'sms'));
        $this->assertContains('9876543210', AudienceResolverService::addressVariants('sms', '919876543210'));
        $this->assertContains('+919876543210', AudienceResolverService::addressVariants('sms', '919876543210'));
    }

    public function testUnsubscribeLinksAreSignedPerAddress(): void
    {
        $this->setEnv('REACH_APP_URL', 'https://reach.example.test');
        $url = UnsubscribeLinks::url(0, 'Someone@Example.com');
        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://reach.example.test/api/v1/public/unsubscribe?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['tenant_id' => 0, 'email' => 'someone@example.com'], UnsubscribeLinks::verify($q['t'], $q['e'], $q['s']));

        $other = rtrim(strtr(base64_encode('victim@example.com'), '+/', '-_'), '=');
        $this->assertNull(UnsubscribeLinks::verify($q['t'], $other, $q['s']), 'signature must bind the address');
        $this->assertNull(UnsubscribeLinks::verify('1', $q['e'], $q['s']), 'signature must bind the tenant');
    }

    public function testEveryDistributionJobTypeHasAHandler(): void
    {
        $registry = new JobHandlerRegistry();
        foreach (DistributionJobTypes::all() as $type) {
            $this->assertTrue($registry->has($type), "{$type} has no handler — its jobs would dead-letter");
        }
    }
}
