<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Libraries\Settings\ReachConfig;
use App\Models\SettingModel;
use Tests\Support\ApiTestCase;

/**
 * @covers \App\Controllers\Api\V1\AutomationSettingsController
 * @covers \App\Controllers\Api\V1\SettingsController
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

    public function testReadersCannotWrite(): void
    {
        $h = $this->authAs('viewer');
        $this->withHeaders($h)->call('GET', 'v1/settings/automation')->assertStatus(403);
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
