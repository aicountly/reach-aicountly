<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Libraries\ActorRegistry;
use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageGenerationResult;
use App\Libraries\Ai\Images\ImageProviderInterface;
use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineCoverService;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Blog\AutomationDraftIngestService;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Blog\BlogPublishVetGate;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\BlogVetRefused;
use App\Libraries\Content\Quality\ContentRevetService;
use App\Libraries\Content\Quality\RegenerationQueue;
use App\Libraries\ContentVersionService;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Live-content clean-up end to end, with fakes only at the Gemini and image
 * HTTP boundaries and the mock public-site publisher:
 *
 *   - the publish gate: no blog version goes out without the vetting model
 *     approving THAT version; a routine approval passes without a second call;
 *   - reach:content-revet: dry run changes nothing; a live page Gemini rejects
 *     is unpublished (withdrawn), queued for a rewrite, the routine fetches it
 *     from regenerate/next, submits the rewrite with regeneration_id, and it is
 *     republished at the SAME slug and public record with a fresh cover;
 *   - approved-but-unpublished items: a pass is adopted into the routine
 *     pipeline without using quota, a fail is retired and queued;
 *   - heuristic flags alone never take anything down.
 *
 * @internal
 */
final class ContentQualityCleanupTest extends DatabaseTestCase
{
    /** @var list<array{status:int, body:string}> */
    private array $gemini = [];
    private int $geminiCalls = 0;
    private string $mediaDir = '';
    private array $envBackup = [];

    private const ENV = [
        'CI_ENVIRONMENT' => 'testing', 'REACH_PUB_MOCK' => 'true',
        'AI_GEMINI_API_KEY' => 'test-gemini-key', 'AI_GEMINI_DEFAULT_MODEL' => 'gemini-test-flash',
        'MEDIA_SIGNING_KEY' => 'test-media-signing-key-0123456789abcdef', 'BLOG_REQUIRE_COVER_IMAGE' => 'true',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaDir = sys_get_temp_dir() . '/reach-quality-media-' . bin2hex(random_bytes(4));
        foreach (self::ENV + ['MEDIA_STORAGE_PATH' => $this->mediaDir] as $k => $v) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        foreach (['BLOG_AUTO_PUBLISH_ENABLED', 'BLOG_PUBLIC_PUBLISHER_ENABLED', 'BLOG_IMAGE_GENERATION_ENABLED', 'BLOG_AUTOMATION_ENABLED'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
            putenv($k);
        }
        ReachConfig::flush();
        PublicSitePublisherFactory::resetMock();
        $this->gemini = [];
        $this->geminiCalls = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        ReachConfig::flush();
        PublicSitePublisherFactory::resetMock();
        parent::tearDown();
    }

    // ── fakes / fixtures ────────────────────────────────────────────────────

    private function verdict(string $decision, array $reasons = [], array $final = []): array
    {
        $text = json_encode([
            'decision' => $decision, 'reasons' => $reasons,
            'scores' => ['accuracy' => 9, 'clarity' => 8, 'compliance' => 9, 'tone' => 9], 'final_json' => json_encode($final),
        ]);

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    private function vetting(): ContentVettingService
    {
        return new ContentVettingService(function (string $url, array $body): array {
            $this->geminiCalls++;

            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        });
    }

    private function images(): ImageProviderInterface
    {
        return new class () implements ImageProviderInterface {
            public int $calls = 0;
            public function getProviderKey(): string { return 'openai_image'; }
            public function isConfigured(): bool { return true; }
            public function healthCheck(): AiProviderHealthResult { throw new \LogicException('unused'); }
            public function classifyError(\Throwable $error): AiProviderError { throw new \LogicException('unused'); }
            public function generateImage(ImageGenerationInput $input): ImageGenerationResult
            {
                $this->calls++;
                $img = imagecreatetruecolor(1536, 1024);
                imagefilledrectangle($img, 0, 0, 1535, 1023, imagecolorallocate($img, 20, 140, 90 + $this->calls));
                ob_start();
                imagepng($img);
                $png = (string) ob_get_clean();

                return new ImageGenerationResult([['b64' => base64_encode($png), 'url' => null, 'mime' => 'image/png']], 'openai_image', 'gpt-image', null, 5, 1);
            }
        };
    }

    private function pipeline(?ImageProviderInterface $images = null): RoutineContentPipeline
    {
        return new RoutineContentPipeline($this->vetting(), new RoutineCoverService($images ?? $this->images()));
    }

    private function body(string $topic = 'TDS'): string
    {
        $html = '<h2>What ' . $topic . ' compliance involves</h2>';
        foreach (['deduction', 'deposit', 'returns', 'certificates', 'late fees', 'lower deduction', 'reconciliation', 'records'] as $i => $t) {
            $html .= "<p>{$topic} {$t} is part of everyday compliance for growing companies. Step {$i}: check {$t} every month against "
                . "your books, note every difference and fix it before the due date. A short {$topic} checklist keeps the finance team on "
                . "track when the month gets busy and stops interest and fees from building up.</p>";
        }

        return $html;
    }

    private function filler(): string
    {
        return str_repeat('<p>Digital content creation is an evolving landscape. Brands must craft engaging stories, plan an editorial '
            . 'calendar and measure engagement across channels to grow an audience and build trust over time. </p>', 12);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title'            => 'TDS Compliance Basics for Growing Companies',
            'summary'          => 'What TDS deduction, deposit and returns involve for a growing company.',
            'body_html'        => $this->body(),
            'seo'              => ['slug' => 'tds-compliance-basics-for-growing-companies', 'meta_title' => 'TDS compliance basics for growing companies',
                                   'meta_description' => 'TDS deduction, deposit, returns and certificates explained for growing Indian companies, with a monthly checklist to stay compliant.'],
            'category'         => 'TDS',
            'content_base_key' => 'blog-20261018-tds-basics',
            'provenance'       => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session_test'],
        ];
    }

    private function item(int $id): array
    {
        return Database::connect()->table('reach_content_items')->where('id', $id)->get()->getRowArray();
    }

    /** A routine article taken all the way to live through the real pipeline. */
    private function publishLiveArticle(): array
    {
        $this->gemini = [$this->verdict('approve')];
        $ingest = (new AutomationDraftIngestService())->ingestBlogDraft($this->payload());
        $row    = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));

