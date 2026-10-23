<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Libraries\Ai\AiProviderError;
use App\Libraries\Ai\AiProviderHealthResult;
use App\Libraries\Ai\Images\ImageGenerationInput;
use App\Libraries\Ai\Images\ImageGenerationResult;
use App\Libraries\Ai\Images\ImageProviderInterface;
use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineCoverService;
use App\Libraries\Automation\Routine\RoutineIntakeRefused;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Blog\AutomationDraftIngestService;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * The Claude-routine blog pipeline end to end, against a real database, the
 * mock public-site publisher and fakes only at the Gemini and image-API HTTP
 * boundaries:
 *
 *   ingest → Gemini vets (revise is re-vetted once) → system approval bound to
 *   the FINAL text's checksum → author/SEO profiles → fresh DALL·E cover →
 *   deployment → verified live.
 *
 * Plus: reject never publishes; a vetting outage leaves the draft pending and
 * a retry finishes it; the daily quota refuses with 429; content Gemini itself
 * marks high risk is held for a person; no cover keeps it off the site until a
 * waiver.
 *
 * @internal
 */
final class RoutineBlogPipelineTest extends DatabaseTestCase
{
    /** @var list<array{status:int, body:string}> */
    private array $gemini = [];
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
        $this->mediaDir = sys_get_temp_dir() . '/reach-routine-media-' . bin2hex(random_bytes(4));
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

    // ── fixtures ────────────────────────────────────────────────────────────

    private function verdict(string $decision, array $final = [], array $reasons = [], int $compliance = 9): array
    {
        $text = json_encode([
            'decision'   => $decision,
            'reasons'    => $reasons,
            'scores'     => ['accuracy' => 9, 'clarity' => 8, 'compliance' => $compliance, 'tone' => 9],
            'final_json' => json_encode($final),
        ]);

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    private function vetting(): ContentVettingService
    {
        return new ContentVettingService(function (string $url, array $body): array {
            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        });
    }

    private function pipeline(?ImageProviderInterface $images = null): RoutineContentPipeline
    {
        return new RoutineContentPipeline($this->vetting(), new RoutineCoverService($images ?? $this->images()));
    }

    private function images(bool $fail = false): ImageProviderInterface
    {
        return new class ($fail) implements ImageProviderInterface {
            public int $calls = 0;
            public ?string $prompt = null;

            public function __construct(private readonly bool $fail)
            {
            }

            public function getProviderKey(): string { return 'openai_image'; }
            public function isConfigured(): bool { return true; }
            public function healthCheck(): AiProviderHealthResult { throw new \LogicException('unused'); }
            public function classifyError(\Throwable $error): AiProviderError { throw new \LogicException('unused'); }

            public function generateImage(ImageGenerationInput $input): ImageGenerationResult
            {
                $this->calls++;
                $this->prompt = $input->prompt;
                if ($this->fail) {
                    throw new \RuntimeException('HTTP 500 from images API');
                }
                // A real cover size: CoverGenerator rejects tiny images.
                $img = imagecreatetruecolor(1536, 1024);
                imagefilledrectangle($img, 0, 0, 1535, 1023, imagecolorallocate($img, 20, 140, 90));
                ob_start();
                imagepng($img);
                $png = (string) ob_get_clean();

                return new ImageGenerationResult([['b64' => base64_encode($png), 'url' => null, 'mime' => 'image/png']], 'openai_image', 'gpt-image-2.5-flare', null, 5, 1);
            }
        };
    }

    private function body(): string
    {
        $topics = ['input tax credit', 'e-invoicing', 'GSTR-1', 'GSTR-3B', 'reconciliation', 'vendor ledgers', 'bank statements', 'cash flow'];
        $html = '<h2>Why monthly GST discipline matters</h2>';
        foreach ($topics as $i => $t) {
            $html .= "<p>Small businesses that review {$t} every month spend less time fixing errors later. "
                . "Section {$i} of this guide explains a simple routine for {$t}: collect the documents, check them against "
                . "your books, note every difference, and resolve the differences before the filing date arrives. "
                . "Accountants recommend keeping a short checklist for {$t} so that nothing is missed when the team is busy.</p>";
        }

        return $html;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title'            => 'How small businesses can stay on top of GST every month',
            'summary'          => 'A practical monthly GST routine for Indian small businesses.',
            'body_html'        => $this->body(),
            'seo'              => ['slug' => 'monthly-gst-routine', 'meta_title' => 'A monthly GST routine for small businesses', 'meta_description' => 'A simple monthly routine for GST returns, input tax credit and reconciliation that keeps Indian small businesses ready for every filing date.'],
            'category'         => 'gst',
            'tags'             => ['gst', 'compliance'],
            'content_base_key' => 'blog-20261017-monthly-gst-routine',
            'product_facts'    => ['Smart Books records sales and purchase invoices.'],
            'provenance'       => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session_test'],
        ];
    }

