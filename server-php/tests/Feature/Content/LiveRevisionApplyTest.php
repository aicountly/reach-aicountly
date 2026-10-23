<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

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
use App\Libraries\Blog\BlogPublishVetGate;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Content\Quality\ContentRevetService;
use App\Libraries\Content\Quality\LiveRevisionApplier;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseDraftIngestService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * reach:content-revet on a LIVE post whose verdict is a revise Gemini then
 * approved (`revised_approved`): the corrected text becomes a new current
 * version and is republished at the same slug and public record — fakes only
 * at the Gemini / image HTTP boundaries and the mock public-site publisher,
 * as in ContentQualityCleanupTest.
 *
 * @internal
 */
final class LiveRevisionApplyTest extends DatabaseTestCase
{
    private const SLUG = 'tds-compliance-basics-for-growing-companies';

    /** @var list<array{status:int, body:string}> */
    private array $gemini = [];
    private int $geminiCalls = 0;
    private string $mediaDir = '';
    private array $envBackup = [];

    private const ENV = [
        'CI_ENVIRONMENT' => 'testing', 'REACH_PUB_MOCK' => 'true',
        'AI_GEMINI_API_KEY' => 'test-gemini-key', 'AI_GEMINI_DEFAULT_MODEL' => 'gemini-test-flash',
        'MEDIA_SIGNING_KEY' => 'test-media-signing-key-0123456789abcdef', 'BLOG_REQUIRE_COVER_IMAGE' => 'true',
        'KB_AUTO_APPROVE' => 'false',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaDir = sys_get_temp_dir() . '/reach-revision-media-' . bin2hex(random_bytes(4));
        foreach (self::ENV + ['MEDIA_STORAGE_PATH' => $this->mediaDir] as $k => $v) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        foreach (['BLOG_AUTO_PUBLISH_ENABLED', 'BLOG_PUBLIC_PUBLISHER_ENABLED', 'BLOG_IMAGE_GENERATION_ENABLED', 'BLOG_AUTOMATION_ENABLED', 'KB_AUTO_PUBLISH_ENABLED'] as $k) {
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
            public function getProviderKey(): string { return 'openai_image'; }
            public function isConfigured(): bool { return true; }
            public function healthCheck(): AiProviderHealthResult { throw new \LogicException('unused'); }
            public function classifyError(\Throwable $error): AiProviderError { throw new \LogicException('unused'); }
            public function generateImage(ImageGenerationInput $input): ImageGenerationResult
            {
                $img = imagecreatetruecolor(1536, 1024);
                imagefilledrectangle($img, 0, 0, 1535, 1023, imagecolorallocate($img, 20, 140, 90));
                ob_start();
                imagepng($img);
                $png = (string) ob_get_clean();

                return new ImageGenerationResult([['b64' => base64_encode($png), 'url' => null, 'mime' => 'image/png']], 'openai_image', 'gpt-image', null, 5, 1);
            }
        };
    }

    private function pipeline(): RoutineContentPipeline
    {
        return new RoutineContentPipeline($this->vetting(), new RoutineCoverService($this->images()));
    }

    private function body(string $extra = ''): string
    {
        $html = '<h2>What TDS compliance involves</h2>';
        foreach (['deduction', 'deposit', 'returns', 'certificates', 'late fees', 'lower deduction', 'reconciliation', 'records'] as $i => $t) {
            $html .= "<p>TDS {$t} is part of everyday compliance for growing companies. Step {$i}: check {$t} every month against "
                . 'your books, note every difference and fix it before the due date. A short TDS checklist keeps the finance team on '
                . 'track when the month gets busy and stops interest and fees from building up.</p>';
        }

        return $html . $extra;
    }

