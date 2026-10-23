<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineIntakeRefused;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseDraftIngestService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Claude-routine knowledge-base articles: Gemini (knowledge_base rubric)
 * vets, the SystemApprover approves, the article is published to /help and
 * verified — with no KB_AUTO_APPROVE involved. Reject, outage and quota
 * behave as for blogs.
 *
 * @internal
 */
final class RoutineKbPipelineTest extends DatabaseTestCase
{
    /** @var list<array{status:int, body:string}> */
    private array $gemini = [];
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['CI_ENVIRONMENT' => 'testing', 'REACH_PUB_MOCK' => 'true', 'AI_GEMINI_API_KEY' => 'test-gemini-key',
            'AI_GEMINI_DEFAULT_MODEL' => 'gemini-test-flash', 'KB_AUTO_APPROVE' => 'false'] as $k => $v) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        $this->envBackup['KB_AUTO_PUBLISH_ENABLED'] = $_ENV['KB_AUTO_PUBLISH_ENABLED'] ?? null;
        unset($_ENV['KB_AUTO_PUBLISH_ENABLED']);
        ReachConfig::flush();
        PublicSitePublisherFactory::resetMock();
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

    private function verdict(string $decision, array $final = [], array $reasons = []): array
    {
        $text = json_encode(['decision' => $decision, 'reasons' => $reasons, 'scores' => ['accuracy' => 9, 'clarity' => 9, 'compliance' => 9, 'tone' => 9], 'final_json' => json_encode($final)]);

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    private function pipeline(): RoutineContentPipeline
    {
        return new RoutineContentPipeline(new ContentVettingService(function (): array {
            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{}'];
        }));
    }

    private function payload(array $overrides = []): array
    {
        $body = '';
        foreach (['Open Billing from the app launcher.', 'Choose New invoice on the Invoices screen.', 'Pick the customer and add each item with its quantity and rate.',
            'Check the tax lines Billing calculates from the item settings.', 'Save the invoice, then share it from the invoice view.'] as $i => $line) {
            $body .= "<h2>Step " . ($i + 1) . "</h2><p>{$line} This part of the guide explains what you see on screen, what each field means in plain words, "
                . 'and what to check before you move on, so that the invoice you send to your customer is complete and correct the first time.</p>';
        }

        return $overrides + [
            'title'            => 'How to create your first invoice in Billing',
            'product_slug'     => 'billing',
            'article_type'     => 'how_to',
            'content_base_key' => 'kb-billing-first-invoice',
            'summary'          => 'Create, check and share a sales invoice in Aicountly Billing.',
            'body_html'        => $body,
            'seo'              => ['slug' => 'billing-create-first-invoice', 'meta_title' => 'Create your first invoice in Billing', 'meta_description' => 'Step-by-step: create, check and share your first sales invoice in Aicountly Billing.'],
            'prerequisites'    => ['A Billing account with at least one customer'],
            'steps'            => [['title' => 'Open Billing', 'text' => 'Open Billing from the app launcher.'], ['text' => 'Choose New invoice on the Invoices screen.'], 'Save the invoice.'],
            'troubleshooting'  => ['If the tax line is missing, check the item tax settings.'],
            'provenance'       => ['generated_by_model' => 'claude-sonnet'],
        ];
    }

    public function testAnApprovedArticleIsApprovedBySystemAndPublishedToHelp(): void
    {
        $ingest = (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload());
        $this->assertSame('ingested', $ingest['status']);

        $db      = Database::connect();
        $profile = $db->table('reach_kb_publication_profiles')->where('content_item_id', $ingest['content_item_id'])->get()->getRowArray();
        $steps   = json_decode($profile['steps_json'], true);
        $this->assertSame([1, 2, 3], array_column($steps, 'step_number'), 'steps are normalised for the readiness gate');
        $this->assertSame('Choose New invoice on the Invoices screen', $steps[1]['title']);

        $this->gemini = [$this->verdict('approve')];
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));

        $item = $db->table('reach_content_items')->where('id', $ingest['content_item_id'])->get()->getRowArray();
        $this->assertSame('approved', $item['approval_status']);
        $this->assertSame(SystemApprover::actorId(), (int) $item['approved_by']);
        $this->assertNotEmpty($row['vetting_run_id']);
        $this->assertSame('knowledge_base', $db->table('reach_ai_vetting_runs')->where('id', $row['vetting_run_id'])->get()->getRowArray()['content_type']);

        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));
        $this->assertSame('published', $db->table('reach_content_items')->where('id', $ingest['content_item_id'])->get()->getRowArray()['workflow_status']);

        $envelope = PublicSitePublisherFactory::mock()->getCalls()[0]['args'] ?? [];
        $this->assertSame('knowledge_base', $envelope['content_type'] ?? null);
        $this->assertSame('Billing', $envelope['payload']['product'] ?? null, 'launch apps get their real name');
    }

    public function testALiveArticleGeminiRejectsIsWithdrawnAndRewrittenAtTheSameSlug(): void
    {
        $ingest = (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload());
        $this->gemini = [$this->verdict('approve')];
        $row = $this->pipeline()->process($ingest['submission_id']);
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process($ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));
        $itemId = (int) $ingest['content_item_id'];

        $this->gemini = [$this->verdict('reject', [], ['OFF_TOPIC: the steps describe a different product.'])];
        $vetting = new ContentVettingService(function (): array {
            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{}'];
        });
        $report = (new \App\Libraries\Content\Quality\ContentRevetService($vetting))->run(['types' => ['knowledge_base'], 'scope' => 'live']);
        $this->assertSame('unpublished+queued_regeneration', $report['items'][0]['action'], json_encode($report));

        $next = (new \App\Libraries\Content\Quality\RegenerationQueue())->claimNext('knowledge_base');
        $this->assertSame('billing', $next['product_slug']);
        $this->assertSame('billing-create-first-invoice', $next['slug']);

        $rewrite = (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload([
            'regeneration_id' => $next['regeneration_id'], 'content_base_key' => 'kb-any-new-key',
            'steps' => [['title' => 'Open Billing', 'text' => 'Open Billing from the app launcher.'], 'Choose New invoice.', 'Save and share the invoice.'],
        ]));
        $this->assertSame($itemId, $rewrite['content_item_id']);
        $this->assertSame('billing-create-first-invoice', $rewrite['slug']);

        $this->gemini = [$this->verdict('approve')];
        $row = $this->pipeline()->process((int) $rewrite['submission_id']);
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process((int) $rewrite['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));
        $db = Database::connect();
        $this->assertSame('published', $db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray()['workflow_status']);
        $this->assertSame('live', $db->table('reach_content_regenerations')->where('id', $next['regeneration_id'])->get()->getRowArray()['status']);
    }

    public function testRejectedOrUnvettedArticlesAreNotPublished(): void
    {
        $this->gemini = [$this->verdict('reject', [], ['Describes a screen that does not exist'])];
        $rejected = (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload());
        $this->assertSame(RoutineSubmissions::REJECTED, $this->pipeline()->process($rejected['submission_id'])['status']);
        $this->assertSame('rejected', Database::connect()->table('reach_content_items')->where('id', $rejected['content_item_id'])->get()->getRowArray()['workflow_status']);

        $this->gemini = [];
        $pending = (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload(['content_base_key' => 'kb-billing-second', 'title' => 'How to share an invoice from Billing', 'seo' => ['slug' => 'billing-share-invoice']]));
        $row = $this->pipeline()->process($pending['submission_id']);
        $this->assertSame(RoutineSubmissions::PENDING_VETTING, $row['status']);
        $this->assertSame('pending', Database::connect()->table('reach_content_items')->where('id', $pending['content_item_id'])->get()->getRowArray()['approval_status']);
        $this->assertSame(0, Database::connect()->table('reach_publication_deployments')->countAllResults());
    }

    public function testTheKbQuotaRefusesWith429(): void
    {
        ReachConfig::set('automation.knowledge_base.daily_limit', 1);
        (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload());

        $this->expectException(RoutineIntakeRefused::class);
        try {
            (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->payload(['content_base_key' => 'kb-billing-third', 'title' => 'How to record a payment in Billing', 'seo' => ['slug' => 'billing-record-payment']]));
        } catch (RoutineIntakeRefused $e) {
            $this->assertSame(429, $e->httpStatus);
            throw $e;
        }
    }

    public function testThePlanPutsLaunchAppsFirstAndReportsTheQuota(): void
    {
        $plan = (new KnowledgeBaseDraftIngestService())->plan();
        $this->assertSame(['books', 'billing', 'inventory', 'notes', 'drive', 'manage'], array_slice(array_column($plan['products'], 'product_slug'), 0, 6));
        $this->assertTrue($plan['products'][0]['is_launch_app']);
        $this->assertSame(10, $plan['quota']['daily_limit']);
    }
}