        return $ingest;
    }

    /** Swap the live article's current text for filler, as the retired generator wrote it. */
    private function corrupt(int $itemId): int
    {
        $db = Database::connect();
        $v  = (new ContentVersionService())->createVersion($itemId, [
            'title' => 'TDS Compliance Basics for Growing Companies', 'summary' => 'Digital content creation for brands.',
            'body_html' => $this->filler(), 'body_plain_text' => strip_tags($this->filler()),
        ], ['type' => 'bot', 'service' => 'reach:work_block:generate_draft'], 'AI-generated draft');

        return (int) $v['id'];
    }

    /** A legacy (non-routine) blog approved by a person, never published. */
    private function humanApprovedBlog(string $title, string $body, string $slug): array
    {
        $db = Database::connect();
        $ingest = (new AutomationDraftIngestService())->ingestBlogDraft($this->payload([
            'title' => $title, 'body_html' => $body, 'content_base_key' => 'legacy-' . $slug, 'seo' => ['slug' => $slug] + $this->payload()['seo'],
        ]));
        // Make it look like the old path: no routine submission, approved by a person.
        $db->table('reach_routine_submissions')->where('id', $ingest['submission_id'])->delete();
        $itemId = (int) $ingest['content_item_id'];
        $roleId = (int) ($db->table('reach_roles')->select('id')->orderBy('id')->limit(1)->get()->getRowArray()['id'] ?? 0);
        $db->table('reach_users')->insert(['email' => 'editor-' . $slug . '@aicountly.org', 'name' => 'Editor', 'password_hash' => 'x', 'role_id' => $roleId ?: null, 'is_active' => true]);
        $userId = (int) $db->insertID();
        ActorRegistry::idForUser($userId);
        $sm = new BlogStateMachine();
        $sm->transition($itemId, BlogStateMachine::INTERNAL_REVIEW);
        $sm->transition($itemId, BlogStateMachine::APPROVED);
        (new BlogContentApprovalService())->approve($itemId, (int) $this->item($itemId)['current_version_id'], $userId, 'standard', 'approved by a person');
        $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->update([
            'featured_image_reference' => 'https://reach.aicountly.org/api/v1/public/media/fixture.webp?sig=t', 'featured_image_alt' => 'Fixture cover',
        ]);

        return ['item_id' => $itemId, 'version_id' => (int) $this->item($itemId)['current_version_id']];
    }

    // ── publish gate ────────────────────────────────────────────────────────

    public function testThePublishGateRefusesAVersionGeminiRejectsAndBindsAnApproval(): void
    {
        $legacy = $this->humanApprovedBlog('TDS Compliance Basics for Growing Companies', $this->filler(), 'tds-filler');
        $pub    = new PublicationDeploymentService(null, null, null, new BlogPublishVetGate($this->vetting()));

        $this->gemini = [$this->verdict('reject', ['OFF_TOPIC: the body is about digital content creation, not TDS.'])];
        try {
            $pub->enqueuePublication($legacy['item_id'], $legacy['version_id'], 'aicountly_com', 'publish');
            $this->fail('an off-topic version must not be queued for publication');
        } catch (BlogVetRefused $e) {
            $this->assertSame('reject', $e->decision);
            $this->assertStringContainsString('OFF_TOPIC', $e->getMessage());
        }
        $db = Database::connect();
        $this->assertSame(0, $db->table('reach_publication_deployments')->where('content_item_id', $legacy['item_id'])->countAllResults());
        $check = $db->table('reach_content_quality_checks')->where('subject_id', $legacy['item_id'])->get()->getRowArray();
        $this->assertSame('fail', $check['decision']);
        $this->assertStringContainsString('generic_filler', $check['heuristic_flags']);

        // Vetting unavailable: refused, never a silent pass.
        $this->gemini = [];
        try {
            $pub->enqueuePublication($legacy['item_id'], $legacy['version_id'], 'aicountly_com', 'publish');
            $this->fail('unvetted content must not publish during an outage');
        } catch (BlogVetRefused $e) {
            $this->assertTrue($e->unavailable);
        }

        // A genuine article approved by a person is vetted once, then the approval carries the run.
        $good = $this->humanApprovedBlog('Advance tax instalments for small companies', $this->body('advance tax'), 'advance-tax');
        $this->gemini = [$this->verdict('approve')];
        $before = $this->geminiCalls;
        $deploymentId = $pub->enqueuePublication($good['item_id'], $good['version_id'], 'aicountly_com', 'publish');
        $this->assertGreaterThan(0, $deploymentId);
        $this->assertSame($before + 1, $this->geminiCalls);
        $status = (new BlogPublishVetGate($this->vetting()))->status($good['item_id'], $good['version_id']);
        $this->assertTrue($status['vetted']);
        // Replays and the send itself do not call Gemini again.
        $pub->enqueuePublication($good['item_id'], $good['version_id'], 'aicountly_com', 'publish');
        $this->assertSame($before + 1, $this->geminiCalls);
    }

    public function testADeploymentQueuedBeforeTheGateIsVettedAtSendTime(): void
    {
        $legacy = $this->humanApprovedBlog('TDS Compliance Basics for Growing Companies', $this->filler(), 'tds-old-queue');
        $db = Database::connect();
        $conn = (new \App\Libraries\Publishing\PublicationConnectionService())->resolveBlogConnectionKey();
        $connId = (int) $db->table('reach_publication_connections')->where('connection_key', $conn)->get()->getRowArray()['id'];
        $db->table('reach_publication_deployments')->insert([
            'content_item_id' => $legacy['item_id'], 'content_version_id' => $legacy['version_id'], 'connection_id' => $connId,
            'operation' => 'publish', 'status' => 'queued', 'idempotency_key' => 'pre-gate-' . $legacy['item_id'],
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $deploymentId = (int) $db->insertID();

        $this->gemini = [$this->verdict('reject', ['OFF_TOPIC: not about TDS.'])];
        (new PublicationDeploymentService(null, null, null, new BlogPublishVetGate($this->vetting())))->processDeployment($deploymentId);

        $d = $db->table('reach_publication_deployments')->where('id', $deploymentId)->get()->getRowArray();
        $this->assertSame('failed', $d['status']);
        $this->assertSame('vetting_refused', $d['error_category']);
        $this->assertSame([], PublicSitePublisherFactory::mock()->getCalls(), 'nothing was sent to the public site');
    }

    // ── live clean-up + same-slug regeneration ──────────────────────────────

    public function testALiveRejectIsUnpublishedQueuedRewrittenAndRepublishedAtTheSameSlug(): void
    {
        $ingest = $this->publishLiveArticle();
        $itemId = (int) $ingest['content_item_id'];
        $db     = Database::connect();
        $liveDeployment = $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->where('status', 'verified')->get()->getRowArray();
        $publicId = (int) $liveDeployment['public_content_id'];
        $this->corrupt($itemId);

        // Dry run: reported, nothing changes.
        $this->gemini = [$this->verdict('reject', ['OFF_TOPIC: the body is about digital content creation, not TDS.'])];
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live', 'dry_run' => true]);
        $this->assertSame('fail', $report['items'][0]['decision']);
        $this->assertSame('unpublish+queue_regeneration', $report['items'][0]['would']);
        $this->assertContains('generic_filler', $report['items'][0]['flags']);
        $this->assertSame(BlogStateMachine::LIVE, $this->item($itemId)['workflow_status']);
        $this->assertSame(0, $db->table('reach_content_regenerations')->countAllResults());

        // Apply: withdrawn on the public site, queued for a rewrite.
        $this->gemini = [$this->verdict('reject', ['OFF_TOPIC: the body is about digital content creation, not TDS.'])];
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live']);
        $this->assertSame('unpublished+queued_regeneration', $report['items'][0]['action']);
        $this->assertSame('unpublished', $this->item($itemId)['workflow_status']);
        $this->assertSame('unpublished', $db->table('reach_publication_deployments')->where('id', $liveDeployment['id'])->get()->getRowArray()['status']);
        $this->assertContains('unpublish', array_column(PublicSitePublisherFactory::mock()->getCalls(), 'method'));
        $this->assertSame('tds-compliance-basics-for-growing-companies', $this->item($itemId)['slug'], 'the slug stays reserved');

        // The routine asks for work first.
        $next = (new RegenerationQueue())->claimNext('blog');
        $this->assertNotNull($next);
        $this->assertSame($itemId, (int) $db->table('reach_content_regenerations')->where('id', $next['regeneration_id'])->get()->getRowArray()['content_item_id']);
        $this->assertSame('tds-compliance-basics-for-growing-companies', $next['slug']);
        $this->assertNull((new RegenerationQueue())->claimNext('blog'), 'a claimed item is not handed out twice');

        // The rewrite goes onto the same item, slug ignored, fresh cover requested.
        $oldCover = $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray()['featured_image_reference'];
        $rewrite  = (new AutomationDraftIngestService())->ingestBlogDraft($this->payload([
            'regeneration_id' => $next['regeneration_id'], 'content_base_key' => 'blog-other-key', 'seo' => ['slug' => 'a-new-slug'] + $this->payload()['seo'],
        ]));
        $this->assertSame('ingested', $rewrite['status']);
        $this->assertSame($itemId, $rewrite['content_item_id']);
        $this->assertSame('tds-compliance-basics-for-growing-companies', $rewrite['slug']);
        $this->assertNotEmpty($oldCover);
        $this->assertNull($db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray()['featured_image_reference'],
            'the old cover is cleared so the cover stage sources a fresh one');
        $sub = (new RoutineSubmissions())->findByItem($itemId);
        $this->assertSame(RoutineSubmissions::PENDING_VETTING, $sub['status']);
        $this->assertSame('regeneration', $sub['origin']);
        $version = $db->table('reach_content_versions')->where('id', $rewrite['content_version_id'])->get()->getRowArray();
        $this->assertStringContainsString('Regenerated replacement for v', (string) $version['change_summary']);

        // Normal path: vetted, approved, new cover, republished, verified.
        $images = $this->images();
        $this->gemini = [$this->verdict('approve', [], ['slug' => 'gemini-wants-a-new-slug'])];
        $row = $this->pipeline($images)->process((int) $sub['id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        $this->assertSame(1, $images->calls, 'a fresh cover is generated for the rewrite');
        $newCover = $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray()['featured_image_reference'];
        $this->assertNotEmpty($newCover);
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process((int) $sub['id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));

        $this->assertSame('tds-compliance-basics-for-growing-companies', $this->item($itemId)['slug'], 'republished at the same URL');
        $newDeployment = $db->table('reach_publication_deployments')->where('id', $row['deployment_id'])->get()->getRowArray();
        $this->assertSame($publicId, (int) $newDeployment['public_content_id'], 'the same public record was updated, not a new one');
        $this->assertSame('live', $db->table('reach_content_regenerations')->where('id', $next['regeneration_id'])->get()->getRowArray()['status']);
    }

    public function testHeuristicFlagsAloneNeverTakeALivePageDown(): void
    {
        $ingest = $this->publishLiveArticle();
        $this->corrupt((int) $ingest['content_item_id']);

        $this->gemini = [$this->verdict('approve')];
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live']);

        $this->assertSame('pass', $report['items'][0]['decision']);
        $this->assertSame('none', $report['items'][0]['action']);
        $this->assertNotSame([], $report['items'][0]['flags']);
        $this->assertSame(BlogStateMachine::LIVE, $this->item((int) $ingest['content_item_id'])['workflow_status']);
        $this->assertNotContains('unpublish', array_column(PublicSitePublisherFactory::mock()->getCalls(), 'method'));
    }

    public function testALiveReviseIsFlaggedForTheOwnerOnly(): void
    {
        $ingest = $this->publishLiveArticle();
        $this->gemini = [$this->verdict('revise', ['Generalise the deposit date.'], ['title' => 'TDS compliance basics']), $this->verdict('revise', ['Still too specific.'])];
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live']);

        $this->assertSame('revise', $report['items'][0]['decision']);
        $this->assertSame('flagged_for_owner', $report['items'][0]['action']);
        $this->assertSame(BlogStateMachine::LIVE, $this->item((int) $ingest['content_item_id'])['workflow_status']);
    }

    // ── approved but never published ────────────────────────────────────────

    public function testApprovedUnpublishedItemsAreAdoptedOrRetired(): void
    {
        ReachConfig::set('automation.blog.daily_limit', 1);
        $good = $this->humanApprovedBlog('Advance tax instalments for small companies', $this->body('advance tax'), 'advance-tax');
        $bad  = $this->humanApprovedBlog('GST filing checklist for small businesses',
            str_repeat('<p>This content supports the intent draft_generation within the content pipeline, based on the grounding context provided and the request parameters. </p>', 20), 'gst-filing-checklist');

        $this->gemini = [$this->verdict('approve'), $this->verdict('reject', ['OFF_TOPIC: the text describes the draft generation step, not GST filing.'])];
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'unpublished']);
        $byId   = array_column($report['items'], null, 'id');

        $this->assertSame('adopted_into_pipeline', $byId[$good['item_id']]['action']);
        $sub = (new RoutineSubmissions())->findByItem($good['item_id']);
        $this->assertSame('revet', $sub['origin']);
        $this->assertSame(RoutineSubmissions::PENDING_VETTING, $sub['status']);
        $this->assertSame(1, (new RoutineSubmissions())->quota('blog')['remaining_today'], 'an adopted item does not use the routine quota');

        $this->assertSame('retired+queued_regeneration', $byId[$bad['item_id']]['action']);
        $this->assertContains('meta_text:draft_generation', $byId[$bad['item_id']]['flags']);
        $this->assertSame('rejected', $this->item($bad['item_id'])['workflow_status']);
        $this->assertFalse((new BlogContentApprovalService())->verifyForPublication($bad['item_id'], $bad['version_id']));
        $regen = Database::connect()->table('reach_content_regenerations')->where('content_item_id', $bad['item_id'])->get()->getRowArray();
        $this->assertSame('queued', $regen['status']);
        $this->assertSame('gst-filing-checklist', $regen['slug']);
        $this->assertNotEmpty($regen['quality_check_id']);

        // The topic belongs to its queued rewrite: a fresh article on it is refused.
        try {
            (new AutomationDraftIngestService())->ingestBlogDraft($this->payload([
                'title' => 'GST filing checklist for small businesses', 'content_base_key' => 'blog-20261018-gst-again', 'seo' => ['slug' => 'gst-again'] + $this->payload()['seo'],
            ]));
            $this->fail('a topic queued for a rewrite must not be written again under a new URL');
        } catch (\App\Libraries\Automation\Routine\RoutineIntakeRefused $e) {
            $this->assertSame(409, $e->httpStatus);
            $this->assertSame((int) $regen['id'], $e->details['regeneration_id']);
        }
    }

    public function testTheDailySweepChecksOnlyNewLiveItemsOncePerVersion(): void
    {
        $ingest = $this->publishLiveArticle();
        $svc    = new ContentRevetService($this->vetting());

        $this->gemini = [$this->verdict('approve')];
        $first = $svc->run(['scope' => 'live', 'since' => date('Y-m-d H:i:s', time() - 3600), 'trigger' => 'daily_sweep']);
        $this->assertSame('pass', $first['items'][0]['decision']);

        $calls = $this->geminiCalls;
        $again = $svc->run(['scope' => 'live', 'since' => date('Y-m-d H:i:s', time() - 3600), 'trigger' => 'daily_sweep']);
        $this->assertSame('skipped', $again['items'][0]['decision']);
        $this->assertSame($calls, $this->geminiCalls, 'an already-checked version is not vetted again');

        $old = $svc->run(['scope' => 'live', 'since' => date('Y-m-d H:i:s', time() + 3600), 'trigger' => 'daily_sweep']);
        $this->assertSame([], $old['items'], 'items live before the window are out of scope');
        $this->assertSame((int) $ingest['content_item_id'], $first['items'][0]['id']);
    }

    // ── publishing state: publish now, due schedules, unsent deployments ───

    private function vettedHumanApproved(string $slug): array
    {
        $b = $this->humanApprovedBlog('Advance tax instalments for small companies', $this->body('advance tax'), $slug);
        $this->gemini = [$this->verdict('approve')];
        (new BlogPublishVetGate($this->vetting()))->ensure($b['item_id'], $b['version_id']);

        return $b;
    }

    public function testPublishNowFromApprovedLeavesTheItemPublishedNotApproved(): void
    {
        $b  = $this->vettedHumanApproved('advance-tax-now');
        $id = (new PublicationDeploymentService())->enqueuePublication($b['item_id'], $b['version_id'], 'aicountly_com', 'publish');
        (new PublicationDeploymentService())->processDeployment($id);

        $this->assertSame('published', Database::connect()->table('reach_publication_deployments')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame(BlogStateMachine::PUBLISHED, $this->item($b['item_id'])['workflow_status'], 'no longer listed as "Ready to Publish"');
        $this->assertSame(0, Database::connect()->table('reach_work_blocks')->where('content_item_id', $b['item_id'])
            ->whereIn('block_type', ['PUBLISH_BLOG'])->countAllResults(), 'the walk must not queue a second publish');
    }

    public function testADueScheduleIsPublishedAndAnOldParkedOneIsReleased(): void
    {
        $b   = $this->vettedHumanApproved('advance-tax-scheduled');
        $pub = new PublicationDeploymentService();
        $id  = $pub->enqueuePublication($b['item_id'], $b['version_id'], 'aicountly_com', 'schedule', date('Y-m-d H:i:s', time() - 60));
        $pub->processDeployment($id);

        $db = Database::connect();
        $this->assertSame('published', $db->table('reach_publication_deployments')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertContains('publish', array_column(PublicSitePublisherFactory::mock()->getCalls(), 'method'));
        $this->assertNotContains('schedule', array_column(PublicSitePublisherFactory::mock()->getCalls(), 'method'));

        // A deployment the old code parked as 'scheduled' on the receiver.
        $db->table('reach_publication_deployments')->where('id', $id)->update(['status' => 'scheduled']);
        $this->assertSame(1, $pub->releaseDueSchedules());
        $this->assertSame('queued', $db->table('reach_publication_deployments')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame(0, $pub->releaseDueSchedules(), 'released once');
    }

    public function testADeploymentTheWorkerNeverSentIsRetriedAfterTwoHours(): void
    {
        $this->gemini = [$this->verdict('approve')];
        $ingest = (new AutomationDraftIngestService())->ingestBlogDraft($this->payload());
        $row    = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status']);

        $db = Database::connect();
        $db->table('reach_publication_deployments')->where('id', $row['deployment_id'])
            ->update(['updated_at' => date('Y-m-d H:i:s', time() - 3 * 3600)]);
        $row = $this->pipeline()->process((int) $ingest['submission_id']);

        // Failed → retried at once with a fresh deployment.
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        $this->assertSame(2, (int) $row['publish_attempts']);
        $this->assertSame(2, $db->table('reach_publication_deployments')->where('content_item_id', $ingest['content_item_id'])->countAllResults());
        $this->assertSame(1, $db->table('reach_publication_deployments')->where('error_category', 'not_sent')->countAllResults());
    }
}
