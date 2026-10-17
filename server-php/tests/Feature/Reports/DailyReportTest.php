<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\SearchSiteFactStore;
use App\Libraries\Intelligence\Seo\DataForSeoSerpClient;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use App\Libraries\Reports\DailyReportBuilder;
use App\Libraries\Reports\DailyReportService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Daily analytics report: honest sections, persisted per date, sent only when
 * the gateway accepts it, never re-sent by the scheduler, and the API gates.
 */
final class DailyReportTest extends ApiTestCase
{
    /** @var list<array{to:string, subject:string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        ReachConfig::flush();
        $this->sent = [];
    }

    private function service(bool $mailConfigured = true, bool $accept = true): DailyReportService
    {
        $db      = Database::connect();
        $builder = new DailyReportBuilder(
            $db,
            null,
            new SeoTrackerService($db, new DataForSeoSerpClient(null, null)),
            static fn (): bool => false,
        );

        return new DailyReportService(
            $db,
            $builder,
            function (string $to, string $subject, string $html, string $text) use ($accept): array {
                $this->sent[] = ['to' => $to, 'subject' => $subject];

                return $accept ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'HTTP 401'];
            },
            static fn (): bool => $mailConfigured,
        );
    }

    public function testReportIsHonestAboutMissingSources(): void
    {
        $db = Database::connect();
        $db->table('reach_analytics_connections')->insert(['tenant_id' => 1, 'provider' => 'gsc', 'display_name' => 'GSC', 'site_property' => 'sc-domain:aicountly.com', 'enabled' => true]);
        (new SearchSiteFactStore())->upsertFacts((int) $db->insertID(), 'sc-domain:aicountly.com', null, [
            ['metric_date' => '2026-10-13', 'page_url' => 'https://aicountly.com/', 'query' => 'aicountly', 'clicks' => 20, 'impressions' => 50, 'avg_position' => 1.1],
            ['metric_date' => '2026-10-14', 'page_url' => 'https://aicountly.com/', 'query' => 'aicountly', 'clicks' => 30, 'impressions' => 60, 'avg_position' => 1.0],
        ]);

        $now     = new \DateTimeImmutable('2026-10-17 08:00:00', new \DateTimeZone('Asia/Kolkata'));
        $builder = new DailyReportBuilder($db, null, new SeoTrackerService($db, new DataForSeoSerpClient(null, null)), static fn (): bool => true);
        $summary = $builder->build('2026-10-17', $now);
        $s       = $summary['sections'];

        $this->assertSame('2026-10-14', $s['search']['date'], 'The freshest ingested day.');
        $this->assertSame(30, $s['search']['day']['clicks']);
        $this->assertSame(20.0, $s['search']['baseline']['clicks'], 'Only one baseline day exists.');
        $this->assertFalse($s['traffic']['configured']);
        $this->assertStringStartsWith('Not configured', $s['traffic']['note']);
        $this->assertSame('not_configured', $s['seo_tracker']['status']);
        $this->assertNull($s['content']['counts']['workflow_guide'], 'No source table → not tracked, not 0.');
        $this->assertSame(0, $s['content']['counts']['blog']);
        $this->assertFalse($s['vetting']['configured'], 'reach_ai_vetting_runs does not exist on this branch.');
        $this->assertArrayHasKey('failed_jobs_24h', $s['pipeline']);
        $this->assertNotEmpty($s['todo']['items']);
        $this->assertSame('2026-10-16 02:30:00+00:00', $summary['window']['start'], '24h up to 08:00 IST (02:30 UTC).');
    }

    public function testVettingSectionSummarisesDecisionsWhenTheTableExists(): void
    {
        $db = Database::connect();
        $db->query("CREATE TABLE reach_ai_vetting_runs (id BIGSERIAL PRIMARY KEY, content_type VARCHAR(40), decision VARCHAR(16),
                    reasons_json JSONB, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
        try {
            $at = '2026-10-17 01:00:00+00:00';
            foreach ([
                ['blog', 'approve', '[]'], ['blog', 'reject', '["Claims a feature that does not exist"]'],
                ['community', 'reject', '["Claims a feature that does not exist", "Too short"]'], ['community', 'revise', '["Tone"]'],
                ['blog', 'reject', '["Too short"]'],
            ] as [$type, $decision, $reasons]) {
                $db->query('INSERT INTO reach_ai_vetting_runs (content_type, decision, reasons_json, created_at) VALUES (?, ?, ?::jsonb, ?)', [$type, $decision, $reasons, $at]);
            }
            // outside the 24h window
            $db->query("INSERT INTO reach_ai_vetting_runs (content_type, decision, created_at) VALUES ('blog', 'approve', '2026-10-10 00:00:00+00')");

            $summary = (new DailyReportBuilder($db, null, new SeoTrackerService($db, new DataForSeoSerpClient(null, null)), static fn (): bool => false))
                ->build('2026-10-17', new \DateTimeImmutable('2026-10-17 09:00:00', new \DateTimeZone('Asia/Kolkata')));
            $v = $summary['sections']['vetting'];

            $this->assertTrue($v['configured']);
            $this->assertSame(['approve' => 1, 'revise' => 1, 'reject' => 3, 'other' => 0], $v['counts']);
            $this->assertSame(['reason' => 'Claims a feature that does not exist', 'count' => 2], $v['top_reject_reasons'][0]);
            $this->assertStringContainsString('Vetting rejected 3', implode("\n", $summary['sections']['todo']['items']));
        } finally {
            $db->query('DROP TABLE IF EXISTS reach_ai_vetting_runs');
            SchemaGuard::reset();
        }
    }

    public function testMissingEmailKeyMarksTheReportFailedNotSent(): void
    {
        $row = $this->service(mailConfigured: false)->send('2026-10-17');

        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('EMAIL_API_KEY', $row['error']);
        $this->assertNull($row['sent_to']);
        $this->assertSame([], $this->sent);
        $this->assertNotSame('', $row['html']);
    }

    public function testScheduledRunSendsOncePerDate(): void
    {
        $svc   = $this->service();
        $first = $svc->runScheduled('2026-10-17');

        $this->assertSame('sent', $first['status']);
        $this->assertSame([['to' => 'pno@aicountly.com', 'subject' => $this->sent[0]['subject']]], $this->sent);
        $this->assertStringStartsWith('AICOUNTLY daily report', $this->sent[0]['subject']);

        $second = $svc->runScheduled('2026-10-17');
        $this->assertSame('skipped', $second['status']);
        $this->assertCount(1, $this->sent, 'The scheduler never re-sends a date.');

        // An explicit send does (and the row stays a single row per date).
        $svc->send('2026-10-17', ['ops@aicountly.com']);
        $this->assertCount(2, $this->sent);
        $this->assertSame(1, (int) Database::connect()->table('reach_daily_reports')->countAllResults());
    }

    public function testGatewayRejectionIsFailedWithReason(): void
    {
        $row = $this->service(accept: false)->send('2026-10-17', ['a@aicountly.com', 'b@aicountly.com']);

        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('a@aicountly.com: HTTP 401', $row['error']);
    }

    public function testApiListPreviewAndSendIsSuperAdminOnly(): void
    {
        $this->service()->generate('2026-10-16');

        $admin = $this->authAs('reach_admin');
        $list  = $this->withHeaders($admin)->get('v1/reports/daily');
        $list->assertStatus(200);
        $data = json_decode($list->getJSON(), true)['data'];
        $this->assertSame('2026-10-16', $data['reports'][0]['report_date']);
        $this->assertFalse($data['can_send']);
        $this->assertSame(['pno@aicountly.com'], $data['recipients']);

        $this->withHeaders($admin)->get('v1/reports/daily/' . $data['reports'][0]['id'])->assertStatus(200);
        $this->withHeaders($admin)->get('v1/reports/daily/preview')->assertStatus(200);
        $this->withHeaders($admin)->post('v1/reports/daily/send')->assertStatus(403);

        $viewer = $this->authAs('viewer');
        $this->withHeaders($viewer)->get('v1/reports/daily')->assertStatus(403);

        // Super admin without EMAIL_API_KEY: the API says "not sent", never success.
        $super = $this->authAs('super_admin');
        $resp  = $this->withHeaders($super)->withBodyFormat('json')->post('v1/reports/daily/send', ['date' => '2026-10-16']);
        $resp->assertStatus(502);
        $this->assertStringContainsString('not sent', (string) json_decode($resp->getJSON(), true)['error']);
    }

    public function testSeoTrackerAndSiteSearchEndpoints(): void
    {
        $admin = $this->authAs('reach_admin');

        $tracker = $this->withHeaders($admin)->get('v1/seo/tracker');
        $tracker->assertStatus(200);
        $body = json_decode($tracker->getJSON(), true)['data'];
        $this->assertSame('not_configured', $body['status']['status']);
        $this->assertGreaterThanOrEqual(30, count($body['keywords']));
        $this->assertNull($body['keywords'][0]['our_rank']);

        $this->withHeaders($admin)->post('v1/seo/tracker/run')->assertStatus(409);

        $site = $this->withHeaders($admin)->get('v1/intelligence/search/site/daily');
        $site->assertStatus(200);
        $siteBody = json_decode($site->getJSON(), true)['data'];
        $this->assertFalse($siteBody['available']);
        $this->assertSame([], $siteBody['days']);

        foreach (['queries', 'pages', 'positions'] as $leaf) {
            $this->withHeaders($admin)->get('v1/intelligence/search/site/' . $leaf)->assertStatus(200);
        }
    }
}
