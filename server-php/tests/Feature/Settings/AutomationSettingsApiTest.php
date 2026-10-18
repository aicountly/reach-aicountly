<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\VideoProviders;
use App\Models\SettingModel;
use Tests\Support\ApiTestCase;
use Tests\Support\Video\FakeHttpTransport;

/**
 * @covers \App\Controllers\Api\V1\AutomationSettingsController
 * @covers \App\Controllers\Api\V1\SettingsController
 * @covers \App\Libraries\Settings\LiveIntegrationCheck
 */
final class AutomationSettingsApiTest extends ApiTestCase
{
    private ?string $jwtBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwtBackup    = $_ENV['JWT_SECRET'] ?? null;
        $_ENV['JWT_SECRET'] = $_ENV['JWT_SECRET'] ?? 'test-jwt-secret';
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        ReachConfig::flush();
        parent::tearDown();
    }

    private function json($result): array
    {
        return json_decode($result->getJSON() ?: '{}', true)['data'] ?? [];
    }

    public function testASavedKeyIsEncryptedAtRestAndNeverReturned(): void
    {
        $h   = $this->authAs('super_admin');
        $res = $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', [
            'ai.openai.api_key'          => 'sk-unit-test-123',
            'automation.blog.daily_limit' => 8,
        ]);
        $res->assertStatus(200);
        $this->assertStringNotContainsString('sk-unit-test-123', (string) $res->getJSON());

        $raw = (new SettingModel())->where('key', 'ai.openai.api_key')->first();
        $this->assertStringNotContainsString('sk-unit-test-123', (string) $raw['value_json']);

        ReachConfig::flush();
        $this->assertSame('sk-unit-test-123', ReachConfig::secret('ai.openai.api_key'));
        $this->assertSame(8, ReachConfig::get('automation.blog.daily_limit'));

        $rows = array_column($this->json($this->withHeaders($h)->call('GET', 'v1/settings/automation')), null, 'key');
        $this->assertTrue($rows['ai.openai.api_key']['is_set']);
        $this->assertNull($rows['ai.openai.api_key']['value']);
        $this->assertSame('settings', $rows['ai.openai.api_key']['source']);
    }

    public function testInvalidValuesAreRefused(): void
    {
        $h = $this->authAs('super_admin');
        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', ['reports.daily.recipients' => 'nope'])->assertStatus(422);
        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', ['made.up.key' => 'x'])->assertStatus(422);
    }

    public function testTheSocialApiSettingsAreOfferedValidatedAndSaved(): void
    {
        $h    = $this->authAs('super_admin');
        $rows = array_column($this->json($this->withHeaders($h)->call('GET', 'v1/settings/automation')), null, 'key');

        $this->assertSame('https://api.social-api.ai/v1', $rows['social.socialapi.base_url']['value']);
        $this->assertSame('default', $rows['social.socialapi.base_url']['source']);
        $this->assertSame('social-api.ai API key', $rows['social.socialapi.api_key']['label']);
        $this->assertSame('secret', $rows['social.socialapi.api_key']['type']);
        $this->assertSame('choice', $rows['video.youtube.publisher']['type']);
        $this->assertSame(['social_api', 'direct'], $rows['video.youtube.publisher']['options']);
        $this->assertSame('social_api', $rows['video.youtube.publisher']['value']);
        $this->assertSame('json_object', $rows['social.socialapi.platform_data.youtube']['type']);
        $this->assertStringContainsString('docs.social-api.ai', (string) $rows['social.socialapi.platform_data.youtube']['help']);
        foreach (['youtube', 'instagram', 'facebook', 'linkedin', 'x', 'tiktok'] as $p) {
            $this->assertSame('Social', $rows['social.socialapi.account.' . $p]['group']);
            $this->assertSame('Social', $rows['social.socialapi.platform_data.' . $p]['group']);
        }

        $res = $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', [
            'social.socialapi.api_key'               => 'sapi_key_feature_secret_123',
            'social.socialapi.account.youtube'       => 'acc_yt_42',
            'social.socialapi.platform_data.youtube' => ' {"youtube": {"privacy": "unlisted"}} ',
            'video.youtube.publisher'                => 'direct',
        ]);
        $res->assertStatus(200);
        $this->assertStringNotContainsString('sapi_key_feature_secret_123', (string) $res->getJSON());
        $raw = (new SettingModel())->where('key', 'social.socialapi.api_key')->first();
        $this->assertStringNotContainsString('sapi_key_feature_secret_123', (string) $raw['value_json'], 'encrypted at rest');

        ReachConfig::flush();
        $this->assertSame('sapi_key_feature_secret_123', ReachConfig::secret('social.socialapi.api_key'));
        $this->assertSame('acc_yt_42', ReachConfig::get('social.socialapi.account.youtube'));
        $this->assertSame(['youtube' => ['privacy' => 'unlisted']], ReachConfig::jsonObject('social.socialapi.platform_data.youtube'));
        $this->assertSame('direct', ReachConfig::get('video.youtube.publisher'));

        $rows = array_column($this->json($this->withHeaders($h)->call('GET', 'v1/settings/automation')), null, 'key');
        $this->assertTrue($rows['social.socialapi.api_key']['is_set']);
        $this->assertNull($rows['social.socialapi.api_key']['value']);
        $this->assertSame('{"youtube":{"privacy":"unlisted"}}', $rows['social.socialapi.platform_data.youtube']['value']);

        foreach ([
            ['social.socialapi.platform_data.youtube', '[1,2]'],
            ['social.socialapi.platform_data.instagram', '{not json'],
            ['video.youtube.publisher', 'ftp'],
            ['social.socialapi.base_url', 'http://insecure.example/v1'],
            ['social.socialapi.account.linkedin', 'has spaces'],
        ] as [$key, $value]) {
            $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', [$key => $value])->assertStatus(422);
        }
        ReachConfig::flush();
        $this->assertSame(['youtube' => ['privacy' => 'unlisted']], ReachConfig::jsonObject('social.socialapi.platform_data.youtube'), 'a refused value leaves the saved one');

        // Clearing the JSON and the account falls back to "none" / auto-pick.
        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', ['social.socialapi.platform_data.youtube' => '', 'social.socialapi.account.youtube' => ''])->assertStatus(200);
        ReachConfig::flush();
        $this->assertSame([], ReachConfig::jsonObject('social.socialapi.platform_data.youtube'));
        $this->assertSame('', (string) ReachConfig::get('social.socialapi.account.youtube', ''));
    }

    public function testTheSocialApiLiveCheckListsAccountsAndNeverPosts(): void
    {
        $h = $this->authAs('super_admin');
        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings/automation', ['social.socialapi.api_key' => 'sapi_key_live_check_456'])->assertStatus(200);
        ReachConfig::flush();
        \App\Libraries\Distribution\Providers\SocialApi\SocialApiAccounts::forget();
        $http = (new FakeHttpTransport())->on('GET', '#^https://api\.social-api\.ai/v1/accounts$#', FakeHttpTransport::json(200, [
            ['id' => 'acc_yt', 'name' => 'AICOUNTLY', 'platform' => 'youtube', 'status' => 'active'],
        ]));
        VideoProviders::override('http', $http);
        try {
            $res = $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/settings/automation/live-check', ['only' => ['social_api']]);
            $res->assertStatus(200);
            $data = $this->json($res);
            $this->assertTrue($data['ok']);
            $this->assertSame('social_api', $data['results'][0]['target']);
            $this->assertStringContainsString('1 connected account(s): youtube 1', $data['results'][0]['message']);
            $this->assertStringNotContainsString('sapi_key_live_check_456', (string) $res->getJSON());
            $this->assertSame(['GET'], array_values(array_unique(array_column($http->requests, 'method'))));
        } finally {
            VideoProviders::reset();
        }
    }

    public function testReadersCannotWrite(): void
    {
        $h = $this->authAs('viewer');
        $this->withHeaders($h)->call('GET', 'v1/settings/automation')->assertStatus(403);
    }

    public function testLiveCheckRunsTheChecksAndNeverReturnsASecret(): void
    {
        $env = ['DATAFORSEO_LOGIN' => 'ops@aicountly.com', 'DATAFORSEO_PASSWORD' => 'dfs-live-secret', 'SHOTSTACK_API_KEY' => 'ss-live-secret', 'SHOTSTACK_BASE_URL' => null, 'SHOTSTACK_ENV' => null];
        $backup = [];
        foreach ($env as $k => $v) {
            $backup[$k] = $_ENV[$k] ?? null;
            if ($v === null) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
        ReachConfig::flush();
        $http = (new FakeHttpTransport())
            ->on('GET', '#dataforseo#', FakeHttpTransport::json(200, ['status_code' => 20000, 'tasks' => [['result' => [['money' => ['balance' => 7]]]]]]))
            ->on('GET', '#shotstack\.io/edit/stage/templates$#', FakeHttpTransport::json(401, ['message' => 'Unauthorized']));
        VideoProviders::override('http', $http);

        try {
            $h   = $this->authAs('super_admin');
            $res = $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/settings/automation/live-check', ['only' => ['dataforseo', 'shotstack']]);
            $res->assertStatus(200);
            $data = $this->json($res);
            $this->assertFalse($data['ok']);
            $this->assertSame(['shotstack', 'dataforseo'], array_column($data['results'], 'target'));
            $rows = array_column($data['results'], null, 'target');
            $this->assertTrue($rows['dataforseo']['ok']);
            $this->assertStringContainsString('Shotstack rejected the key', $rows['shotstack']['message']);
            $this->assertStringNotContainsString('dfs-live-secret', (string) $res->getJSON());
            $this->assertStringNotContainsString('ss-live-secret', (string) $res->getJSON());
            $this->assertCount(2, $http->requests, 'no render from the UI');

            $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/settings/automation/live-check', ['render_test' => true])->assertStatus(422);
            $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/settings/automation/live-check', ['only' => ['slack']])->assertStatus(422);
            $this->assertCount(2, $http->requests);

            $this->withHeaders($this->authAs('viewer'))->withBodyFormat('json')->call('POST', 'v1/settings/automation/live-check', [])->assertStatus(403);
        } finally {
            VideoProviders::reset();
            foreach ($backup as $k => $v) {
                if ($v === null) {
                    unset($_ENV[$k]);
                    putenv($k);
                } else {
                    $_ENV[$k] = $v;
                    putenv("{$k}={$v}");
                }
            }
            ReachConfig::flush();
        }
    }

    public function testGeneralSettingsMaskSecretsAndIgnoreTheMaskOnSave(): void
    {
        $m = new SettingModel();
        $m->setSetting('smtp_password', 'hunter2');
        $m->setSetting('site_name', 'Reach');
        ReachConfig::set('ai.gemini.api_key', 'g-key');

        $h    = $this->authAs('super_admin');
        $data = $this->json($this->withHeaders($h)->call('GET', 'v1/settings'));
        $this->assertSame('••••••••', $data['smtp_password']);
        $this->assertSame('Reach', $data['site_name']);
        $this->assertArrayNotHasKey('ai.gemini.api_key', $data, 'AI & Automation keys are not shown on the generic page');

        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings', ['smtp_password' => '••••••••', 'site_name' => 'Reach 2'])->assertStatus(200);
        $this->assertSame('hunter2', $m->getSetting('smtp_password'), 'saving the mask back keeps the real value');
        $this->assertSame('Reach 2', $m->getSetting('site_name'));

        $this->withHeaders($h)->withBodyFormat('json')->call('PUT', 'v1/settings', ['ai.gemini.api_key' => 'x'])->assertStatus(422);
    }

    public function testVettingRunsAreListedNewestFirst(): void
    {
        \App\Libraries\Ai\Vetting\ContentVettingService::record(['content_type' => 'blog', 'model' => 'm', 'decision' => 'approve', 'reasons_json' => '["ok"]', 'scores_json' => '{}', 'input_sha256' => str_repeat('a', 64)]);
        \App\Libraries\Ai\Vetting\ContentVettingService::record(['content_type' => 'community', 'model' => 'm', 'decision' => 'reject', 'reasons_json' => '["abusive"]', 'scores_json' => '{}', 'input_sha256' => str_repeat('b', 64)]);

        $h    = $this->authAs('super_admin');
        $data = $this->json($this->withHeaders($h)->call('GET', 'v1/ai/vetting-runs', ['decision' => 'reject']));
        $this->assertSame(1, $data['total']);
        $this->assertSame(['abusive'], $data['items'][0]['reasons_json']);
    }

    public function testSystemApproverIsALoginDisabledAccount(): void
    {
        $id  = \App\Libraries\Automation\SystemApprover::userId();
        $row = \Config\Database::connect()->table('reach_users')->where('id', $id)->get()->getRowArray();
        $this->assertSame(\App\Libraries\Automation\SystemApprover::EMAIL, $row['email']);
        $this->assertFalse((bool) ($row['is_active'] === 't' || $row['is_active'] === true));
        $this->assertSame($id, \App\Libraries\Automation\SystemApprover::userId(), 'idempotent');
        $this->assertGreaterThan(0, \App\Libraries\Automation\SystemApprover::actorId());
    }
}
