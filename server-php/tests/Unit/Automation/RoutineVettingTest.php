<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\Automation\Routine\RoutineVetting;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Automation\Routine\RoutineVetting
 */
final class RoutineVettingTest extends CIUnitTestCase
{
    private array $queue = [];
    private array $sent  = [];
    private array $backup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['AI_GEMINI_API_KEY' => 'k', 'AI_GEMINI_DEFAULT_MODEL' => 'gemini-test'] as $k => $v) {
            $this->backup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        ReachConfig::flush();
        parent::tearDown();
    }

    private function verdict(string $decision, array $final = [], array $reasons = [], int $compliance = 9): array
    {
        $text = json_encode(['decision' => $decision, 'reasons' => $reasons, 'scores' => ['compliance' => $compliance], 'final_json' => json_encode($final)]);

        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])];
    }

    private function vetting(array ...$responses): RoutineVetting
    {
        $this->queue = $responses;
        $runs = 0;

        return new RoutineVetting(new ContentVettingService(
            function (string $url, array $body): array {
                $this->sent[] = $body;
                return array_shift($this->queue) ?? ['status' => 500, 'body' => '{}'];
            },
            static function () use (&$runs): int {
                return ++$runs;
            },
        ));
    }

    public function testApproveIsFinalAndCarriesTheRunId(): void
    {
        $out = $this->vetting($this->verdict('approve'))->run('blog', ['title' => 'T', 'body_html' => '<p>x</p>'], [], 'blog:1');
        $this->assertTrue($out['approved']);
        $this->assertSame('approved', $out['decision']);
        $this->assertSame(1, $out['run_id']);
        $this->assertSame(['title' => 'T', 'body_html' => '<p>x</p>'], $out['final']);
        $this->assertNull($out['high_risk']);
        $prompt = $this->sent[0]['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('HIGH_RISK', $prompt, 'the model is told how to flag high risk');
        $this->assertStringContainsString('1 (poor) to 10', $prompt);
    }

    public function testReviseIsReVettedExactlyOnce(): void
    {
        $out = $this->vetting($this->verdict('revise', ['title' => 'Fixed']), $this->verdict('approve'))->run('blog', ['title' => 'T'], [], 'blog:1');
        $this->assertSame('revised_approved', $out['decision']);
        $this->assertSame(['title' => 'Fixed'], $out['final']);
        $this->assertSame([1, 2], $out['run_ids']);
        $this->assertStringContainsString('"title": "Fixed"', $this->sent[1]['contents'][0]['parts'][0]['text'], 'the revision is what gets re-vetted');

        $twice = $this->vetting($this->verdict('revise', ['title' => 'A']), $this->verdict('revise', ['title' => 'B']), $this->verdict('approve'))->run('blog', ['title' => 'T'], [], 'blog:2');
        $this->assertFalse($twice['approved']);
        $this->assertSame('revise_unresolved', $twice['decision']);
        $this->assertCount(1, $this->queue, 'no third call');
    }

    public function testRejectAndOutage(): void
    {
        $out = $this->vetting($this->verdict('reject', [], ['Wrong rate']))->run('knowledge_base', ['title' => 'T'], [], 'kb:1');
        $this->assertFalse($out['approved']);
        $this->assertSame(['Wrong rate'], $out['reasons']);

        $this->expectException(VettingUnavailableException::class);
        $this->vetting()->run('blog', ['title' => 'T'], [], 'blog:3');
    }

    public function testHighRiskComesOnlyFromTheVettingModelsOwnVerdict(): void
    {
        $flag = $this->vetting($this->verdict('approve', [], ['High_Risk: individual tax advice']))->run('blog', ['title' => 'T'], [], 'b');
        $this->assertSame('vetting_flag', $flag['high_risk']);

        $low = $this->vetting($this->verdict('approve', [], [], 4))->run('blog', ['title' => 'T'], [], 'b');
        $this->assertSame('low_compliance_score', $low['high_risk']);

        $ok = $this->vetting($this->verdict('approve', [], ['Mentions high risk of penalties for late filing'], 5))->run('blog', ['title' => 'T'], [], 'b');
        $this->assertNull($ok['high_risk'], 'talking about risk is not a flag; only the HIGH_RISK prefix is');
    }
}
