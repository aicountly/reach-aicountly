<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Structured\StructuredSubmissionService;
use App\Libraries\Automation\Structured\SubmissionRefused;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Settings\ReachConfig;
use Tests\Support\ApiTestCase;

/**
 * Release notes and workflow guides from the routines: vetting decides,
 * the system approver signs off, the mock receiver publishes with the right
 * content type, and only a `live` verification counts as published.
 *
 * @covers \App\Libraries\Automation\Structured\StructuredSubmissionService
 * @covers \App\Libraries\Automation\Structured\AutomationPlanService
 * @covers \App\Libraries\Publishing\Jobs\PublicationDeploymentService
 * @covers \App\Controllers\Api\V1\Automation\StructuredContentController
 * @covers \App\Controllers\Api\V1\Publishing\StructuredSubmissionController
 */
final class StructuredContentPipelineTest extends ApiTestCase
{
    private const TOKEN = 'test-automation-token-0123456789abcdef';

    /** @var array<string, string|null> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['REACH_PUB_MOCK', 'CI_ENVIRONMENT', 'AI_GEMINI_API_KEY', 'AI_GEMINI_DEFAULT_MODEL', 'REACH_AUTOMATION_TOKEN'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['REACH_PUB_MOCK']          = 'true';
        $_ENV['CI_ENVIRONMENT']          = 'testing';
        $_ENV['AI_GEMINI_API_KEY']       = 'test-gemini-key';
        $_ENV['AI_GEMINI_DEFAULT_MODEL'] = 'gemini-test';
        $_ENV['REACH_AUTOMATION_TOKEN']  = self::TOKEN;
        putenv('REACH_AUTOMATION_TOKEN=' . self::TOKEN);
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
        putenv('REACH_AUTOMATION_TOKEN');
        ReachConfig::flush();
        PublicSitePublisherFactory::resetMock();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array{status: int, body: string} */
    private function gemini(string $decision, array $final = [], array $reasons = ['Reads well.']): array
    {
        $verdict = ['decision' => $decision, 'reasons' => $reasons, 'scores' => ['accuracy' => 9, 'clarity' => 9, 'compliance' => 10, 'tone' => 9], 'final_json' => json_encode($final)];

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode($verdict)]]]]]])];
    }

    /** @param array{status: int, body: string} ...$responses */
    private function service(array ...$responses): StructuredSubmissionService
    {
        $queue = $responses;
        $http  = static function (string $url, array $body) use (&$queue): array {
            return array_shift($queue) ?? ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}'];
        };

        return new StructuredSubmissionService(new ContentVettingService($http));
    }

    /** @return array<string, mixed> */
    private function note(string $product = 'billing', string $version = '1.0', string $key = ''): array
    {
        $repos = ['billing' => 'billing-aicountly', 'books' => 'books-react-app'];

        return [
            'external_key' => $key !== '' ? $key : "{$product}:{$version}:" . bin2hex(random_bytes(3)),
            'product_slug' => $product,
            'version'      => $version,
            'title'        => "Release Notes for AICOUNTLY Billing Version {$version}",
            'date_iso'     => '2026-10-16',
            'intro'        => 'This release adds a day-close dashboard and fixes rounding on split payments.',
            'sections'     => [
                ['heading' => 'New', 'bullets' => ['A day-close dashboard with cash, card and UPI totals.']],
                ['heading' => 'Fixed', 'paragraphs' => ['Bills paid partly in cash and partly by card no longer round each part separately.']],
            ],
            'source' => ['repo' => $repos[$product], 'from_ref' => 'a1b2c3d4', 'to_ref' => 'e5f6a7b8c9', 'commits_count' => 12],
        ];
    }

    /** @return array<string, mixed> */
    private function guide(string $key, string $text = 'Go to Vouchers and choose Sales.'): array
    {
        return [
            'external_key'  => $key,
            'product_slug'  => 'books',
            'slug'          => 'record-a-sales-invoice',
            'title'         => 'Record a sales invoice with GST',
            'blurb'         => 'Raise a GST invoice in Smart Books and post it to the customer.',
            'audience'      => 'Business owners and accountants',
            'outcome'       => 'A saved sales invoice with the tax worked out for you.',
            'prerequisites' => ['A customer added in Smart Books'],
            'steps'         => [
                ['title' => 'Open Sales', 'text' => $text, 'tip' => 'You can also press Alt+S.'],
                ['title' => 'Add items', 'text' => 'Add each item. The tax is worked out from the item\'s GST rate.'],
                ['title' => 'Save', 'text' => 'Check the totals and choose Save.'],
            ],
            'faqs'   => [['q' => 'Can I change the invoice later?', 'a' => 'Yes, until the period is locked.']],
            'source' => ['repo' => 'books-react-app', 'ref' => 'abc12345'],
        ];
    }

    private function row(int $id): array
    {
        return \Config\Database::connect()->table('reach_structured_content_submissions')->where('id', $id)->get()->getRowArray();
    }

    /** @return list<array{method: string, args: array}> */
    private function calls(string $method): array
    {
        return array_values(array_filter(PublicSitePublisherFactory::mock()->getCalls(), static fn ($c) => $c['method'] === $method));
    }

    // ── pipeline ────────────────────────────────────────────────────────────

    public function testAnApprovedReleaseNoteIsPublishedAsAReleaseNoteAndConfirmedLive(): void
    {
        $result = $this->service($this->gemini('approve'))->submitReleaseNote($this->note());

        $this->assertSame(201, $result['http']);
        $data = $result['data'];
        $this->assertSame('published', $data['status']);
        $this->assertSame('https://aicountly.com/releases/billing-v1-0', $data['public_url']);
        $this->assertSame('Reach System Bot', $data['approved_by']);
        $this->assertNotNull($data['vetting_run_id']);

        $create = $this->calls('createDraft')[0]['args'];
        $this->assertSame('release_note', $create['content_type'], 'not mapped to blog');
        $this->assertSame('aicountly_com_release_notes', $create['publication_target']);
        $this->assertSame('billing-v1-0', $create['payload']['slug']);
        $this->assertSame('1.0', $create['payload']['version']);
        $this->assertSame(hash('sha256', json_encode($create['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $create['payload_checksum']);
        $this->assertSame('release_note', $this->calls('publish')[0]['args']['envelope']['content_type']);
        $this->assertCount(1, $this->calls('getVerification'), 'live is confirmed before success is reported');

        $row = $this->row($data['id']);
        $this->assertSame(SystemApprover::userId(), (int) $row['approved_by_user_id']);
        $db   = \Config\Database::connect();
        $item = $db->table('reach_content_items')->where('id', $row['content_item_id'])->get()->getRowArray();
        $this->assertSame('release_note', $item['content_type']);
        $this->assertSame('approved', $item['approval_status']);
        $this->assertSame(SystemApprover::actorId(), (int) $item['approved_by']);
        $this->assertSame('published', $db->table('reach_publication_deployments')->where('id', $row['deployment_id'])->get()->getRowArray()['status']);
        $run = $db->table('reach_ai_vetting_runs')->where('id', $row['vetting_run_id'])->get()->getRowArray();
        $this->assertSame('release_note', $run['content_type']);
        $this->assertSame('approve', $run['decision']);
    }

    public function testARevisionIsReVettedOnceAndOnlyTheTextChanges(): void
    {
        $revised = [
            'title'    => 'Release Notes for AICOUNTLY Billing Version 1.0',
            'intro'    => 'Billing 1.0 adds a day-close dashboard and fixes rounding on split payments.',
            'sections' => [['heading' => 'New', 'bullets' => ['See your cash, card and UPI totals at the end of the day.']]],
            'slug'     => 'something-else', // not a vetted field — must be ignored
        ];
        $result = $this->service($this->gemini('revise', $revised, ['Simplified the intro.']), $this->gemini('approve'))
            ->submitReleaseNote($this->note());

        $this->assertSame('published', $result['data']['status']);
        $this->assertSame(['Simplified the intro.', 'Reads well.'], $result['data']['reasons']);
        $this->assertCount(2, $result['data']['vetting_run_ids']);
        $payload = $this->calls('createDraft')[0]['args']['payload'];
        $this->assertSame($revised['intro'], $payload['intro']);
        $this->assertSame('billing-v1-0', $payload['slug'], 'identity fields are not the model\'s to change');
    }

    public function testTwoRevisionsInARowAreNotPublished(): void
    {
        $revised = ['title' => 'T is long enough', 'intro' => 'Changed', 'sections' => [['heading' => 'New', 'bullets' => ['x']]]];
        $result  = $this->service($this->gemini('revise', $revised), $this->gemini('revise', $revised))->submitReleaseNote($this->note());

        $this->assertSame('rejected', $result['data']['status']);
        $this->assertStringContainsString('still asked for changes', implode(' ', $result['data']['reasons']));
        $this->assertSame([], $this->calls('createDraft'));
    }

    public function testARejectedNoteIsStoredWithTheReasonsAndNeverPublished(): void
    {
        $result = $this->service($this->gemini('reject', [], ['Describes a security fix in detail.']))->submitReleaseNote($this->note());

        $this->assertSame(200, $result['http']);
        $this->assertSame('rejected', $result['data']['status']);
        $this->assertSame(['Describes a security fix in detail.'], $result['data']['reasons']);
        $this->assertFalse($result['data']['retryable']);
        $this->assertNull($result['data']['public_url']);
        $this->assertSame([], PublicSitePublisherFactory::mock()->getCalls());
        $this->assertNull($this->row($result['data']['id'])['content_item_id']);

        // A rejected version holds no number: the routine may try 1.0 again with a new note.
        $again = $this->service($this->gemini('approve'))->submitReleaseNote($this->note());
        $this->assertSame('published', $again['data']['status']);
    }

    public function testTheVersionMustMoveForwardByOneStep(): void
    {
        $refused = function (string $version): ?SubmissionRefused {
            try {
                $this->service($this->gemini('approve'))->submitReleaseNote($this->note('books', $version));
            } catch (SubmissionRefused $e) {
                return $e;
            }
            return null;
        };

        // Smart Books' hand-written 2.0 on the site is the floor.
        foreach (['2.0', '1.9', '2.2', '3.1'] as $v) {
            $e = $refused($v);
            $this->assertNotNull($e, "{$v} must be refused");
            $this->assertSame(409, $e->httpStatus);
            $this->assertSame(['fix' => '2.0.1', 'feature' => '2.1', 'major' => '3.0'], $e->details['allowed_next']);
        }

        $this->assertNull($refused('2.1'));
        $this->assertSame(409, $refused('2.1')->httpStatus, 'a published version cannot be repeated');
        $this->assertSame(['fix' => '2.1.1', 'feature' => '2.2', 'major' => '3.0'], $refused('2.0.1')->details['allowed_next']);
        $this->assertNull($refused('2.1.1'));
    }

    public function testUnavailableVettingLeavesItPendingUntilTheSweepPublishesIt(): void
    {
        $pending = $this->service()->submitReleaseNote($this->note());

        $this->assertSame(202, $pending['http']);
        $this->assertSame('pending_vetting', $pending['data']['status']);
        $this->assertSame('vetting_unavailable', $pending['data']['error_category']);
        $this->assertNotNull($pending['data']['next_attempt_at']);
        $this->assertSame([], PublicSitePublisherFactory::mock()->getCalls(), 'unvetted content is never published');

        // Not due yet: the sweep leaves it alone.
        $this->assertSame(0, $this->service($this->gemini('approve'))->sweep()['considered']);

        \Config\Database::connect()->table('reach_structured_content_submissions')
            ->where('id', $pending['data']['id'])->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 60)]);
        $summary = $this->service($this->gemini('approve'))->sweep();

        $this->assertSame(['published' => 1], $summary['by_status']);
        $this->assertSame('published', $this->row($pending['data']['id'])['status']);
    }

    public function testAPublishThatIsNotLiveIsAFailureAndIsRetried(): void
    {
        PublicSitePublisherFactory::mock()->forceNotLive();
        $result = $this->service($this->gemini('approve'))->submitReleaseNote($this->note());

        $this->assertSame(202, $result['http']);
        $this->assertSame('failed', $result['data']['status']);
        $this->assertSame('not_live', $result['data']['error_category']);
        $this->assertNull($result['data']['public_url'], 'never reported as published');
        $this->assertNotNull($result['data']['next_attempt_at']);

        PublicSitePublisherFactory::mock()->forceNotLive(false);
        \Config\Database::connect()->table('reach_structured_content_submissions')
            ->where('id', $result['data']['id'])->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->service()->sweep();

        $row = $this->row($result['data']['id']);
        $this->assertSame('published', $row['status']);
        $this->assertSame(2, (int) $row['attempt_count']);
        $this->assertCount(1, $this->calls('createDraft'), 'the retry updates the same public item');
        $this->assertCount(1, $this->calls('updateDraft'));
    }

    public function testAutomationSwitchedOffRefusesNewWorkAndPausesTheSweep(): void
    {
        $pending = $this->service()->submitReleaseNote($this->note());
        ReachConfig::set('automation.release_note.enabled', false);
        ReachConfig::flush();

        try {
            $this->service($this->gemini('approve'))->submitReleaseNote($this->note('billing', '1.1'));
            $this->fail('expected a refusal');
        } catch (SubmissionRefused $e) {
            $this->assertSame(403, $e->httpStatus);
        }

        \Config\Database::connect()->table('reach_structured_content_submissions')
            ->where('id', $pending['data']['id'])->update(['next_attempt_at' => date('Y-m-d H:i:s', time() - 60)]);
        $summary = $this->service($this->gemini('approve'))->sweep();
        $this->assertSame(['release_note'], $summary['disabled_types']);
        $this->assertSame('pending_vetting', $this->row($pending['data']['id'])['status']);
    }

    public function testTheDailyLimitAnswers429(): void
    {
        ReachConfig::set('automation.release_note.daily_limit', 1);
        ReachConfig::flush();
        $first = $this->service()->submitReleaseNote($this->note('billing', '1.0', 'billing:1.0:quota'));

        try {
            $this->service()->submitReleaseNote($this->note('books', '2.1'));
            $this->fail('expected the quota to refuse');
        } catch (SubmissionRefused $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(['daily_limit' => 1, 'used_today' => 1], $e->details);
        }

        // A replay of today's submission is not new work.
        $this->assertTrue($this->service()->submitReleaseNote($this->note('billing', '1.0', 'billing:1.0:quota'))['data']['idempotent_replay']);
        $this->assertSame('pending_vetting', $first['data']['status']);
    }

    public function testAGuideUpdateReplacesTheSamePageAndAnUnchangedGuideIsANoOp(): void
    {
        $first = $this->service($this->gemini('approve'))->submitWorkflowGuide($this->guide('books:record-a-sales-invoice:w42'));
        $this->assertSame('published', $first['data']['status']);
        $this->assertSame('https://aicountly.com/guides/smart-books/record-a-sales-invoice', $first['data']['public_url']);
        $create = $this->calls('createDraft')[0]['args'];
        $this->assertSame('workflow_guide', $create['content_type']);
        $this->assertSame('aicountly_com_workflow_guides', $create['publication_target']);
        $this->assertSame('books', $create['payload']['product_slug']);
        $this->assertArrayNotHasKey('external_key', $create['payload']);
        $this->assertArrayNotHasKey('source', $create['payload']);

        $same = $this->service()->submitWorkflowGuide($this->guide('books:record-a-sales-invoice:w43'));
        $this->assertSame(200, $same['http']);
        $this->assertSame('unchanged', $same['data']['status']);

        $changed = $this->service($this->gemini('approve'))->submitWorkflowGuide($this->guide('books:record-a-sales-invoice:w44', 'Open Vouchers, then Sales.'));
        $this->assertSame('published', $changed['data']['status']);
        $this->assertCount(1, $this->calls('createDraft'));
        $update = $this->calls('updateDraft')[0]['args'];
        $this->assertSame((int) $this->row($first['data']['id'])['public_content_id'], $update['public_content_id']);
        $this->assertSame('Open Vouchers, then Sales.', $update['envelope']['payload']['steps'][0]['text']);
        $this->assertGreaterThan($create['reach_content_version_id'], $update['envelope']['reach_content_version_id']);
        $this->assertSame($this->row($first['data']['id'])['content_item_id'], $this->row($changed['data']['id'])['content_item_id']);

        $link = \Config\Database::connect()->table('reach_link_registry')->where('url_path', '/guides/smart-books/record-a-sales-invoice')->get()->getRowArray();
        $this->assertSame('guide', $link['link_type']);
    }

    public function testTheSameKeyReplaysAndAReusedKeyWithNewContentConflicts(): void
    {
        $note  = $this->note('billing', '1.0', 'billing:1.0:fixed');
        $first = $this->service($this->gemini('approve'))->submitReleaseNote($note);
        $again = $this->service()->submitReleaseNote($note);

        $this->assertSame(200, $again['http']);
        $this->assertTrue($again['data']['idempotent_replay']);
        $this->assertSame($first['data']['id'], $again['data']['id']);
        $this->assertCount(1, $this->calls('createDraft'));

        $note['intro'] = 'Different text under the same key.';
        try {
            $this->service()->submitReleaseNote($note);
            $this->fail('expected a conflict');
        } catch (SubmissionRefused $e) {
            $this->assertSame(409, $e->httpStatus);
        }
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    public function testTheAutomationEndpoints(): void
    {
        unset($_ENV['AI_GEMINI_API_KEY']);
        ReachConfig::flush();
        $h = ['X-Automation-Token' => self::TOKEN, 'Accept' => 'application/json'];

        $this->call('GET', 'v1/automation/release-plan')->assertStatus(401);

        $plan  = json_decode($this->withHeaders($h)->call('GET', 'v1/automation/release-plan')->getJSON(), true)['data'];
        $books = $plan['products'][0];
        $this->assertSame('books', $books['product_slug']);
        $this->assertSame('books-react-app', $books['repo']);
        $this->assertSame('2.0', $books['last_published']['version']);
        $this->assertSame('site', $books['last_published']['source']);
        $this->assertSame('2026-09-14', $books['diff_from']['since']);
        $this->assertSame('2.1', $books['next_versions']['feature']);

        $res = $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/automation/release-notes', ['product_slug' => 'billing', 'version' => '1.0', 'external_key' => 'k1']);
        $res->assertStatus(422);
        $this->assertNotEmpty(json_decode($res->getJSON(), true)['details']);

        // No Gemini key: stored, pending, not published.
        $res = $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/automation/release-notes', $this->note());
        $res->assertStatus(202);
        $body = json_decode($res->getJSON(), true)['data'];
        $this->assertSame('pending_vetting', $body['status']);

        $plan = json_decode($this->withHeaders($h)->call('GET', 'v1/automation/release-plan')->getJSON(), true)['data'];
        $billing = array_values(array_filter($plan['products'], static fn ($p) => $p['product_slug'] === 'billing'))[0];
        $this->assertSame('pending_vetting', $billing['in_flight'][0]['status']);
        $this->assertNull($billing['last_published']);

        $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/automation/workflow-guides', $this->guide('g1'))->assertStatus(202);

        $wplan = json_decode($this->withHeaders($h)->call('GET', 'v1/automation/workflow-plan')->getJSON(), true)['data'];
        $this->assertSame(['books', 'notes', 'billing', 'inventory', 'docs', 'manage'], array_column($wplan['products'], 'product_slug'));
        $bookGuides = array_column($wplan['products'][0]['guides'], null, 'slug');
        $this->assertSame('site', $bookGuides['record-a-sales-invoice']['source']);
        $this->assertSame('https://aicountly.com/guides/smart-books', $wplan['products'][0]['guide_hub_url']);
        $this->assertSame('record-a-sales-invoice', $wplan['products'][0]['in_flight'][0]['slug']);

        ReachConfig::set('automation.workflow_guide.enabled', false);
        ReachConfig::flush();
        $this->withHeaders($h)->withBodyFormat('json')->call('POST', 'v1/automation/workflow-guides', $this->guide('g2'))->assertStatus(403);
    }

    public function testThePublishingListShowsStatusReasonsAndAllowsRetry(): void
    {
        $rejected = $this->service($this->gemini('reject', [], ['Promises a future feature.']))->submitReleaseNote($this->note());
        $pending  = $this->service()->submitReleaseNote($this->note('billing', '1.0'));

        unset($_ENV['AI_GEMINI_API_KEY']); // the controller's own vetting service must not reach Gemini
        ReachConfig::flush();
        $h    = $this->authAs('super_admin');
        $list = json_decode($this->withHeaders($h)->call('GET', 'v1/publishing/release-notes')->getJSON(), true)['data'];
        $this->assertSame(2, $list['total']);
        $byId = array_column($list['items'], null, 'id');
        $this->assertSame(['Promises a future feature.'], $byId[$rejected['data']['id']]['reasons']);
        $this->assertSame('Billing', $byId[$pending['data']['id']]['product_name']);

        $this->withHeaders($h)->call('POST', 'v1/publishing/release-notes/' . $rejected['data']['id'] . '/retry')->assertStatus(409);
        $this->withHeaders($h)->call('POST', 'v1/publishing/workflow-guides/' . $pending['data']['id'] . '/retry')->assertStatus(404);
        $res = $this->withHeaders($h)->call('POST', 'v1/publishing/release-notes/' . $pending['data']['id'] . '/retry');
        $res->assertStatus(200);
        $this->assertSame('pending_vetting', json_decode($res->getJSON(), true)['data']['status'], 'still no verdict: stays pending');

        $this->withHeaders($this->authAs('viewer'))->call('GET', 'v1/publishing/release-notes')->assertStatus(403);
        $this->assertSame(0, json_decode($this->withHeaders($h)->call('GET', 'v1/publishing/workflow-guides')->getJSON(), true)['data']['total']);
    }
}
