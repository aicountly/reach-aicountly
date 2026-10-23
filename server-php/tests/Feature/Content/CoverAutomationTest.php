<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Blog\CoverSweepService;
use App\Libraries\Blog\WorkBlockService;
use App\Libraries\Media\CoverGalleryAutofillService;
use App\Libraries\Media\CoverGenerationAlertService;
use App\Libraries\Media\CoverGenerator;
use App\Libraries\Media\CoverRefreshService;
use App\Libraries\Media\CoverSelectionService;
use App\Libraries\Reports\DailyReportBuilder;
use App\Libraries\Reports\DailyReportRenderer;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeCoverImageProvider;

/**
 * Covers without a human (owner decision, 18 Oct 2026).
 *
 * Reach used to email "Cover-image gallery deficit: N image(s) needed …
 * Upload at: Reach console → Quality Centre → Cover Gallery" and park
 * articles until someone did. It now stocks the gallery itself with
 * GPT Image covers (branded), decides per article between a relevant
 * gallery cover and a fresh one, releases parked articles when a cover
 * lands, reports the numbers in the daily report and emails only when
 * generation keeps failing.
 */
final class CoverAutomationTest extends ApiTestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::hasTestDatabase()) {
            return;
        }
        $this->mediaDir = sys_get_temp_dir() . '/reach-cover-test-' . bin2hex(random_bytes(4));
        $this->env('MEDIA_STORAGE_PATH', $this->mediaDir);
        $this->env('MEDIA_SIGNING_KEY', str_repeat('a', 64));
        $this->env('BLOG_REQUIRE_COVER_IMAGE', 'true');
        $this->env('BLOG_AUTOMATION_ENABLED', 'true');
        unset($_ENV['BLOG_IMAGE_GENERATION_ENABLED']);
        putenv('BLOG_IMAGE_GENERATION_ENABLED');
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach (['MEDIA_STORAGE_PATH', 'MEDIA_SIGNING_KEY', 'BLOG_REQUIRE_COVER_IMAGE', 'BLOG_AUTOMATION_ENABLED', 'BLOG_IMAGE_GENERATION_ENABLED'] as $k) {
            unset($_ENV[$k]);
            putenv($k);
        }
        if (isset($this->mediaDir) && is_dir($this->mediaDir)) {
            exec('rm -rf ' . escapeshellarg($this->mediaDir));
        }
        ReachConfig::flush();
        parent::tearDown();
    }

    private function env(string $k, string $v): void
    {
        $_ENV[$k] = $v;
        putenv("{$k}={$v}");
    }

    private function setting(string $key, mixed $value): void
    {
        ReachConfig::set($key, $value);
        ReachConfig::flush();
    }

    /** @return list<array<string,mixed>> */
    private function indexEntries(): array
    {
        return array_values(array_filter(
            (array) ((new ContentBaseService())->blogIndex()['entries'] ?? []),
            static fn ($e) => in_array(strtolower((string) ($e['status'] ?? 'planned')), ['planned', 'drafting'], true),
        ));
    }

    /** The entry the autofill stocks first: earliest target date, then key. */
    private function firstStockedEntry(): array
    {
        $entries = $this->indexEntries();
        usort($entries, static fn ($a, $b) => [($a['target_date'] ?? '') ?: '9999', $a['key']] <=> [($b['target_date'] ?? '') ?: '9999', $b['key']]);

        return $entries[0];
    }

    private function autofill(FakeCoverImageProvider $provider, ?\Closure $sweep = null): CoverGalleryAutofillService
    {
        return new CoverGalleryAutofillService(new CoverGenerator($provider), null, null, $sweep ?? static fn () => ['scanned' => 0]);
    }

    private function seedArticle(string $title, array $extra = [], string $status = BlogStateMachine::FACT_VERIFIED, ?string $cover = null): int
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('reach_content_items')->insert($extra + [
            'uuid'               => bin2hex(random_bytes(16)),
            'content_type'       => 'blog',
            'title'              => $title,
            'slug'               => 'cov-' . bin2hex(random_bytes(5)),
            'workflow_status'    => $status,
            'approval_status'    => 'approved',
            'created_actor_type' => 'system',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $id = (int) $db->insertID();
        $db->table('reach_content_blog_details')->insert([
            'content_item_id'  => $id,
            'origin'           => 'claude_routine',
            'portfolio_stream' => 'marketing',
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $db->table('reach_blog_publication_profiles')->insert([
            'content_item_id'          => $id,
            'featured_image_reference' => $cover,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        return $id;
    }

    private function seedUpload(array $tags, ?string $stream = null, string $provenance = 'operator_upload'): array
    {
        $db   = Database::connect();
        $key  = bin2hex(random_bytes(8));
        @mkdir($this->mediaDir, 0755, true);
        $path = $this->mediaDir . '/up-' . $key . '.webp';
        file_put_contents($path, 'fake-webp-binary');
        $db->table('reach_media_gallery_assets')->insert([
            'asset_uuid'       => 'aaaaaaaa-bbbb-4ccc-8ddd-' . substr($key, 0, 12),
            'kind'             => 'gallery_upload',
            'file_path'        => $path,
            'mime'             => 'image/webp',
            'bytes'            => 16,
            'checksum_sha256'  => hash('sha256', $key),
            'category_tags'    => json_encode($tags),
            'portfolio_stream' => $stream,
            'provenance'       => $provenance,
            'status'           => 'active',
        ]);

        return $db->table('reach_media_gallery_assets')->where('id', (int) $db->insertID())->get()->getRowArray();
    }

    // ── deficit → generation ───────────────────────────────────────────────

    public function testTheDeficitIsFilledWithBrandedGalleryCoversTaggedToTheirEntries(): void
    {
        $entries  = $this->indexEntries();
        $provider = new FakeCoverImageProvider();

        $result = $this->autofill($provider)->run(3);

        $this->assertSame('ok', $result['status'], json_encode($result));
        $this->assertSame(count($entries), $result['deficit']);
        $this->assertSame(3, $result['generated']);
        $this->assertSame(3, $provider->calls);

        $db     = Database::connect();
        $assets = $db->table('reach_media_gallery_assets')->where('kind', 'ai_gallery')->orderBy('id')->get()->getResultArray();
        $this->assertCount(3, $assets);
        $keys = array_column($entries, 'key');
        foreach ($assets as $i => $asset) {
            $this->assertSame('active', $asset['status']);
            $this->assertContains($asset['source_key'], $keys);
            $this->assertSame('aicountly_generated', $asset['provenance']);
            $this->assertTrue(in_array($asset['branded'], [true, 't', 1, '1'], true), 'the Aicountly logo is composited');
            $this->assertFileExists((string) $asset['file_path']);
            $this->assertFileExists((string) $asset['original_file_path'], 'the unbranded original is kept');
            $this->assertNotSame('[]', $asset['category_tags']);
            $this->assertSame('gpt-image-2.5-flare', $asset['model_key']);
        }

        // Prompts come from the entry (title + brief + scene), in the brand style, without text.
        $first = $this->firstStockedEntry();
        $this->assertStringContainsString($first['title'], $provider->prompts[0]);
        $this->assertStringContainsString('green and white brand palette', $provider->prompts[0]);
        $this->assertStringContainsString('Strictly no text', $provider->prompts[0]);
        $this->assertStringNotContainsString('Theme: marketing', $provider->prompts[0]);

        $this->assertSame(3, $db->table('reach_cover_generation_attempts')->where('status', 'generated')->where('purpose', 'gallery_stock')->countAllResults());
    }

    public function testStockingIsIdempotentPerEntryAndStopsAtTheDailyCap(): void
    {
        $this->setting('covers.auto.daily_limit', 4);
        $provider = new FakeCoverImageProvider();

        $this->assertSame(3, $this->autofill($provider)->run(3)['generated']);
        $second = $this->autofill($provider)->run(3);
        $this->assertSame(1, $second['generated'], 'only one left under the cap of 4');
        $third = $this->autofill($provider)->run(3);
        $this->assertSame('capped', $third['status']);
        $this->assertSame(4, $provider->calls, 'nothing is generated past the cap');

        $keys = array_column(Database::connect()->table('reach_media_gallery_assets')->select('source_key')->where('kind', 'ai_gallery')->get()->getResultArray(), 'source_key');
        $this->assertCount(4, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)), 'never two covers for one entry');

        // Lift the cap: the remaining entries are stocked, the stocked ones are never redone.
        $this->setting('covers.auto.daily_limit', 50);
        $remaining = count($this->indexEntries()) - 4;
        $this->autofill($provider)->run(50);
        $this->assertSame(4 + $remaining, $provider->calls);
        $again = $this->autofill($provider)->run(50);
        $this->assertSame(0, $again['generated']);
        $this->assertSame(4 + $remaining, $provider->calls, 'a full gallery costs nothing');
    }

    public function testTheSwitchAndTheKillSwitchStopStocking(): void
    {
        $provider = new FakeCoverImageProvider();
        $this->setting('covers.auto.enabled', false);
        $this->assertSame('skipped', $this->autofill($provider)->run(3)['status']);

        $this->setting('covers.auto.enabled', true);
        $this->env('BLOG_IMAGE_GENERATION_ENABLED', 'false');
        $this->assertSame('skipped', $this->autofill($provider)->run(3)['status']);
        $this->assertSame(0, $provider->calls);
    }

    public function testAnUnconfiguredProviderStoresNothingAndSaysSo(): void
    {
        $result = $this->autofill(new FakeCoverImageProvider(false))->run(3);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('not_configured', $result['failures'][0]['kind']);
        $this->assertSame(0, Database::connect()->table('reach_media_gallery_assets')->where('kind', 'ai_gallery')->countAllResults());
        $this->assertSame(1, Database::connect()->table('reach_cover_generation_attempts')->where('status', 'failed')->countAllResults());
    }

    // ── reuse vs generate ─────────────────────────────────────────────────

    public function testAnArticleReusesTheCoverStockedForItsEntry(): void
    {
        $entry    = $this->firstStockedEntry();
        $provider = new FakeCoverImageProvider();
        $this->autofill($provider)->run(1);
        $this->assertSame(1, $provider->calls);

        $itemId    = $this->seedArticle((string) $entry['title'], ['content_base_key' => $entry['key']]);
        $selection = (new CoverSelectionService(new CoverGenerator($provider)))->coverFor($itemId);

        $this->assertTrue($selection['ok']);
        $this->assertSame('gallery', $selection['source']);
        $this->assertContains('source_key', $selection['match_reasons']);
        $this->assertSame(1, $provider->calls, 'reuse costs nothing');
    }

    public function testAnUnrelatedOrStreamOnlyCoverIsNotReusedAFreshOneIsGeneratedAndKept(): void
    {
        // The live-site failure: unrelated photos on GST / TDS / MSME articles.
        $this->seedUpload(['travel', 'cycling', 'forest'], 'marketing');
        $this->seedUpload([], 'marketing');
        $provider = new FakeCoverImageProvider();

        $itemId = $this->seedArticle('GSTR-3B for September 2026: due dates and late fees');
        $selection = (new CoverSelectionService(new CoverGenerator($provider)))->coverFor($itemId);

        $this->assertTrue($selection['ok'], json_encode($selection));
        $this->assertSame('generated', $selection['source']);
        $this->assertSame(1, $provider->calls);
        $this->assertStringContainsString('GSTR-3B for September 2026', $provider->prompts[0], 'the prompt is the article, not a generic one');

        $asset = Database::connect()->table('reach_media_gallery_assets')->where('id', $selection['asset_id'])->get()->getRowArray();
        $this->assertSame('ai_gallery', $asset['kind'], 'a fresh cover joins the gallery');
        $this->assertSame(1, (int) $asset['times_used']);
        $this->assertStringContainsString('gstr', (string) $asset['category_tags']);
    }

    public function testARelevantUploadIsReusedAndBrandedOnTheWayOut(): void
    {
        $upload   = $this->seedUpload(['tds', 'return', 'checklist']);
        $provider = new FakeCoverImageProvider();
        // A real image on disk so branding can run.
        $img = imagecreatetruecolor(1536, 1024);
        ob_start();
        imagewebp($img);
        file_put_contents((string) $upload['file_path'], (string) ob_get_clean());

        $itemId    = $this->seedArticle('TDS return checklist for Q2');
        $selection = (new CoverSelectionService(new CoverGenerator($provider)))->coverFor($itemId);

        $this->assertSame('gallery', $selection['source']);
        $this->assertSame((int) $upload['id'], $selection['asset_id']);
        $this->assertTrue($selection['branded']);
        $this->assertSame(0, $provider->calls);
        $row = Database::connect()->table('reach_media_gallery_assets')->where('id', (int) $upload['id'])->get()->getRowArray();
        $this->assertFileExists((string) $row['original_file_path']);
    }

    // ── parked article released ───────────────────────────────────────────

    public function testAParkedArticleIsReleasedOnceItsCoverIsStocked(): void
    {
        $entry  = $this->firstStockedEntry();
        $itemId = $this->seedArticle((string) $entry['title'], ['content_base_key' => $entry['key']], BlogStateMachine::INTERNAL_REVIEW);
        $db     = Database::connect();
        $db->table('reach_content_validations')->insert([
            'content_item_id' => $itemId, 'validation_type' => 'cover_image', 'validation_status' => 'failed',
            'message' => 'No suitable cover image could be sourced', 'run_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Morning: the sweep retries and misses (no gallery cover, no OpenAI key in tests).
        $workBlocks = new WorkBlockService();
        $first = (new CoverSweepService(null, $workBlocks))->sweep();
        $this->assertSame([$itemId], $first['content_item_ids']);
        $block = $db->table('reach_work_blocks')->where('content_item_id', $itemId)->where('block_type', 'GENERATE_IMAGE')->get()->getRowArray();
        $miss  = $workBlocks->execute((int) $block['id']);
        $this->assertTrue($miss['image_failed'] ?? false, json_encode($miss));
        $db->table('reach_work_blocks')->where('id', (int) $block['id'])->update(['updated_at' => date('Y-m-d H:i:s', time() - 120)]);

        // Later: the autofill stocks the entry's cover and runs the sweep, which re-arms today's block.
        $result = (new CoverGalleryAutofillService(new CoverGenerator(new FakeCoverImageProvider())))->run(1);
        $this->assertSame(1, $result['generated']);
        $this->assertContains($itemId, $result['sweep']['content_item_ids'] ?? [], json_encode($result['sweep']));

        $out = $workBlocks->execute((int) $block['id']);
        $this->assertArrayNotHasKey('image_failed', $out, json_encode($out));
        $this->assertTrue($out['gallery_assigned']);
        $profile = $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertStringContainsString('/api/v1/public/media/', (string) $profile['featured_image_reference']);
        $validation = $db->table('reach_content_validations')->where('content_item_id', $itemId)->where('validation_type', 'cover_image')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('passed', $validation['validation_status']);
    }

    public function testWithoutAProviderTheArticleStaysParkedAndNothingClaimsACover(): void
    {
        $itemId = $this->seedArticle('Payroll month-end checklist');
        $result = (new CoverSelectionService(new CoverGenerator(new FakeCoverImageProvider(false))))->coverFor($itemId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', (string) $result['reason']);
        $profile = Database::connect()->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertSame('', (string) $profile['featured_image_reference']);
    }

    // ── the email → report line + failure alert ───────────────────────────

    public function testNoEmailWhileGenerationWorksAndTheReportCarriesTheCoversLine(): void
    {
        $this->autofill(new FakeCoverImageProvider())->run(2);
        $mails = [];
        $alert = new CoverGenerationAlertService(null, static function ($to, $subject, $body) use (&$mails) { $mails[] = $body; return true; }, static fn () => true);

        $out = $alert->run();
        $this->assertFalse($out['sent']);
        $this->assertSame('no_repeated_failures', $out['reason']);
        $this->assertSame([], $mails, 'the deficit alone never emails anyone');

        $summary = (new DailyReportBuilder(null, null, null, static fn () => false, static fn () => 'email: test'))->build(gmdate('Y-m-d', time() + 86400));
        $covers  = $summary['sections']['covers'];
        $this->assertSame(2, $covers['generated']);
        $this->assertStringStartsWith('Covers: 2 generated automatically (24h), gallery has 2 active, deficit ', $covers['line']);
        $text = DailyReportRenderer::text($summary);
        $this->assertStringContainsString('== Cover images ==', $text);
        $this->assertStringContainsString('Covers: 2 generated automatically', $text);
        $this->assertStringNotContainsString('Upload at', $text);
        $this->assertStringContainsString('Cover images', DailyReportRenderer::html($summary));
    }

    public function testRepeatedFailuresSendOneInformationalAlertThatSaysWhatFailed(): void
    {
        $this->setting('covers.alerts.recipients', 'ops@aicountly.com');
        $generator = new CoverGenerator(new FakeCoverImageProvider(true, 'HTTP 400: model gpt-image-2.5-flare is not available'));
        foreach ([1, 2] as $_) { // two failures per run, then the run stops
            (new CoverGalleryAutofillService($generator, null, null, static fn () => null))->run(1);
        }

        $mails = [];
        $out   = (new CoverGenerationAlertService(null, static function ($to, $subject, $body) use (&$mails) { $mails[] = compact('to', 'subject', 'body'); return true; }, static fn () => true))->run();

        $this->assertTrue($out['sent'], json_encode($out));
        $this->assertCount(1, $mails);
        $this->assertSame(['ops@aicountly.com'], $mails[0]['to']);
        $this->assertStringContainsString('informational', $mails[0]['subject']);
        $this->assertStringContainsString('4 attempt(s) failed', $mails[0]['body']);
        $this->assertStringContainsString('model gpt-image-2.5-flare is not available', $mails[0]['body']);
        $this->assertStringNotContainsString('Upload at', $mails[0]['body']);
        $this->assertStringNotContainsString('Prompts to generate', $mails[0]['body']);

        $status  = static fn (array $w) => (new CoverGenerationAlertService(null, null, static fn () => true))->status($w['start'], $w['end']);
        $summary = (new DailyReportBuilder(null, null, null, static fn () => false, static fn () => 'email: test', $status))->build(gmdate('Y-m-d', time() + 86400));
        $this->assertStringContainsString('4 generation attempt(s) failed', $summary['sections']['covers']['line']);
        $this->assertNotEmpty(array_filter($summary['sections']['todo']['items'], static fn ($i) => str_contains($i, 'Cover generation failed 4 time(s)')));

        // The off switch is honoured.
        $this->setting('covers.alerts.enabled', false);
        $off = (new CoverGenerationAlertService(null, static fn () => true, static fn () => true))->run();
        $this->assertSame('alerts_disabled', $off['reason']);
    }

    public function testAMissingKeyWithADeficitAlertsEvenBeforeAnyAttempt(): void
    {
        $mails = [];
        $out   = (new CoverGenerationAlertService(null, static function ($to, $s, $body) use (&$mails) { $mails[] = $body; return true; }, static fn () => false))->run();

        $this->assertTrue($out['sent']);
        $this->assertStringContainsString('OpenAI API key is not set', $mails[0]);
    }

    // ── reach:covers-refresh ──────────────────────────────────────────────

    public function testRefreshDryRunListsLivePostsWithoutAnAicountlyCoverAndSpendsNothing(): void
    {
        $upload   = $this->seedUpload(['gst']);
        $noCover  = $this->seedArticle('GSTR-3B for September 2026', [], BlogStateMachine::PUBLISHED);
        $stock    = $this->seedArticle('MSME-1 half-yearly return', [], BlogStateMachine::PUBLISHED, 'https://picsum.photos/seed/1/800/450');
        $operator = $this->seedArticle('TDS return checklist', [], BlogStateMachine::PUBLISHED,
            'https://reach.aicountly.org/api/v1/public/media/' . $upload['asset_uuid'] . '.webp?sig=x');
        $this->seedArticle('Draft not yet live', [], BlogStateMachine::FACT_VERIFIED);

        $provider = new FakeCoverImageProvider();
        $report   = (new CoverRefreshService(new CoverGenerator($provider), null, static fn () => 99))->run(true, 10);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(3, $report['found']);
        $byId = array_column($report['items'], null, 'id');
        $this->assertSame('no_cover', $byId[$noCover]['reason']);
        $this->assertSame('not_a_reach_cover', $byId[$stock]['reason']);
        $this->assertSame('operator_upload', $byId[$operator]['reason']);
        $this->assertSame('would_regenerate_and_republish', $byId[$noCover]['action']);
        $this->assertSame(0, $provider->calls);
    }

    public function testRefreshRegeneratesWithinTheCapAndRepublishesCoverOnly(): void
    {
        $this->setting('covers.auto.daily_limit', 1);
        $a = $this->seedArticle('GSTR-3B for September 2026', [], BlogStateMachine::PUBLISHED);
        $b = $this->seedArticle('MSME-1 half-yearly return', [], BlogStateMachine::PUBLISHED, 'https://picsum.photos/seed/2/800/450');

        $republished = [];
        $provider    = new FakeCoverImageProvider();
        $report      = (new CoverRefreshService(new CoverGenerator($provider), null, static function (int $id, int $version) use (&$republished) {
            $republished[] = $id;

            return 500 + $id;
        }))->run(false, 10);

        $this->assertSame(1, $provider->calls, 'the daily cap holds');
        $this->assertSame([$a], $republished);
        $byId = array_column($report['items'], null, 'id');
        $this->assertSame('regenerated_and_republish_queued', $byId[$a]['action']);
        $this->assertSame('deferred_daily_cap', $byId[$b]['action']);

        $profile = Database::connect()->table('reach_blog_publication_profiles')->where('content_item_id', $a)->get()->getRowArray();
        $this->assertStringContainsString('/api/v1/public/media/', (string) $profile['featured_image_reference']);
        $this->assertNull((new CoverRefreshService(new CoverGenerator($provider)))->reason((string) $profile['featured_image_reference']),
            'a branded Aicountly-generated cover is left alone next time');
        $this->assertStringContainsString('GSTR-3B for September 2026', $provider->prompts[0]);
    }
}
