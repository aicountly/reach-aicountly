<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vetting;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\Rubrics;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Vetting\ContentVettingService
 * @covers \App\Libraries\Ai\Vetting\Rubrics
 */
final class ContentVettingServiceTest extends CIUnitTestCase
{
    private array $envBackup = [];

    /** @var list<array> */
    private array $recorded = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['AI_GEMINI_API_KEY', 'AI_GEMINI_DEFAULT_MODEL'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['AI_GEMINI_API_KEY']       = 'test-gemini-key';
        $_ENV['AI_GEMINI_DEFAULT_MODEL'] = 'gemini-test-flash';
        ReachConfig::flush();
        $this->recorded = [];
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
        parent::tearDown();
    }

    private function service(array ...$responses): ContentVettingService
    {
        $queue = $responses;
        return new ContentVettingService(
            function (string $url, array $body) use (&$queue): array {
                $this->assertStringContainsString('/models/gemini-test-flash:generateContent', $url);
                return array_shift($queue) ?? ['status' => 500, 'body' => '{}'];
            },
            function (array $row): int {
                $this->recorded[] = $row;
                return count($this->recorded);
            },
        );
    }

    private static function gemini(array $verdict): array
    {
        return ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [
            ['text' => 'thinking…', 'thought' => true],
            ['text' => json_encode($verdict)],
        ]]]]])];
    }

    public function testApproveReturnsTheDraftUnchangedAndRecordsTheRun(): void
    {
        $draft  = ['question' => 'When is GSTR-1 due?', 'answer' => 'By the 11th of the next month.'];
        $result = $this->service(self::gemini(['decision' => 'approve', 'reasons' => ['Accurate.'], 'scores' => ['accuracy' => 9], 'final_json' => '{"answer":"changed"}']))
            ->vet('community', $draft, [], 'community:1');

        $this->assertSame(VettingResult::APPROVE, $result->decision);
        $this->assertSame($draft, $result->final, 'approving publishes exactly what was submitted');
        $this->assertSame('gemini-test-flash', $result->model);
        $this->assertSame('approve', $this->recorded[0]['decision']);
        $this->assertSame('community:1', $this->recorded[0]['content_ref']);
    }

    public function testReviseTakesCorrectedFieldsButNeverNewOrReshapedOnes(): void
    {
        $draft  = ['title' => 'Old', 'steps' => [['text' => 'a']], 'word_count' => 10];
        $result = $this->service(self::gemini(['decision' => 'revise', 'reasons' => ['Fixed title.'], 'scores' => [], 'final_json' => json_encode([
            'title' => 'New', 'steps' => 'not-an-array', 'word_count' => 12, 'injected' => 'x',
        ])]))->vet('knowledge_base', $draft);

        $this->assertSame(VettingResult::REVISE, $result->decision);
        $this->assertSame(['title' => 'New', 'steps' => [['text' => 'a']], 'word_count' => 12], $result->final);
    }

    public function testRejectCarriesReasonsAndNothingToPublish(): void
    {
        $result = $this->service(self::gemini(['decision' => 'reject', 'reasons' => ['Abusive question.'], 'scores' => [], 'final_json' => '{}']))
            ->vet('community', ['question' => 'x', 'answer' => 'y']);

        $this->assertTrue($result->rejected());
        $this->assertSame([], $result->final);
        $this->assertSame(['Abusive question.'], $result->reasons);
    }

    public function testMalformedOutputIsRetriedOnceThenFailsClosed(): void
    {
        $bad = ['status' => 200, 'body' => json_encode(['candidates' => [['content' => ['parts' => [['text' => 'not json']]]]]])];
        try {
            $this->service($bad, $bad)->vet('blog', ['title' => 't']);
            $this->fail('expected VettingUnavailableException');
        } catch (VettingUnavailableException) {
            $this->assertCount(1, $this->recorded);
            $this->assertSame('error', $this->recorded[0]['decision']);
        }
    }

    public function testRecoversWhenTheRetrySucceeds(): void
    {
        $result = $this->service(['status' => 503, 'body' => '{"error":{"message":"overloaded key=abc"}}'], self::gemini(['decision' => 'approve', 'reasons' => [], 'scores' => [], 'final_json' => '{}']))
            ->vet('blog', ['title' => 't']);
        $this->assertTrue($result->approved());
    }

    public function testNoKeyMeansNoVerdict(): void
    {
        unset($_ENV['AI_GEMINI_API_KEY']);
        ReachConfig::flush();
        $this->expectException(VettingUnavailableException::class);
        $this->service()->vet('blog', ['title' => 't']);
    }

    public function testPerTypeModelOverridesTheDefault(): void
    {
        $this->assertSame('gemini-test-flash', ReachConfig::vettingModel('release_note'));
    }

    public function testRequestAsksForStructuredJson(): void
    {
        $body = (new ContentVettingService())->requestBody('RUBRIC', ['a' => 'b'], ['today' => '2026-10-17']);
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame(['approve', 'revise', 'reject'], $body['generationConfig']['responseSchema']['properties']['decision']['enum']);
        $this->assertSame('RUBRIC', $body['systemInstruction']['parts'][0]['text']);
    }

    public function testRubricsPinTheKeyEditorialRules(): void
    {
        $community = Rubrics::for('community');
        $this->assertStringContainsString('AI-assisted', $community);
        $this->assertMatchesRegularExpression('/confirm\s+the specifics with their CA/', $community);
        $this->assertStringContainsString('very', $community);

        $release = Rubrics::for('release_note');
        $this->assertStringContainsString('Never reveal security details', $release);
        $this->assertStringContainsString('commit hash', $release);

        // The inbox screen rejects only for the reasons that close a question for good.
        $screen = Rubrics::for('community_question');
        $this->assertStringContainsString('There is', $screen);
        $this->assertStringContainsString('never because the', $screen);
        $this->assertStringContainsString('personal data', $screen);

        foreach (Rubrics::TYPES as $type) {
            $this->assertStringContainsString('Nothing you approve is reviewed again', Rubrics::for($type));
        }
        $this->expectException(\InvalidArgumentException::class);
        Rubrics::for('unknown');
    }
}
