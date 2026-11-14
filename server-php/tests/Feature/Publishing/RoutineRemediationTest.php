<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Automation\Routine\RemediationRefused;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineRemediator;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseDraftIngestService;
use App\Libraries\Settings\ReachConfig;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * The bot's fix ladder for a routine article that stopped on its way out:
 * re-vet the current text → ask the routine for a same-slug rewrite → a
 * person. Nothing goes out without vetting.
 *
 * @internal
 */
final class RoutineRemediationTest extends DatabaseTestCase
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

    private function verdict(string $decision, array $reasons = []): array
    {
        $text = json_encode(['decision' => $decision, 'reasons' => $reasons, 'scores' => ['accuracy' => 9, 'clarity' => 9, 'compliance' => 9, 'tone' => 9], 'final_json' => json_encode([])]);

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    private function vetting(): ContentVettingService
    {
        return new ContentVettingService(function (): array {
            return array_shift($this->gemini) ?? ['status' => 503, 'body' => '{}'];
        });
    }

    private function remediator(): RoutineRemediator
    {
        return new RoutineRemediator(null, new RoutineContentPipeline($this->vetting()));
    }

    /** An approved help article that then stopped with the given reason. */
    private function blocked(string $status, string $kind, string $error, bool $approved = false): int
    {
        $body = '';
        foreach (['Open Billing from the app launcher.', 'Choose New invoice on the Invoices screen.', 'Pick the customer and add each item.',
            'Check the tax lines Billing calculates.', 'Save the invoice, then share it.'] as $i => $line) {
            $body .= '<h2>Step ' . ($i + 1) . "</h2><p>{$line} This part of the guide explains what you see on screen, what each field means in plain words, "
                . 'and what to check before you move on, so that the invoice you send to your customer is complete and correct the first time.</p>';
        }
        $ingest = (new KnowledgeBaseDraftIngestService())->ingestKbDraft([
            'title' => 'How to create your first invoice in Billing', 'product_slug' => 'billing', 'article_type' => 'how_to',
            'content_base_key' => 'kb-billing-first-invoice-' . bin2hex(random_bytes(2)), 'summary' => 'Create, check and share a sales invoice.',
            'body_html' => $body,
            'seo' => ['slug' => 'billing-first-invoice-' . bin2hex(random_bytes(2)), 'meta_title' => 'Create your first invoice in Billing', 'meta_description' => 'Step-by-step: create, check and share your first sales invoice in Aicountly Billing.'],
            'steps' => [['title' => 'Open Billing', 'text' => 'Open Billing from the app launcher.'], 'Save the invoice.'],
            'provenance' => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $id = (int) $ingest['submission_id'];
        if ($approved) {
            $this->gemini = [$this->verdict('approve')];
            $this->assertSame(RoutineSubmissions::PUBLISHING, (new RoutineContentPipeline($this->vetting()))->process($id)['status']);
        }
        (new RoutineSubmissions())->update($id, ['status' => $status, 'block_kind' => $kind, 'last_error' => $error]);

        return $id;
    }

    private function row(int $id): array
    {
        return (new RoutineSubmissions())->find($id);
    }

    public function testTextThatChangedAfterApprovalIsReVettedAndPublished(): void
    {
        $id = $this->blocked(RoutineSubmissions::PUBLISH_FAILED, 'version_mismatch', 'The current version no longer matches the vetted, approved text; not publishing.');
        $this->assertSame('revet', RoutineRemediator::diagnose($this->row($id))['step']);

        $this->gemini = [$this->verdict('approve')];
        $out = $this->remediator()->sweep();

        $this->assertSame(['revet' => 1], $out['steps']);
        $row = $this->row($id);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));
        $this->assertSame('revet', $row['remediation_step']);
        $this->assertSame(1, (int) $row['remediation_count']);
        $this->assertNotEmpty($row['vetting_run_id'], 'published only after a fresh vetting run');
        $this->assertSame([], $this->gemini, 'exactly one Gemini call');
    }

    public function testWhenReVettingRejectsItTheRoutineIsAskedForARewrite(): void
    {
        $id = $this->blocked(RoutineSubmissions::PUBLISH_BLOCKED, 'vet_refused', 'Publish refused: Not published: the vetting model rejected it: steps are wrong.');

        $this->gemini = [$this->verdict('reject', ['OFF_TOPIC: the steps describe Books, not Billing.'])];
        $this->remediator()->sweep();
        $row = $this->row($id);
        $this->assertSame(RoutineSubmissions::REJECTED, $row['status']);
        $this->assertSame('rewrite', RoutineRemediator::diagnose($row)['step']);

        // Within the pause nothing happens; after it, the rewrite is queued.
        $this->assertSame(0, $this->remediator()->sweep()['considered']);
        $this->remediator()->sweep(10, new \DateTimeImmutable('+1 hour'));
        $row = $this->row($id);
        $this->assertSame(RoutineSubmissions::AWAITING_REWRITE, $row['status'], json_encode($row));
        $this->assertSame(2, (int) $row['remediation_count']);

        $regen = Database::connect()->table('reach_content_regenerations')->where('content_item_id', $row['content_item_id'])->get()->getRowArray();
        $this->assertSame('queued', $regen['status']);
        $this->assertStringContainsString('OFF_TOPIC', $regen['reasons'], 'the routine is told what was wrong');
        $this->assertSame('wait', RoutineRemediator::diagnose($row)['step']);
    }

    public function testARewriteTheRoutineGaveUpOnGoesToAPerson(): void
    {
        $id  = $this->blocked(RoutineSubmissions::PUBLISH_FAILED, 'vet_refused', 'Publish refused.');
        $row = $this->remediator()->apply($id, 'rewrite', 'operator', null, 'Make it about Billing only.');
        $this->assertSame(RoutineSubmissions::AWAITING_REWRITE, $row['status']);
        $db = Database::connect();
        $this->assertStringContainsString('Owner note: Make it about Billing only.', $db->table('reach_content_regenerations')->where('content_item_id', $row['content_item_id'])->get()->getRowArray()['reasons']);

        $db->table('reach_content_regenerations')->where('content_item_id', $row['content_item_id'])->update(['status' => 'cancelled']);
        $this->remediator()->sweep();
        $row = $this->row($id);
        $this->assertSame(RoutineSubmissions::PUBLISH_FAILED, $row['status']);
        $this->assertSame('person', RoutineRemediator::diagnose($row)['step']);
    }

    public function testARevokedApprovalIsLeftToAPersonButCanBeReVettedByHand(): void
    {
        $id = $this->blocked(RoutineSubmissions::PUBLISH_FAILED, 'approval_revoked', 'The approval was revoked; not publishing.');
        $this->assertSame(0, $this->remediator()->sweep(10, new \DateTimeImmutable('+1 day'))['considered']);
        $this->assertSame('person', RoutineRemediator::diagnose($this->row($id))['step']);

        $this->gemini = [$this->verdict('approve')];
        $row = $this->remediator()->apply($id, 'revet', 'operator', 1);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status']);
    }

    public function testASiteRefusalIsRetriedAfterAPauseThenLeftToAPerson(): void
    {
        $id = $this->blocked(RoutineSubmissions::PUBLISH_FAILED, 'site_refused', 'Public site refused the publish: http_500', true);
        $this->assertSame(0, $this->remediator()->sweep()['considered'], 'just failed: wait');

        Database::connect()->table('reach_routine_submissions')->where('id', $id)->update(['updated_at' => date('Y-m-d H:i:s', time() - 7 * 3600)]);
        $this->remediator()->sweep();
        $row = $this->row($id);
        $this->assertSame('retry', $row['remediation_step']);
        $this->assertSame(RoutineSubmissions::PUBLISHING, $row['status'], json_encode($row));

        $this->assertSame('person', RoutineRemediator::diagnose(['status' => 'publish_failed', 'block_kind' => 'site_refused', 'remediation_step' => 'retry', 'remediation_count' => 2])['step']);
    }

    public function testStepsThatDoNotApplyAreRefusedAndTheSwitchStopsTheBot(): void
    {
        $id = $this->blocked(RoutineSubmissions::LIVE, '', '');
        try {
            $this->remediator()->apply($id, 'revet');
            $this->fail('a live article is not re-vetted from here');
        } catch (RemediationRefused $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->blocked(RoutineSubmissions::PUBLISH_FAILED, 'version_mismatch', 'no longer matches');
        ReachConfig::set('automation.remediation.auto_fix_blocked', false);
        ReachConfig::flush();
        $this->assertArrayHasKey('skipped', $this->remediator()->sweep());
    }

    public function testOldRowsAreClassifiedFromTheirError(): void
    {
        $this->assertSame('vet_refused', RoutineRemediator::blockKind(['last_error' => 'Publish refused: Not published: the vetting model (gemini) asks for corrections: x']));
        $this->assertSame('not_approved', RoutineRemediator::blockKind(['last_error' => 'Publish refused: Blog content needs a valid approval of this exact version before publishing (approval_checksum_mismatch).']));
        $this->assertSame('version_mismatch', RoutineRemediator::blockKind(['last_error' => 'The current version no longer matches the vetted, approved text; not publishing.']));
        $this->assertSame('site_refused', RoutineRemediator::blockKind(['last_error' => 'Public site refused the publish: validation']));
    }
}