    private function payload(): array
    {
        return [
            'title'            => 'TDS Compliance Basics for Growing Companies',
            'summary'          => 'What TDS deduction, deposit and returns involve for a growing company.',
            'body_html'        => $this->body(),
            'seo'              => ['slug' => self::SLUG, 'meta_title' => 'TDS compliance basics for growing companies',
                                   'meta_description' => 'TDS deduction, deposit, returns and certificates explained for growing Indian companies, with a monthly checklist to stay compliant.'],
            'category'         => 'tds-tcs',
            'content_base_key' => 'blog-20261023-tds-basics',
            'provenance'       => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session_test'],
        ];
    }

    private function item(int $id): array
    {
        return Database::connect()->table('reach_content_items')->where('id', $id)->get()->getRowArray();
    }

    /** A routine article taken all the way to live through the real pipeline. */
    private function publishLiveArticle(): int
    {
        $this->gemini = [$this->verdict('approve')];
        $ingest = (new AutomationDraftIngestService())->ingestBlogDraft($this->payload());
        $row    = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));
        PublicSitePublisherFactory::mock()->reset();

        return (int) $ingest['content_item_id'];
    }

    /** Gemini corrects the live text (first run), then approves its correction (second run). */
    private function reviseThenApprove(array $final): void
    {
        $this->gemini = [
            $this->verdict('revise', ['Section 16(4): the ITC time limit is 30 November of the following year, not 30 September.'], $final),
            $this->verdict('approve', ['The corrected text is accurate.']),
        ];
    }

    private function correctedBody(): string
    {
        return $this->body('<p>Corrected: the ITC time limit under Section 16(4) is 30 November of the following financial year.</p>');
    }

    // ── tests ───────────────────────────────────────────────────────────────

    public function testALiveReviseWithCorrectedTextBecomesANewVersionAndIsRepublishedAtTheSameUrl(): void
    {
        $itemId = $this->publishLiveArticle();
        $db     = Database::connect();
        $before = $this->item($itemId);
        $live   = $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->where('status', 'verified')->get()->getRowArray();
        $profileBefore = $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray();

        $this->reviseThenApprove([
            'title'            => 'TDS Compliance Basics for Growing Companies in 2026',
            'body_html'        => $this->correctedBody(),
            'meta_description' => 'TDS deduction, deposit, returns and certificates for growing Indian companies, with the corrected time limits.',
            'slug'             => 'gemini-wants-a-new-slug',
        ]);
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live', 'types' => ['blog'], 'ids' => [$itemId]]);
        $row    = $report['items'][0];

        $this->assertSame('revise', $row['decision']);
        $this->assertSame('revised_approved', $row['vetting']);
        $this->assertSame('revision_applied+republish_queued', $row['action'], json_encode($row));
        $this->assertSame(1, $report['totals']['action:revision_applied+republish_queued']);

        // A new current version carrying the correction; the old one stays in the history.
        $after = $this->item($itemId);
        $this->assertNotSame((int) $before['current_version_id'], (int) $after['current_version_id']);
        $version = $db->table('reach_content_versions')->where('id', $after['current_version_id'])->get()->getRowArray();
        $this->assertStringContainsString('30 November of the following financial year', (string) $version['body_html']);
        $this->assertSame(LiveRevisionApplier::SERVICE, $version['created_by_service']);
        $this->assertStringContainsString('Live correction of v', (string) $version['change_summary']);
        $this->assertSame('TDS Compliance Basics for Growing Companies in 2026', $version['title'], 'a same-topic title is taken');
        $this->assertSame(2, $db->table('reach_content_versions')->where('content_item_id', $itemId)->countAllResults());

        // URL and filing unchanged; still live; meta corrected.
        $this->assertSame(self::SLUG, $after['slug']);
        $seo = $db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertSame(self::SLUG, $seo['slug']);
        $this->assertStringContainsString('corrected time limits', (string) $seo['meta_description']);
        $this->assertSame(BlogStateMachine::LIVE, $after['workflow_status']);
        $this->assertSame('approved', $after['approval_status']);
        $profileAfter = $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertSame($profileBefore['category'], $profileAfter['category']);
        $this->assertSame($profileBefore['featured_image_reference'], $profileAfter['featured_image_reference']);

        // Approved under the system bot with Gemini's approving run, so the gate needs no second call.
        $this->assertTrue((new BlogPublishVetGate($this->vetting()))->status($itemId, (int) $after['current_version_id'])['vetted']);

        // The republish is queued for the new version and updates the SAME public record.
        $deployment = $db->table('reach_publication_deployments')->where('content_item_id', $itemId)
            ->where('content_version_id', $after['current_version_id'])->get()->getRowArray();
        $this->assertSame('queued', $deployment['status']);
        $this->assertSame('publish', $deployment['operation']);
        $calls = $this->geminiCalls;
        (new PublicationDeploymentService())->processDeployment((int) $deployment['id']);
        $this->assertSame($calls, $this->geminiCalls, 'the send does not vet again');
        $sent = $db->table('reach_publication_deployments')->where('id', $deployment['id'])->get()->getRowArray();
        $this->assertSame('published', $sent['status']);
        $this->assertSame((int) $live['public_content_id'], (int) $sent['public_content_id']);
        $methods = array_column(PublicSitePublisherFactory::mock()->getCalls(), 'method');
        $this->assertContains('updateDraft', $methods);
        $this->assertNotContains('createDraft', $methods);
        $this->assertNotContains('unpublish', $methods);
        $this->assertSame(BlogStateMachine::LIVE, $this->item($itemId)['workflow_status']);

        $check = $db->table('reach_content_quality_checks')->where('subject_id', $itemId)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('revision_applied+republish_queued', $check['action']);
        $this->assertStringContainsString('Corrected title, body_html, meta_description', (string) $check['reasons']);
    }

    public function testATitleThatDriftsFromTheTopicIsNotTakenButTheCorrectionIs(): void
    {
        $itemId = $this->publishLiveArticle();
        $this->reviseThenApprove([
            'title'     => 'Ten social media ideas that grow your audience fast',
            'body_html' => $this->correctedBody(),
        ]);
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live', 'ids' => [$itemId]]);

        $this->assertSame('revision_applied+republish_queued', $report['items'][0]['action']);
        $version = Database::connect()->table('reach_content_versions')->where('id', $this->item($itemId)['current_version_id'])->get()->getRowArray();
        $this->assertSame('TDS Compliance Basics for Growing Companies', $version['title']);
        $this->assertSame('TDS Compliance Basics for Growing Companies', $this->item($itemId)['title']);
        $this->assertStringContainsString('30 November', (string) $version['body_html']);
        $this->assertStringContainsString('live title is kept', implode(' ', $report['items'][0]['reasons']));
    }

    public function testALiveReviseWithoutUsableTextStaysFlaggedForTheOwner(): void
    {
        $itemId  = $this->publishLiveArticle();
        $db      = Database::connect();
        $version = (int) $this->item($itemId)['current_version_id'];
        $deployments = $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->countAllResults();

        // Gemini asks for a revision but sends back the text unchanged.
        $this->reviseThenApprove([]);
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live', 'ids' => [$itemId]]);

        $this->assertSame('revise', $report['items'][0]['decision']);
        $this->assertSame('flagged_for_owner', $report['items'][0]['action']);
        $this->assertStringContainsString('no changed text', implode(' ', $report['items'][0]['reasons']));
        $this->assertSame($version, (int) $this->item($itemId)['current_version_id']);
        $this->assertSame($deployments, $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->countAllResults());
        $this->assertSame(BlogStateMachine::LIVE, $this->item($itemId)['workflow_status']);
    }

    public function testADryRunSaysItWouldApplyTheRevisionAndChangesNothing(): void
    {
        $itemId  = $this->publishLiveArticle();
        $db      = Database::connect();
        $version = (int) $this->item($itemId)['current_version_id'];
        $deployments = $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->countAllResults();

        $this->reviseThenApprove(['body_html' => $this->correctedBody()]);
        $report = (new ContentRevetService($this->vetting()))->run(['scope' => 'live', 'ids' => [$itemId], 'dry_run' => true]);

        $this->assertSame('revise', $report['items'][0]['decision']);
        $this->assertSame('none', $report['items'][0]['action']);
        $this->assertSame('apply_revision+republish', $report['items'][0]['would']);
        $this->assertSame($version, (int) $this->item($itemId)['current_version_id']);
        $this->assertSame(1, $db->table('reach_content_versions')->where('content_item_id', $itemId)->countAllResults());
        $this->assertSame($deployments, $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->countAllResults());
        $this->assertSame([], PublicSitePublisherFactory::mock()->getCalls());
    }

    public function testARepublishThatIsRefusedRollsBackAndReadsRevisionFailed(): void
    {
        $itemId  = $this->publishLiveArticle();
        $db      = Database::connect();
        $version = (int) $this->item($itemId)['current_version_id'];
        $refusing = new class () extends PublicationDeploymentService {
            public function enqueuePublication(int $contentItemId, int $contentVersionId, string $connectionKey = 'aicountly_com', string $operation = 'publish', ?string $scheduledAt = null, ?int $createdBy = null): int
            {
                throw new \RuntimeException('Content is not ready for publication: fixture refusal');
            }
        };

        $this->reviseThenApprove(['body_html' => $this->correctedBody()]);
        $report = (new ContentRevetService($this->vetting(), null, null, new LiveRevisionApplier(null, $refusing)))
            ->run(['scope' => 'live', 'ids' => [$itemId]]);

        $this->assertSame('revision_failed', $report['items'][0]['action']);
        $this->assertStringContainsString('fixture refusal', implode(' ', $report['items'][0]['reasons']));
        $this->assertSame($version, (int) $this->item($itemId)['current_version_id'], 'the new version is rolled back');
        $this->assertSame(1, $db->table('reach_content_versions')->where('content_item_id', $itemId)->countAllResults());
        $this->assertSame(BlogStateMachine::LIVE, $this->item($itemId)['workflow_status']);
        $this->assertTrue((new BlogPublishVetGate($this->vetting()))->status($itemId, $version)['vetted'], 'the live version keeps its approval');
    }

    public function testALivePostWithoutACoverGetsOneBeforeTheCorrectionIsRepublished(): void
    {
        $itemId = $this->publishLiveArticle();
        $db     = Database::connect();
        $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->update(['featured_image_reference' => null]);
        $asked  = [];
        $covers = function (int $id) use (&$asked, $db): array {
            $asked[] = $id;
            $db->table('reach_blog_publication_profiles')->where('content_item_id', $id)
                ->update(['featured_image_reference' => 'https://cdn.example.test/cover.png', 'featured_image_alt' => 'Cover']);

            return ['ok' => true];
        };

        $this->reviseThenApprove(['body_html' => $this->correctedBody()]);
        $report = (new ContentRevetService($this->vetting(), null, null, new LiveRevisionApplier(null, null, $covers)))
            ->run(['scope' => 'live', 'ids' => [$itemId]]);

        $this->assertSame([$itemId], $asked);
        $this->assertSame('revision_applied+republish_queued', $report['items'][0]['action']);
    }

    public function testALivePostThatCannotGetACoverIsLeftAsItIs(): void
    {
        $itemId  = $this->publishLiveArticle();
        $db      = Database::connect();
        $version = (int) $this->item($itemId)['current_version_id'];
        $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->update(['featured_image_reference' => '']);

        $this->reviseThenApprove(['body_html' => $this->correctedBody()]);
        $report = (new ContentRevetService($this->vetting(), null, null, new LiveRevisionApplier(null, null, static fn (): array => ['ok' => false, 'reason' => 'no_cover: fixture'])))
            ->run(['scope' => 'live', 'ids' => [$itemId]]);

        $this->assertSame('revision_failed', $report['items'][0]['action']);
        $this->assertStringContainsString('no_cover: fixture', implode(' ', $report['items'][0]['reasons']));
        $this->assertSame($version, (int) $this->item($itemId)['current_version_id']);
        $this->assertSame(BlogStateMachine::LIVE, $this->item($itemId)['workflow_status']);
    }

    public function testALiveKbArticleTakesTheCorrectedStepsAndIsRepublishedAtTheSameSlug(): void
    {
        $body = '';
        foreach (['Open Billing from the app launcher.', 'Choose New invoice on the Invoices screen.', 'Pick the customer and add each item with its quantity and rate.',
            'Check the tax lines Billing calculates from the item settings.', 'Save the invoice, then share it from the invoice view.'] as $i => $line) {
            $body .= '<h2>Step ' . ($i + 1) . "</h2><p>{$line} This part of the guide explains what you see on screen, what each field means in plain words, "
                . 'and what to check before you move on, so that the invoice you send to your customer is complete and correct the first time.</p>';
        }
        $ingest = (new KnowledgeBaseDraftIngestService())->ingestKbDraft([
            'title' => 'How to create your first invoice in Billing', 'product_slug' => 'billing', 'article_type' => 'how_to',
            'content_base_key' => 'kb-billing-first-invoice', 'summary' => 'Create, check and share a sales invoice in AICOUNTLY Billing.',
            'body_html' => $body,
            'seo' => ['slug' => 'billing-create-first-invoice', 'meta_title' => 'Create your first invoice in Billing', 'meta_description' => 'Step-by-step: create, check and share your first sales invoice in AICOUNTLY Billing.'],
            'prerequisites' => ['A Billing account with at least one customer'],
            'steps' => [['title' => 'Open Billing', 'text' => 'Open Billing from the app launcher.'], ['text' => 'Choose New invoice on the Invoices screen.'], 'Save the invoice.'],
            'troubleshooting' => ['If the tax line is missing, check the item tax settings.'],
            'provenance' => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->gemini = [$this->verdict('approve')];
        $row = $this->pipeline()->process($ingest['submission_id']);
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));
        $itemId = (int) $ingest['content_item_id'];
        $db     = Database::connect();
        $publicId = (int) $db->table('reach_publication_deployments')->where('content_item_id', $itemId)
            ->where('public_content_id IS NOT NULL', null, false)->get()->getRowArray()['public_content_id'];
        $versionBefore = (int) $this->item($itemId)['current_version_id'];

        $this->gemini = [
            $this->verdict('revise', ['Step 3 should say where the Share button is.'], [
                'steps' => [['title' => 'Open Billing', 'description' => 'Open Billing from the app launcher.'],
                    ['title' => 'New invoice', 'description' => 'Choose New invoice on the Invoices screen.'],
                    ['title' => 'Share', 'description' => 'Save the invoice, then use Share at the top right of the invoice view.']],
            ]),
            $this->verdict('approve'),
        ];
        $report = (new ContentRevetService($this->vetting()))->run(['types' => ['knowledge_base'], 'scope' => 'live']);

        $this->assertSame('revision_applied+republish_queued', $report['items'][0]['action'], json_encode($report['items'][0]));
        $item = $this->item($itemId);
        $this->assertNotSame($versionBefore, (int) $item['current_version_id']);
        $this->assertSame('billing-create-first-invoice', $item['slug']);
        $this->assertSame('published', $item['workflow_status']);
        $steps = json_decode((string) $db->table('reach_kb_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray()['steps_json'], true);
        $this->assertStringContainsString('top right', $steps[2]['description']);

        $deployment = $db->table('reach_publication_deployments')->where('content_item_id', $itemId)->where('content_version_id', $item['current_version_id'])->get()->getRowArray();
        (new PublicationDeploymentService())->processDeployment((int) $deployment['id']);
        $sent = $db->table('reach_publication_deployments')->where('id', $deployment['id'])->get()->getRowArray();
        $this->assertSame('published', $sent['status'], (string) $sent['redacted_error']);
        $this->assertSame($publicId, (int) $sent['public_content_id']);
    }
}
