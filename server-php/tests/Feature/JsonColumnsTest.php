<?php

namespace Tests\Feature;

use App\Libraries\MarketingBotReporter;
use App\Models\BotSettingModel;
use App\Models\CommunitySpaceModel;
use App\Models\LeadModel;
use App\Models\MarketingBotReportModel;
use App\Services\JsonColumnRepairService;
use Config\Database;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * JSON columns round-trip through the models: writers' arrays are stored once (a JSON document, not a
 * JSON string), SQL NULL reads as null, rows an older release stored double-encoded still read and
 * reach:repair-json rewrites them, and the text[] columns (pg-text-array) store and read lists.
 *
 * @internal
 */
final class JsonColumnsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Services::reset(true);
        parent::tearDown();
    }

    private function jsonType(string $table, string $column, int $id): ?string
    {
        return Database::connect()->query("SELECT jsonb_typeof({$column}) AS t FROM {$table} WHERE id = ?", [$id])->getRow()->t ?? null;
    }

    public function testJsonColumnsEndToEnd(): void
    {
        $db = Database::connect();

        // A library writer (used to json_encode() every field).
        Services::injectMock('consoleAudit', new \App\Libraries\ConsoleAuditClient(static fn (): array => ['status' => 0, 'body' => null], '', ''));
        $reportId = (new MarketingBotReporter())->record([
            'action' => 'generate_blog_draft', 'mode' => 'confirm',
            'data_accessed' => ['blog_posts' => 3], 'content_generated' => ['title' => 'GST'], 'evidence' => ['urls' => ['a']],
        ]);
        $this->assertSame(['object', 'object', 'object', null],
            [$this->jsonType('reach_marketing_bot_reports', 'data_accessed', $reportId), $this->jsonType('reach_marketing_bot_reports', 'content_generated', $reportId),
             $this->jsonType('reach_marketing_bot_reports', 'evidence', $reportId), $this->jsonType('reach_marketing_bot_reports', 'errors', $reportId)]);
        $report = (new MarketingBotReportModel())->find($reportId);
        $this->assertSame(['blog_posts' => 3], $report['data_accessed']);
        $this->assertNull($report['errors'], 'SQL NULL reads as null');

        // A model that encoded inside its own method.
        (new BotSettingModel())->updateMode('auto', ['generate_blog_draft', 'push_lead'], null);
        $this->assertSame(['generate_blog_draft', 'push_lead'], (new BotSettingModel())->currentAllowedAutoActions());
        $this->assertSame('array', $db->query("SELECT jsonb_typeof(allowed_auto_actions) AS t FROM reach_bot_settings ORDER BY id DESC LIMIT 1")->getRow()->t);

        // text[] through pg-text-array (the json cast refused a non-empty array).
        $spaces = new CommunitySpaceModel();
        $spaceId = (int) $spaces->insert(['slug' => 'zz-test-space', 'title' => 'ZZ', 'allowed_content_types' => ['faq', 'how to']], true);
        $this->assertSame(['faq', 'how to'], $spaces->find($spaceId)['allowed_content_types']);
        $this->assertSame('{faq,"how to"}', $db->query('SELECT allowed_content_types::text AS v FROM reach_community_spaces WHERE id = ?', [$spaceId])->getRow()->v);

        // A row an older release stored double-encoded.
        $db->query("INSERT INTO reach_leads (name, raw_payload) VALUES ('Legacy', to_jsonb(?::text))", ['{"utm":"x"}']);
        $legacy = (int) $db->insertID();
        $this->assertSame(['utm' => 'x'], (new LeadModel())->find($legacy)['raw_payload'], 'a double-encoded row still reads');
        $repair = new JsonColumnRepairService($db);
        $this->assertSame(1, array_sum(array_column($repair->run(false, 'reach_leads'), 'candidates')));
        $this->assertSame(1, array_sum(array_column($repair->run(true, 'reach_leads'), 'repaired')));
        $this->assertSame('object', $this->jsonType('reach_leads', 'raw_payload', $legacy));
        $this->assertSame(0, array_sum(array_column($repair->run(false), 'candidates')), 'idempotent across every JSON column');
    }
}
