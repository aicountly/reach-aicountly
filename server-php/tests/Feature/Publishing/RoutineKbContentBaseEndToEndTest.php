<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Content\Base\ContentBaseEntries;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * A knowledge-base topic goes all the way with no person involved:
 * GET content-base/next → POST kb-drafts (routine) → Gemini vets and approves →
 * SystemApprover approves → published to the aicountly.com KB receiver
 * (content_type knowledge_base, /help) → verified live → the topic reads
 * "published" on the Content Base page.
 *
 * Regression: the KB showed "0 produced · 0 queued · 17 pending sync" since
 * August; nothing proved a seed could be produced at all.
 *
 * @internal
 */
final class RoutineKbContentBaseEndToEndTest extends ApiTestCase
{
    private const TOKEN = 'routine-test-token-0123456789abcdef0123';
    private array $envBackup = [];
    /** @var list<array{status:int, body:string}> */
    private array $gemini = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['REACH_AUTOMATION_TOKEN' => self::TOKEN, 'CI_ENVIRONMENT' => 'testing', 'REACH_PUB_MOCK' => 'true',
            'JWT_SECRET' => $_ENV['JWT_SECRET'] ?? 'test-jwt-secret', 'KB_AUTO_APPROVE' => 'false'] as $k => $v) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        $this->envBackup['AI_GEMINI_DEFAULT_MODEL'] = $_ENV['AI_GEMINI_DEFAULT_MODEL'] ?? null;
        foreach (['AI_GEMINI_API_KEY', 'KB_AUTO_PUBLISH_ENABLED'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
            putenv($k);
        }
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

    private function routine(): array
    {
        return ['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'];
    }

    private function data($result): array
    {
        return json_decode($result->getJSON() ?: '{}', true)['data'] ?? [];
    }

    private function pipeline(): RoutineContentPipeline
    {
        return new RoutineContentPipeline(new ContentVettingService(function (): array {
            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{}'];
        }));
    }

    private function approve(): array
    {
        $text = json_encode(['decision' => 'approve', 'reasons' => [], 'scores' => ['accuracy' => 9, 'clarity' => 9, 'compliance' => 9, 'tone' => 9], 'final_json' => '{}']);

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    public function testAShippedKbSeedIsProducedAndPublishedEndToEnd(): void
    {
        $next = $this->data($this->withHeaders($this->routine())->call('GET', 'v1/automation/content-base/next', ['type' => 'kb']));
        $topic = $next['items'][0];
        $this->assertSame('books', $topic['product_slug']);
        $this->assertSame('kb-books-getting-started', $topic['content_base_key'], 'the first shipped seed for the first launch app');

        $plan = $this->data($this->withHeaders($this->routine())->call('GET', 'v1/automation/kb-plan'));
        $books = array_values(array_filter($plan['products'], static fn ($p) => $p['product_slug'] === 'books'))[0];
        $this->assertSame($topic['content_base_key'], $books['pending_topics'][0]['content_base_key'], 'kb-plan offers the same topic and key');

        $body = '';
        foreach (['Open Smart Books from the app launcher.', 'Choose Company in Settings and fill in your business details.', 'Add your GSTIN and the state you are registered in.',
            'Create your first customer from Customers.', 'Create your first invoice from Sales.'] as $i => $line) {
            $body .= '<h2>Step ' . ($i + 1) . "</h2><p>{$line} This step explains what you see on screen, what each field means in plain words "
                . 'and what to check before moving on, so that your books start from the right details and nothing has to be corrected later.</p>';
        }
        $res = $this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/kb-drafts', [
            'content_base_key' => $topic['content_base_key'],
            'product_slug'     => 'books',
            'article_type'     => 'how_to',
            'title'            => 'How to get started with Smart Books',
            'summary'          => 'Set up your company, add a customer and send your first invoice in Smart Books.',
            'body_html'        => $body,
            'steps'            => [['title' => 'Open Settings', 'text' => 'Choose Company in Settings.'], ['title' => 'Add a customer', 'text' => 'Open Customers and choose New.']],
            'prerequisites'    => ['An AICOUNTLY account signed in through my.aicountly.com'],
            'seo'              => ['slug' => 'smart-books-getting-started', 'meta_title' => 'Get started with Smart Books', 'meta_description' => 'Set up your company, add your first customer and send your first GST invoice in AICOUNTLY Smart Books, step by step.'],
            'product_facts'    => ['Company settings live under Settings → Company (src/pages/Settings/Company.jsx)'],
            'provenance'       => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session_e2e'],
        ]);
        $res->assertStatus(201);
        $ingest = $this->data($res);
        $this->assertSame('pending_vetting', $ingest['pipeline']['status'], 'no Gemini key on the request path: stored, not published');

        $report = $this->data($this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/content-base/produced',
            ['type' => 'kb', 'key' => $topic['key'], 'outcome' => 'submitted', 'routine_run_id' => 'session_e2e']));
        $this->assertSame('queued', $report['status']);

        // Gemini is configured now; the sweep vets (approve) and the system account approves.
        $_ENV['AI_GEMINI_API_KEY']       = 'test-gemini-key';
        $_ENV['AI_GEMINI_DEFAULT_MODEL'] = 'gemini-test-flash';
        ReachConfig::flush();
        $this->gemini = [$this->approve()];
        $row = $this->pipeline()->process((int) $ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        $db   = Database::connect();
        $item = $db->table('reach_content_items')->where('id', $ingest['content_item_id'])->get()->getRowArray();
        $this->assertSame(SystemApprover::actorId(), (int) $item['approved_by']);
        (new ContentBaseEntries())->reconcile();
        $this->assertSame('produced', (new ContentBaseEntries())->find('knowledge_base', 'kb-books-getting-started')['status']);

        // The publisher delivers it to the aicountly.com KB receiver, then it is verified live.
        (new PublicationDeploymentService())->processDeployment((int) $row['deployment_id']);
        $row = $this->pipeline()->process((int) $ingest['submission_id']);
        $this->assertSame(RoutineSubmissions::LIVE, $row['status'], json_encode($row));

        $calls    = PublicSitePublisherFactory::mock()->getCalls();
        $envelope = $calls[0]['args'] ?? [];
        $this->assertSame('knowledge_base', $envelope['content_type'] ?? null);
        $this->assertSame('smart-books-getting-started', $envelope['payload']['slug'] ?? null);
        $this->assertSame('Smart Books', $envelope['payload']['product'] ?? null);
        $this->assertNotEmpty($envelope['payload']['steps'] ?? []);

        (new ContentBaseEntries())->reconcile();
        $entry = (new ContentBaseEntries())->find('knowledge_base', 'kb-books-getting-started');
        $this->assertSame('published', $entry['status']);
        $this->assertSame('session_e2e', $entry['produced_by_run']);

        $after = $this->data($this->withHeaders($this->routine())->call('GET', 'v1/automation/content-base/next', ['type' => 'kb']));
        $this->assertNotContains('kb-books-getting-started', array_column($after['items'], 'key'));
        $this->assertSame(1, $after['counts']['published']);
    }
}
