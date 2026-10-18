<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use App\Libraries\Distribution\UnsubscribeLinks;
use App\Models\Distribution\ChannelSuppressionModel;
use Tests\Support\ApiTestCase;

/**
 * GET v1/distribution/readiness and the public unsubscribe link.
 *
 * @internal
 */
final class CampaignReadinessApiTest extends ApiTestCase
{
    private string|false $appUrl = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appUrl = getenv('REACH_APP_URL');
        putenv('REACH_APP_URL=https://reach.example.test');
        $_ENV['REACH_APP_URL'] = 'https://reach.example.test';
    }

    protected function tearDown(): void
    {
        if ($this->appUrl === false) {
            putenv('REACH_APP_URL');
            unset($_ENV['REACH_APP_URL']);
        } else {
            putenv('REACH_APP_URL=' . $this->appUrl);
            $_ENV['REACH_APP_URL'] = $this->appUrl;
        }
        parent::tearDown();
    }

    public function testReadinessReportsEveryChannelHonestly(): void
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/distribution/readiness');
        $res->assertStatus(200);
        $data = json_decode($res->getJSON(), true)['data'];

        $byChannel = array_column($data['channels'], null, 'channel');
        $this->assertSame(
            ['email', 'sms', 'whatsapp', 'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'google_ads', 'meta_ads', 'linkedin_ads'],
            array_keys($byChannel)
        );
        foreach (['google_ads', 'meta_ads', 'linkedin_ads'] as $ads) {
            $this->assertSame('not_supported', $byChannel[$ads]['state']);
        }
        foreach (['facebook', 'instagram', 'linkedin', 'twitter'] as $social) {
            $this->assertNotSame('ready', $byChannel[$social]['state'], 'no real social publisher exists yet');
        }
        // Email lists the providers it would use, by name only; none here (no Console, no EMAIL_API_KEY).
        $this->assertSame([], $byChannel['email']['providers']);
        $this->assertSame('none', $byChannel['email']['provider_source']);
        $this->assertSame('not_configured', $byChannel['email']['state']);
        $this->assertStringStartsWith('email: no provider configured', $byChannel['email']['provider_line']);
        $this->assertSame('distribution', $data['queue']['name']);
        $this->assertStringNotContainsString('test-jwt-secret', $res->getJSON(), 'no secret values in the report');
    }

    public function testReadinessNeedsDistributionRead(): void
    {
        $this->withHeaders($this->authAs('viewer'))->get('v1/distribution/readiness')->assertStatus(403);
    }

    public function testSignedUnsubscribeLinkSuppressesTheAddress(): void
    {
        $url = (string) UnsubscribeLinks::url(0, 'reader@example.com');
        $path = substr($url, strpos($url, '/api/') + 5);
        $res = $this->get($path);
        $res->assertStatus(200);
        $this->assertStringContainsString('unsubscribed', strtolower((string) $res->getBody()));

        $hash = ChannelSuppressionModel::hashAddress(0, 'email', 'reader@example.com');
        $row  = \Config\Database::connect()->table('reach_channel_suppressions')->where('address_hash', $hash)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('unsubscribe', $row['reason']);

        $this->get('v1/public/unsubscribe?t=0&e=cmVhZGVy&s=forged')->assertStatus(400);
    }
}