    private function ingest(array $overrides = []): array
    {
        return (new AutomationDraftIngestService())->ingestBlogDraft($this->payload($overrides));
    }

    /** Run the publication job the worker would run. */
    private function deliver(int $submissionId): void
    {
        $row = (new RoutineSubmissions())->find($submissionId);
        $this->assertNotEmpty($row['deployment_id'], 'a deployment must be queued: ' . json_encode($row));
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
    }

    private function item(int $id): array
    {
        return Database::connect()->table('reach_content_items')->where('id', $id)->get()->getRowArray();
    }

    // ── tests ───────────────────────────────────────────────────────────────

    public function testApprovedDraftIsApprovedUnderTheSystemAccountGetsACoverAndGoesLive(): void
    {
        // Automation on: any successor block the state machine would create
        // (PUBLISH_BLOG after publish_queued) is visible to the assertion below.
        $_ENV['BLOG_AUTOMATION_ENABLED'] = 'true';
        $this->gemini = [$this->verdict('approve')];
        $images       = $this->images();
        $ingest       = $this->ingest();
        $this->assertSame('ingested', $ingest['status']);

        $row = $this->pipeline($images)->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        $this->assertSame('approved', $row['decision']);
        $this->assertSame('generated', $row['cover_status']);
        $this->assertSame(1, $images->calls, 'a fresh DALL·E cover is generated for every routine blog');
        $this->assertStringContainsString('Strictly no text', (string) $images->prompt);
        $this->assertNotEmpty($row['vetting_run_id']);

        $db       = Database::connect();
        $itemId   = $ingest['content_item_id'];
        $item     = $this->item($itemId);
        $approval = $db->table('reach_blog_content_approvals')->where('content_item_id', $itemId)->where('revoked_at IS NULL', null, false)->get()->getRowArray();
        $this->assertSame(SystemApprover::actorId(), (int) $approval['approved_by']);
        $this->assertSame((int) $row['vetting_run_id'], (int) $approval['vetting_run_id']);
        $this->assertSame((int) $item['current_version_id'], (int) $approval['content_version_id']);
        $this->assertTrue((new BlogContentApprovalService())->verifyForPublication($itemId, (int) $item['current_version_id']));

        $profile = $db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray();
        $this->assertSame('AICOUNTLY Editorial Team', $profile['author_reference']);
        $this->assertStringContainsString('/api/v1/public/media/', (string) $profile['featured_image_reference']);
        $this->assertNotSame('', trim((string) $profile['featured_image_alt']));

        // No CROSS_REVIEW / HUMAN_REVIEW_GATE / PUBLISH_BLOG blocks: the
        // vetting run replaced them and the deployment is already queued.
        $this->assertSame(0, $db->table('reach_work_blocks')->where('content_item_id', $itemId)
            ->whereIn('block_type', ['CROSS_REVIEW', 'HUMAN_REVIEW_GATE', 'PUBLISH_BLOG'])->countAllResults());

        $this->deliver($ingest['submission_id']);
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));
        $this->assertSame(BlogStateMachine::LIVE, $this->item($itemId)['workflow_status']);
        $this->assertNotEmpty($row['verified_at']);

        $calls = array_column(PublicSitePublisherFactory::mock()->getCalls(), 'method');
        $this->assertContains('publish', $calls);
        $this->assertContains('getVerification', $calls);

        // aicountly.com receives the canonical category and (none for a topic
        // article) product_slug.
        $sent = null;
        foreach (PublicSitePublisherFactory::mock()->getCalls() as $call) {
            if (in_array($call['method'], ['createDraft', 'updateDraft'], true)) {
                $sent = $call['args']['payload'] ?? null;
            }
        }
        $this->assertIsArray($sent);
        $this->assertSame('gst', $sent['category']);
        $this->assertArrayHasKey('product_slug', $sent);
        $this->assertNull($sent['product_slug']);
    }

    public function testCategoryAndProductAreCanonicalisedAndStoredForTopicMix(): void
    {
        $ingest = $this->ingest(['category' => 'Billing & Invoicing', 'product_slug' => 'billing', 'content_base_key' => 'blog-20261023-billing-reminders',
            'title' => 'Payment reminders that get invoices paid on time', 'seo' => ['slug' => 'payment-reminders-invoices'] + $this->payload()['seo']]);
        $db      = Database::connect();
        $profile = $db->table('reach_blog_publication_profiles')->where('content_item_id', $ingest['content_item_id'])->get()->getRowArray();
        $this->assertSame('billing-invoicing', $profile['category']);
        $this->assertSame('billing', $profile['product_slug']);
        $sub = (new RoutineSubmissions())->find($ingest['submission_id']);
        $this->assertSame('billing-invoicing', $sub['submission_json']['category']);
        $this->assertSame('billing', $sub['submission_json']['product_slug']);

        $old = $this->ingest(['category' => 'Income Tax', 'product_slug' => 'smart_books', 'content_base_key' => 'blog-20261023-advance-tax',
            'title' => 'Advance tax for small business owners, step by step', 'seo' => ['slug' => 'advance-tax-small-business'] + $this->payload()['seo']]);
        $profile = $db->table('reach_blog_publication_profiles')->where('content_item_id', $old['content_item_id'])->get()->getRowArray();
        $this->assertSame('income-tax', $profile['category'], 'an old label is filed under its canonical slug');
        $this->assertSame('books', $profile['product_slug'], 'smart_books is the site slug books');
    }

    public function testAnUnknownCategoryOrProductIsRefusedBeforeAnythingIsStored(): void
    {
        $db     = Database::connect();
        $before = $db->table('reach_content_items')->countAllResults();
        foreach ([['category' => 'Marketing'], ['category' => null], ['category' => 'gst', 'product_slug' => 'tally'],
            ['category' => 'payroll-hr', 'product_slug' => 'hrms']] as $bad) {
            try {
                $this->ingest(array_filter($bad, static fn ($v) => $v !== null) + ($bad['category'] === null ? ['category' => ''] : []));
                $this->fail('expected a 422 for ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/Use one of|is required|Beta app/', $e->getMessage());
            }
        }
        $this->assertSame($before, $db->table('reach_content_items')->countAllResults());
    }

    public function testARevisionIsReVettedOnceAndTheApprovalCoversTheRevisedText(): void
    {
        $revised = $this->payload()['body_html'] . '<p>Always confirm the current due date on the GST portal before filing.</p>';
        $draft   = ['title' => 'How small businesses can stay on top of GST every month', 'body_html' => $revised, 'meta_title' => 'Monthly GST routine for small businesses'];
        $this->gemini = [$this->verdict('revise', $draft, ['Added a reminder to confirm due dates']), $this->verdict('approve')];

        $ingest = $this->ingest();
        $row    = $this->pipeline()->process($ingest['submission_id']);

        $this->assertSame('revised_approved', $row['decision']);
        $this->assertCount(2, $row['vetting_run_ids_json'], 'exactly one re-vet');
        $item    = $this->item($ingest['content_item_id']);
        $version = Database::connect()->table('reach_content_versions')->where('id', $item['current_version_id'])->get()->getRowArray();
        $this->assertStringContainsString('confirm the current due date', $version['body_html']);
        $this->assertNotSame($ingest['content_version_id'], (int) $item['current_version_id'], 'the corrected text is a new version');
        $this->assertSame(
            (new BlogContentApprovalService())->checksumForVersion((int) $item['current_version_id']),
            $row['final_checksum'],
            'the approval checksum is that of the FINAL text',
        );
        $seo = Database::connect()->table('reach_content_seo_profiles')->where('content_item_id', $ingest['content_item_id'])->get()->getRowArray();
        $this->assertSame('Monthly GST routine for small businesses', $seo['meta_title']);
    }

    public function testRejectedAndUnresolvedRevisionsAreNeverPublished(): void
    {
        $this->gemini = [$this->verdict('reject', [], ['States an outdated GST rate as current'])];
        $rejected     = $this->ingest();
        $row          = $this->pipeline()->process($rejected['submission_id']);
        $this->assertSame(RoutineSubmissions::REJECTED, $row['status']);
        $this->assertSame(['States an outdated GST rate as current'], $row['reasons_json']);
        $this->assertSame(BlogStateMachine::REJECTED, $this->item($rejected['content_item_id'])['workflow_status']);

        $this->gemini = [$this->verdict('revise', ['title' => 'How small businesses can stay on top of GST each month']), $this->verdict('revise', ['title' => 'Another title change for GST'])];
        $unresolved   = $this->ingest(['title' => 'Another monthly GST checklist for growing businesses', 'content_base_key' => 'blog-20261017-gst-checklist', 'seo' => ['slug' => 'gst-checklist']]);
        $row          = $this->pipeline()->process($unresolved['submission_id']);
        $this->assertSame(RoutineSubmissions::REJECTED, $row['status'], json_encode($row));
        $this->assertSame('revise_unresolved', $row['decision']);

        $this->assertSame(0, Database::connect()->table('reach_publication_deployments')->countAllResults());
        $this->assertSame(0, Database::connect()->table('reach_blog_content_approvals')->countAllResults());
    }

    public function testVettingOutageLeavesTheDraftPendingUntilARetrySucceeds(): void
    {
        $this->gemini = []; // every call → HTTP 503
        $ingest = $this->ingest();
        $row    = $this->pipeline()->process($ingest['submission_id']);

        $this->assertSame(RoutineSubmissions::PENDING_VETTING, $row['status']);
        $this->assertSame(1, (int) $row['vetting_attempts']);
        $this->assertNotEmpty($row['next_attempt_at']);
        $this->assertStringContainsString('Vetting unavailable', (string) $row['last_error']);
        $this->assertSame('pending', $this->item($ingest['content_item_id'])['approval_status']);
        $this->assertSame([], (new RoutineSubmissions())->dueIds(), 'backoff: not due again yet');

        $this->gemini = [$this->verdict('approve')];
        $row = $this->pipeline()->retry($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
    }

    public function testTheDailyQuotaRefusesWith429AndRejectionsDoNotCount(): void
    {
        ReachConfig::set('automation.blog.daily_limit', 1);
        $first = $this->ingest();
        $this->assertSame('ingested', $first['status']);

        // Re-sending the same key is idempotent, not a quota hit.
        $this->assertSame('already_ingested', $this->ingest()['status']);

        try {
            $this->ingest(['title' => 'A second article about TDS deposit dates for SMEs', 'content_base_key' => 'blog-20261017-tds', 'seo' => ['slug' => 'tds-dates']]);
            $this->fail('expected the quota to refuse');
        } catch (RoutineIntakeRefused $e) {
            $this->assertSame(429, $e->httpStatus);
        }

        Database::connect()->table('reach_routine_submissions')->where('id', $first['submission_id'])->update(['status' => 'rejected']);
        $this->assertSame('ingested', $this->ingest(['title' => 'A second article about TDS deposit dates for SMEs', 'content_base_key' => 'blog-20261017-tds', 'seo' => ['slug' => 'tds-dates']])['status']);
    }

    public function testADuplicateTopicUnderANewKeyIsRefused(): void
    {
        $this->ingest();
        try {
            $this->ingest(['title' => 'How small businesses can stay on top of GST, every month!', 'content_base_key' => 'blog-other-key']);
            $this->fail('expected a duplicate refusal');
        } catch (RoutineIntakeRefused $e) {
            $this->assertSame(409, $e->httpStatus);
        }
    }

    public function testContentGeminiMarksHighRiskIsHeldForAPerson(): void
    {
        $this->gemini = [$this->verdict('approve', [], ['HIGH_RISK: gives a specific tax position for one taxpayer'], 9)];
        $ingest = $this->ingest();
        $row    = $this->pipeline()->process($ingest['submission_id']);

        $this->assertSame(RoutineSubmissions::HELD_HIGH_RISK, $row['status']);
        $this->assertSame('vetting_flag', $row['risk_flag']);
        $item = $this->item($ingest['content_item_id']);
        $this->assertSame(BlogStateMachine::INTERNAL_REVIEW, $item['workflow_status']);
        $this->assertSame('high', $item['risk_level']);
        $this->assertSame(0, Database::connect()->table('reach_blog_content_approvals')->countAllResults());

        $this->gemini = [$this->verdict('approve', [], [], 3)];
        $low = $this->ingest(['title' => 'Understanding notices under the GST law for small firms', 'content_base_key' => 'blog-20261017-notices', 'seo' => ['slug' => 'gst-notices']]);
        $this->assertSame('low_compliance_score', $this->pipeline()->process($low['submission_id'])['risk_flag']);
    }

    public function testNoCoverKeepsItOffTheSiteUntilAWaiverAndRetriesHourly(): void
    {
        $this->gemini = [$this->verdict('approve')];
        $ingest = $this->ingest();
        $row    = $this->pipeline($this->images(true))->process($ingest['submission_id']);

        $this->assertSame(RoutineSubmissions::AWAITING_COVER, $row['status'], json_encode($row));
        $this->assertSame('failed', $row['cover_status']);
        $this->assertGreaterThan(time() + 3000, strtotime((string) $row['next_cover_attempt_at']));
        $this->assertSame(0, Database::connect()->table('reach_publication_deployments')->countAllResults());

        // A superadmin waives the cover for this article: the next pass publishes.
        Database::connect()->table('reach_content_validations')
            ->where('content_item_id', $ingest['content_item_id'])->where('validation_type', 'cover_image')
            ->update(['waived_at' => date('Y-m-d H:i:s')]);
        $row = $this->pipeline($this->images(true))->retry($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        $this->assertSame('none_allowed', $row['cover_status']);
    }

    public function testAutoPublishOffKeepsTheApprovedItemAndAnEnvFalseIsAKillSwitch(): void
    {
        $_ENV['BLOG_AUTO_PUBLISH_ENABLED'] = 'false';
        $this->gemini = [$this->verdict('approve')];
        $ingest = $this->ingest();
        $row    = $this->pipeline()->process($ingest['submission_id']);

        $this->assertSame(RoutineSubmissions::APPROVED, $row['status']);
        $this->assertStringContainsString('BLOG_AUTO_PUBLISH_ENABLED=false', (string) $row['last_error']);
        $this->assertSame(0, Database::connect()->table('reach_publication_deployments')->countAllResults());

        unset($_ENV['BLOG_AUTO_PUBLISH_ENABLED']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $this->pipeline()->retry($ingest['submission_id'])['status']);
    }
}
