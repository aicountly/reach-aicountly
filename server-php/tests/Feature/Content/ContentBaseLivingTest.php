<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Libraries\Content\Base\ContentBaseEntries;
use App\Libraries\Content\Base\ContentBaseSync;
use App\Libraries\Content\Base\RoutineActivity;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The living content base: topics and their progress in the database, fed
 * by git seeds and routine proposals, moved along by Reach's own pipeline.
 *
 * Regression: the base lived only in git (rsynced with --delete on a manual
 * deploy), so nothing a routine learned could reach the server; every tab
 * showed "base updated 05-08-2026", the KB showed "0 produced · 17 pending
 * sync" whatever the routine did, and "produced" meant "some item exists".
 *
 * @internal
 */
final class ContentBaseLivingTest extends ApiTestCase
{
    private const TOKEN = 'routine-test-token-0123456789abcdef0123';
    private array $envBackup = [];
    private ?string $baseDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['REACH_AUTOMATION_TOKEN' => self::TOKEN, 'CI_ENVIRONMENT' => 'testing', 'REACH_PUB_MOCK' => 'true',
            'JWT_SECRET' => $_ENV['JWT_SECRET'] ?? 'test-jwt-secret'] as $k => $v) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        $this->envBackup['AI_GEMINI_API_KEY'] = $_ENV['AI_GEMINI_API_KEY'] ?? null;
        unset($_ENV['AI_GEMINI_API_KEY']);
        putenv('AI_GEMINI_API_KEY');
        $this->envBackup['CONTENT_BASE_PATH'] = $_ENV['CONTENT_BASE_PATH'] ?? null;
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
            }
        }
        if ($this->baseDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->baseDir));
        }
        ReachConfig::flush();
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

    private function next(string $type, int $expect = 200): array
    {
        $res = $this->withHeaders($this->routine())->call('GET', 'v1/automation/content-base/next', ['type' => $type, 'limit' => 50]);
        $res->assertStatus($expect);

        return $this->data($res);
    }

    private function send(string $path, array $body)
    {
        return $this->withHeaders($this->routine())->withBodyFormat('json')->call('POST', 'v1/automation/' . $path, $body);
    }

    /** A private seed directory, so tests do not depend on the shipped files. */
    private function useSeeds(array $blog, array $kbProducts, array $community = []): void
    {
        $this->baseDir = sys_get_temp_dir() . '/cb-test-' . bin2hex(random_bytes(4));
        foreach (['blog', 'knowledge-base', 'community'] as $d) {
            mkdir($this->baseDir . '/' . $d, 0777, true);
        }
        file_put_contents($this->baseDir . '/blog/index.json', json_encode(['version' => 1, 'updated_at' => '2026-08-05', 'entries' => $blog]));
        file_put_contents($this->baseDir . '/blog/base.md', '# Base');
        file_put_contents($this->baseDir . '/knowledge-base/index.json', json_encode(['version' => 1, 'products' => $kbProducts]));
        file_put_contents($this->baseDir . '/community/question-seeds.json', json_encode(['seeds' => $community]));
        $_ENV['CONTENT_BASE_PATH'] = $this->baseDir;
        putenv('CONTENT_BASE_PATH=' . $this->baseDir);
    }

    private function kbBody(): string
    {
        $body = '';
        foreach (['Open Smart Books.', 'Choose Company in Settings.', 'Fill the company details.', 'Save.', 'Create your first invoice.'] as $i => $line) {
            $body .= '<h2>Step ' . ($i + 1) . "</h2><p>{$line} This part explains what you see on screen, what each field means in plain words, "
                . 'and what to check before you move on, so that your books start from correct details and nothing has to be fixed later on.</p>';
        }

        return $body;
    }

    private function kbDraft(string $key, array $overrides = []): array
    {
        return $overrides + [
            'content_base_key' => $key,
            'product_slug'     => 'books',
            'article_type'     => 'how_to',
            'title'            => 'How to set up your company in Smart Books',
            'summary'          => 'Set up your company details in Smart Books.',
            'body_html'        => $this->kbBody(),
            'steps'            => [['title' => 'Open Settings', 'text' => 'Choose Company in Settings.']],
            'seo'              => ['slug' => 'smart-books-company-setup', 'meta_title' => 'Set up your company in Smart Books', 'meta_description' => 'Step by step: set up your company details in Aicountly Smart Books before you record your first invoice.'],
            'provenance'       => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session_test'],
        ];
    }

    // ────────────────────────────────────────────────────────────── next

    public function testNextNeedsTheTokenAndServesShippedSeedsWithTheKeyToSubmitUnder(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])->call('GET', 'v1/automation/content-base/next', ['type' => 'blog'])->assertStatus(401);
        $this->next('nonsense', 422);

        $blog = $this->next('blog');
        $this->assertNotEmpty($blog['items'], 'the repo ships blog seeds; a fresh server imports them on first read');
        foreach ($blog['items'] as $item) {
            $this->assertNotEmpty($item['content_base_key']);
            $this->assertContains($item['kind'], ['regeneration', 'roadmap', 'content_base']);
        }
        $this->assertSame($blog['counts']['planned'], $blog['counts']['total'] - $blog['counts']['retired']);

        $kb = $this->next('kb');
        $this->assertSame('knowledge_base', $kb['type']);
        $this->assertSame('books', $kb['items'][0]['product_slug'], 'launch apps first');

        $row = Database::connect()->table('reach_routine_activity')->where('content_type', 'content_base')->where('endpoint', 'content-base/next')->get()->getRowArray();
        $this->assertNotNull($row, 'the call is recorded as routine activity');
        $this->assertSame('GET', $row['method']);
        $this->assertGreaterThanOrEqual(2, (int) $row['hits']);

        $this->withHeaders($this->routine())->call('GET', 'v1/automation/kb-plan')->assertStatus(200);
        $kbRow = Database::connect()->table('reach_routine_activity')->where('content_type', 'knowledge_base')->get()->getRowArray();
        $this->assertSame('kb-plan', $kbRow['endpoint'], 'the KB writer is credited through its own plan call');
    }

    public function testKbTopicsForProductsWithoutAnEnabledCadenceAreNotOffered(): void
    {
        $this->useSeeds([], [
            ['product_slug' => 'auditor', 'topics' => [['key' => 'kb-auditor-x', 'title' => 'Start an audit engagement in Auditor']]],
            ['product_slug' => 'billing', 'topics' => [['key' => 'kb-billing-x', 'title' => 'Create a GST invoice in Billing']]],
        ]);
        Database::connect()->table('reach_kb_product_cadence')->where('product_slug', 'auditor')->update(['enabled' => false]);

        $keys = array_column($this->next('kb')['items'], 'key');
        $this->assertSame(['kb-billing-x'], $keys);
    }

    // ─────────────────────────────────────────────────────────── propose

    public function testProposalsNeedEvidenceAndAreDeduplicated(): void
    {
        $this->useSeeds([], [['product_slug' => 'books', 'topics' => []]]);
        $topic = [
            'key'          => 'kb-books-recurring-invoices',
            'title'        => 'Set up recurring invoices in Smart Books',
            'product_slug' => 'books',
            'brief'        => 'Recurring invoices shipped this week (Invoices → Recurring); explain setup, schedule and pausing.',
        ];

        $res = $this->send('content-base/propose', ['type' => 'kb', 'items' => [$topic], 'routine_run_id' => 'session_a']);
        $res->assertStatus(200);
        $this->assertSame('invalid', $this->data($res)['results'][0]['status'], 'no evidence → refused');
        $this->assertStringContainsString('evidence', $this->data($res)['results'][0]['reason']);

        $url = $this->send('content-base/propose', ['type' => 'kb', 'items' => [$topic + ['evidence' => [['url' => 'https://aicountly.com/smart-books']]]]]);
        $this->assertStringContainsString('product repository', $this->data($url)['results'][0]['reason'], 'a KB topic must cite the product repo');

        $topic['evidence'] = [['repo' => 'aicountly/books-react-app', 'commits' => ['abc1234'], 'file' => 'src/pages/Invoices/Recurring.jsx']];
        $ok = $this->send('content-base/propose', ['type' => 'kb', 'items' => [$topic], 'routine_run_id' => 'session_a']);
        $ok->assertStatus(201);
        $this->assertSame('accepted', $this->data($ok)['results'][0]['status']);

        $again = $this->data($this->send('content-base/propose', ['type' => 'kb', 'items' => [$topic, ['key' => 'kb-books-other'] + $topic]]));
        $this->assertSame(['exists', 'duplicate'], array_column($again['results'], 'status'));

        $next = $this->next('kb');
        $this->assertSame('kb-books-recurring-invoices', $next['items'][0]['key']);
        $this->assertSame('routine', $next['items'][0]['origin']);
        $this->assertNotNull($next['activity']['last_proposal_at']);

        $entry = (new ContentBaseEntries())->find('knowledge_base', 'kb-books-recurring-invoices');
        $this->assertSame('session_a', $entry['proposed_by_run']);
        $this->assertSame('aicountly/books-react-app', json_decode($entry['evidence_json'], true)[0]['repo']);
    }

    public function testTheDailyProposalLimitHolds(): void
    {
        $this->useSeeds([], []);
        ReachConfig::set('automation.content_base.propose_daily_limit', 1);
        $mk = static fn (string $k, string $t): array => ['key' => $k, 'title' => $t, 'category' => 'gst',
            'brief' => 'A question small traders keep asking at the GSTR-3B due date; answer from the CBIC notification.',
            'evidence' => [['url' => 'https://cbic-gst.gov.in/', 'note' => 'notification']]];

        $res = $this->data($this->send('content-base/propose', ['type' => 'community', 'items' => [
            $mk('faq-gst-a', 'Can I revise a GSTR-3B after filing it?'), $mk('faq-gst-b', 'What happens if I file GSTR-1 after the due date?'),
        ]]));
        $this->assertSame(['accepted', 'limit_reached'], array_column($res['results'], 'status'));
        $this->assertSame(0, $res['remaining_today']);
    }

    public function testABlogProposalJoinsTheRoadmapToo(): void
    {
        $this->useSeeds([], []);
        $this->send('content-base/propose', ['type' => 'blog', 'items' => [[
            'key' => 'blog-20261018-gstr-9-checklist', 'title' => 'GSTR-9 annual return: a checklist for small businesses',
            'brief' => 'GSTR-9 for FY 2025-26 is due 31 December; walk through tables 4–17 and reconciliation with books.',
            'portfolio_stream' => 'marketing', 'evidence' => [['url' => 'https://www.gst.gov.in/', 'note' => 'due date']],
        ]]])->assertStatus(201);

        $c = Database::connect()->table('reach_topic_candidates')->where('content_base_key', 'blog-20261018-gstr-9-checklist')->get()->getRowArray();
        $this->assertSame('routine_proposal', $c['source']);
        $this->assertSame('candidate', $c['status']);
    }

    // ────────────────────────────────────────────────── produced / status

    public function testProducedLinksTheSubmissionAndStatusFollowsThePipeline(): void
    {
        $this->useSeeds([], [['product_slug' => 'books', 'topics' => [['key' => 'kb-books-company-setup', 'title' => 'Set up your company in Smart Books', 'article_type' => 'how_to']]]]);
        $item = $this->next('kb')['items'][0];
        $this->assertSame('kb-books-company-setup', $item['content_base_key']);

        $this->send('content-base/produced', ['type' => 'kb', 'key' => $item['key']])->assertStatus(409);
        $this->send('content-base/produced', ['type' => 'kb', 'key' => 'kb-nope'])->assertStatus(404);

        $draft = $this->send('kb-drafts', $this->kbDraft($item['content_base_key']));
        $draft->assertStatus(201);
        $itemId = (int) $this->data($draft)['content_item_id'];

        $rep = $this->send('content-base/produced', ['type' => 'kb', 'key' => $item['key'], 'outcome' => 'submitted', 'routine_run_id' => 'session_b']);
        $rep->assertStatus(200);
        $this->assertSame('queued', $this->data($rep)['status'], 'no Gemini key: stored and waiting for vetting');
        $this->assertSame([], $this->next('kb')['items'], 'a queued topic is not handed out again');

        $db = Database::connect();
        $db->table('reach_routine_submissions')->where('content_item_id', $itemId)->update(['status' => 'publishing']);
        (new ContentBaseEntries())->reconcile();
        $this->assertSame('produced', (new ContentBaseEntries())->find('knowledge_base', 'kb-books-company-setup')['status']);

        $db->table('reach_routine_submissions')->where('content_item_id', $itemId)->update(['status' => 'live', 'published_at' => date('Y-m-d H:i:s')]);
        (new ContentBaseEntries())->reconcile();
        $entry = (new ContentBaseEntries())->find('knowledge_base', 'kb-books-company-setup');
        $this->assertSame('published', $entry['status']);
        $this->assertSame($itemId, (int) $entry['content_item_id']);
        $this->assertNotNull($entry['published_at']);
    }

    public function testNotApplicableRetiresWithTheRoutinesReason(): void
    {
        $this->useSeeds([], [['product_slug' => 'books', 'topics' => [['key' => 'kb-books-bank-import', 'title' => 'Import bank statements into Smart Books']]]]);
        $this->next('kb');

        $this->send('content-base/produced', ['type' => 'kb', 'key' => 'kb-books-bank-import', 'outcome' => 'not_applicable'])->assertStatus(422);
        $res = $this->send('content-base/produced', ['type' => 'kb', 'key' => 'kb-books-bank-import', 'outcome' => 'not_applicable',
            'reason' => 'books-react-app has no bank statement import (no route or component for it on main).']);
        $res->assertStatus(200);
        $entry = (new ContentBaseEntries())->find('knowledge_base', 'kb-books-bank-import');
        $this->assertSame('retired', $entry['status']);
        $this->assertStringContainsString('no bank statement import', $entry['status_reason']);
    }

    public function testARejectedTopicComesBackOnceUnderAFreshKeyThenRetires(): void
    {
        $this->useSeeds([], [['product_slug' => 'books', 'topics' => [['key' => 'kb-books-year-end', 'title' => 'Close the year in Smart Books']]]]);
        $entries = new ContentBaseEntries();
        $this->next('kb');

        $first = $this->data($this->send('kb-drafts', $this->kbDraft('kb-books-year-end', ['title' => 'How to close the financial year in Smart Books', 'seo' => ['slug' => 'year-end-1']])));
        Database::connect()->table('reach_routine_submissions')->where('content_item_id', $first['content_item_id'])
            ->update(['status' => 'rejected', 'reasons_json' => json_encode(['Describes a Year-end menu that does not exist'])]);
        $entries->reconcile();

        $entry = $entries->find('knowledge_base', 'kb-books-year-end');
        $this->assertSame('planned', $entry['status']);
        $this->assertSame('kb-books-year-end-r2', $entry['content_key']);
        $this->assertStringContainsString('Year-end menu', $entry['status_reason']);
        $this->assertSame('kb-books-year-end-r2', $this->next('kb')['items'][0]['content_base_key']);

        $second = $this->data($this->send('kb-drafts', $this->kbDraft('kb-books-year-end-r2', ['title' => 'Year-end checklist for Smart Books users', 'seo' => ['slug' => 'year-end-2']])));
        Database::connect()->table('reach_routine_submissions')->where('content_item_id', $second['content_item_id'])
            ->update(['status' => 'rejected', 'reasons_json' => json_encode(['Still inaccurate'])]);
        $entries->reconcile();
        $this->assertSame('retired', $entries->find('knowledge_base', 'kb-books-year-end')['status']);
    }

    public function testCommunitySeedsFollowCuratedQuestionsAndRoutineFaqs(): void
    {
        $this->useSeeds([], [], [
            ['key' => 'q-curated', 'category' => 'gst', 'question' => 'Is there late-fee relief for GSTR-3B?'],
            ['key' => 'q-faq', 'category' => 'gst', 'question' => 'Which ITC is blocked under Section 17(5)?'],
        ]);
        $db = Database::connect();
        $this->assertCount(2, $this->next('community')['items']);

        $db->table('reach_community_questions')->insert(['title' => 'Is there late-fee relief for GSTR-3B?', 'external_question_id' => 'q-curated', 'source_type' => 'official_question']);
        $db->table('reach_community_automation_submissions')->insert(['external_key' => 'q-faq', 'kind' => 'faq', 'status' => 'published',
            'question_title' => 'Which ITC is blocked under Section 17(5)?', 'published_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $this->next('community')['items']);
        $e = new ContentBaseEntries();
        $this->assertSame('queued', $e->find('community', 'q-curated')['status'], 'an open curated question waits for the desk');
        $this->assertSame('published', $e->find('community', 'q-faq')['status']);
    }

    public function testASeedImportNeverResetsProgressAndAddsNewSeeds(): void
    {
        $seed = ['key' => 'gst-a', 'title' => 'GSTR-2B reconciliation, step by step', 'status' => 'planned', 'brief_prompt' => 'v1'];
        $this->useSeeds([$seed], []);
        (new ContentBaseSync())->run();
        $db = Database::connect();
        $db->table('reach_content_base_entries')->where('entry_key', 'gst-a')->update(['status' => 'published']);

        // Git changes the seed (and marks it retired), adds one, drops nothing.
        file_put_contents($this->baseDir . '/blog/index.json', json_encode(['entries' => [
            ['status' => 'retired', 'brief_prompt' => 'v2'] + $seed,
            ['key' => 'gst-b', 'title' => 'E-invoicing thresholds for FY 2026-27', 'brief_prompt' => 'new'],
        ]]));
        $out = (new ContentBaseSync())->run();

        $e = new ContentBaseEntries();
        $this->assertSame('published', $e->find('blog', 'gst-a')['status'], 'progress survives a seed edit');
        $this->assertSame('v1', $e->find('blog', 'gst-a')['brief']);
        $this->assertSame('planned', $e->find('blog', 'gst-b')['status']);
        $this->assertSame(1, $out['seeds']['blog']['created']);
    }

    // ───────────────────────────────────────────────────────── roadmap

    public function testAStuckInReachRoadmapTopicIsReleasedToTheRoutineQueue(): void
    {
        $this->useSeeds([['key' => 'smart-books-bank-reco', 'title' => 'Bank reconciliation in Smart Books', 'brief_prompt' => 'walkthrough']], []);
        $db  = Database::connect();
        $old = date('Y-m-d H:i:s', strtotime('-60 days'));
        $db->table('reach_content_items')->insert(['content_type' => 'blog', 'title' => 'Bank reconciliation in Smart Books', 'slug' => 'bank-reco',
            'workflow_status' => 'brief_ready', 'created_by_service' => 'reach:work_block:generate_brief', 'created_at' => $old, 'updated_at' => $old]);
        $itemId = (int) $db->insertID();
        $db->table('reach_work_blocks')->insert(['block_type' => 'GENERATE_OUTLINE', 'content_item_id' => $itemId, 'eligibility_status' => 'blocked']);
        (new ContentBaseSync())->run(); // creates the pinned candidate
        $db->table('reach_topic_candidates')->where('content_base_key', 'smart-books-bank-reco')
            ->update(['content_item_id' => $itemId, 'status' => 'roadmap_selected', 'is_locked' => true, 'updated_at' => $old]);

        // Before the release the topic is held by the stuck item, and says so.
        (new ContentBaseEntries())->reconcile();
        $held = (new ContentBaseEntries())->find('blog', 'smart-books-bank-reco');
        $this->assertSame('queued', $held['status']);
        $this->assertStringContainsString("retired in-house drafting at 'brief_ready'", $held['status_reason']);

        $out = (new ContentBaseSync())->run();
        $this->assertSame(1, $out['released']['released']);
        $this->assertSame('archived', $db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray()['workflow_status']);
        $this->assertSame('cancelled', $db->table('reach_work_blocks')->where('content_item_id', $itemId)->get()->getRowArray()['eligibility_status']);
        $cand = $db->table('reach_topic_candidates')->where('content_base_key', 'smart-books-bank-reco')->get()->getRowArray();
        $this->assertNull($cand['content_item_id']);
        $this->assertSame('f', $cand['is_locked']);

        $next = $this->next('blog');
        $this->assertSame('roadmap', $next['items'][0]['kind'], 'roadmap-selected topics come before plain seeds');
        $this->assertSame('smart-books-bank-reco', $next['items'][0]['content_base_key']);
        $this->assertCount(1, array_filter($next['items'], static fn ($i) => $i['key'] === 'smart-books-bank-reco'), 'not listed twice');
        $this->assertSame('planned', (new ContentBaseEntries())->find('blog', 'smart-books-bank-reco')['status'], 'open again for the routine');
    }

    public function testTheOptimiserHandsSelectionsToTheRoutineAndRescoresThemDaily(): void
    {
        $this->useSeeds([], []);
        $db = Database::connect();
        $db->table('reach_topic_candidates')->insert(['candidate_uuid' => bin2hex(random_bytes(16)), 'title' => 'TDS on rent under 194-IB explained',
            'normalized_title' => 'tds on rent under 194-ib explained', 'status' => 'candidate', 'is_human_pinned' => true, 'evidence_ready' => true,
            'portfolio_stream' => 'marketing']);
        $id = (int) $db->insertID();
        $_ENV['BLOG_AUTO_DISCOVER_ON_OPTIMIZE'] = 'false';

        $flags = new class () extends \App\Libraries\Blog\BlogFeatureFlags {
            public function isEnabled(string $flag): bool
            {
                return true;
            }
        };
        $opt = new \App\Libraries\Blog\Roadmap\RoadmapOptimizerService($flags);
        $day1 = date('Y-m-d', strtotime('-8 days'));
        $opt->run($day1);

        $cand = $db->table('reach_topic_candidates')->where('id', $id)->get()->getRowArray();
        $this->assertSame('roadmap_selected', $cand['status']);
        $this->assertNull($cand['content_item_id']);
        $this->assertSame(0, $db->table('reach_work_blocks')->where('block_type', 'GENERATE_BRIEF')->countAllResults(), 'no in-Reach brief/draft work');
        $this->assertSame('roadmap-' . $id, $this->next('blog')['items'][0]['content_base_key']);

        $today = date('Y-m-d');
        (new \App\Libraries\Blog\Roadmap\RoadmapOptimizerService($flags))->run($today);
        $score = $db->table('reach_topic_scores')->where('topic_candidate_id', $id)->where('is_current', true)->get()->getRowArray();
        $this->assertSame($today, substr((string) $score['scored_for_date'], 0, 10), 'a selected topic is rescored, so "scored for" moves');
        $this->assertNotNull($score['trend_7d'], 'with a score 8 days back the 7d trend is real');
        $this->assertSame('roadmap_selected', $db->table('reach_topic_candidates')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame(1, $db->table('reach_roadmap_decisions')->where('topic_candidate_id', $id)->countAllResults(), 'not re-decided every night');
        unset($_ENV['BLOG_AUTO_DISCOVER_ON_OPTIMIZE']);
    }

    public function testABriefBlockQueuedBeforeTheHandOffDoesNotRecreateAnInReachItem(): void
    {
        $this->useSeeds([], []);
        $db = Database::connect();
        $db->table('reach_topic_candidates')->insert(['candidate_uuid' => bin2hex(random_bytes(16)), 'title' => 'Advance tax for freelancers',
            'normalized_title' => 'advance tax for freelancers', 'status' => 'scored']);
        $id = (int) $db->insertID();
        $wb = new \App\Libraries\Blog\WorkBlockService(null, new class () extends \App\Libraries\Blog\BlogFeatureFlags {
            public function isEnabled(string $flag): bool
            {
                return true;
            }
        });
        $block = $wb->create(['block_type' => 'GENERATE_BRIEF', 'scope' => 'roadmap', 'input_json' => ['topic_candidate_id' => $id], 'eligibility_status' => 'eligible']);
        $out   = $wb->execute($block);

        $this->assertTrue($out['superseded']);
        $this->assertSame(0, $db->table('reach_content_items')->where('content_type', 'blog')->countAllResults());
        $this->assertSame('roadmap_selected', $db->table('reach_topic_candidates')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame('completed', $db->table('reach_work_blocks')->where('id', $block)->get()->getRowArray()['eligibility_status']);
    }

    // ──────────────────────────────────────────────────────── staleness

    public function testRoutineStalenessSignal(): void
    {
        $db = Database::connect();
        $health = (new RoutineActivity())->health();
        $this->assertSame('never_seen', $health['knowledge_base']['state']);
        $this->assertTrue($health['knowledge_base']['stale']);

        $this->withHeaders($this->routine())->call('GET', 'v1/automation/kb-plan')->assertStatus(200);
        $this->assertSame('ok', (new RoutineActivity())->health()['knowledge_base']['state']);
        $db->table('reach_routine_activity')->where('content_type', 'knowledge_base')->update(['last_at' => date('Y-m-d H:i:s', strtotime('-40 hours'))]);
        $kb = (new RoutineActivity())->health()['knowledge_base'];
        $this->assertSame('stale', $kb['state']);
        $this->assertEqualsWithDelta(40, $kb['hours_since'], 0.2);

        $db->table('reach_routine_activity')->where('content_type', 'knowledge_base')->update(['last_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))]);
        $this->assertSame('ok', (new RoutineActivity())->health()['knowledge_base']['state']);

        ReachConfig::set('automation.knowledge_base.enabled', false);
        $this->assertSame('off', (new RoutineActivity())->health()['knowledge_base']['state']);
        ReachConfig::set('automation.knowledge_base.enabled', true);

        // A weekly routine is not quiet after two days.
        $db->table('reach_routine_activity')->insert(['content_type' => 'workflow_guide', 'endpoint' => 'workflow-plan', 'method' => 'GET', 'hits' => 1,
            'last_at' => date('Y-m-d H:i:s', strtotime('-50 hours'))]);
        $this->assertSame('ok', (new RoutineActivity())->health()['workflow_guide']['state']);

        $lines = RoutineActivity::staleLines((new RoutineActivity())->health());
        $this->assertNotEmpty(array_filter($lines, static fn ($l) => str_contains($l, 'reach-community-qa')));

        $report = (new \App\Libraries\Reports\DailyReportBuilder())->build(date('Y-m-d'));
        $this->assertArrayHasKey('routines', $report['sections']);
        $this->assertNotEmpty(array_filter($report['sections']['todo']['items'], static fn ($l) => str_contains($l, 'routine activity')));
    }

    public function testTheConsoleOverviewIsLivingAndTruthful(): void
    {
        $admin = $this->authAs('super_admin');
        $res   = $this->withHeaders($admin)->call('GET', 'v1/content-base');
        $res->assertStatus(200);
        $data = $this->data($res);

        $this->assertTrue($data['available']);
        $this->assertGreaterThan(0, $data['totals']['entries']);
        $this->assertSame(['blog', 'knowledge_base', 'community'], array_keys($data['surfaces']));
        $this->assertSame('reach-kb', $data['surfaces']['knowledge_base']['routine']['routine']);
        $this->assertNotEmpty($data['stale'], 'no routine has called this fresh server');
        foreach ($data['surfaces'] as $surface) {
            foreach ($surface['entries'] as $e) {
                $this->assertContains($e['status'], ContentBaseEntries::STATUSES);
            }
        }
        $this->assertNotEmpty($data['surfaces']['knowledge_base']['products']);
    }
}
