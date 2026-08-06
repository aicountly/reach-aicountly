<?php

namespace Tests\Unit;

use App\Libraries\SecretRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests — deliberately skip CIUnitTestCase to keep runnable in
 * environments without ext-intl (the framework bootstrap depends on Locale).
 *
 * @internal
 */
final class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SecretRedactor();
    }

    public function testRedactsKeysMatchingSensitiveNames(): void
    {
        $input = [
            'name'  => 'Rahul',
            'openai_api_key' => 'sk-abc123',
            'nested' => ['token' => 'xyz123', 'ok' => true],
        ];
        $out = $this->redactor->redact($input);
        $this->assertSame('Rahul', $out['name']);
        $this->assertSame('[REDACTED]', $out['openai_api_key']);
        $this->assertSame('[REDACTED]', $out['nested']['token']);
        $this->assertTrue($out['nested']['ok']);
    }

    public function testRedactsBearerStrings(): void
    {
        $out = $this->redactor->redact('Bearer abcdef1234');
        $this->assertSame('[REDACTED]', $out);
    }

    public function testRedactsJwtLikeStrings(): void
    {
        $jwt = 'eyJhbGciOi.eyJzdWIiOi.SflKxwRJSMeKK';
        $out = $this->redactor->redact($jwt);
        $this->assertSame('[REDACTED]', $out);
    }

    public function testRedactsLongOpaqueTokens(): void
    {
        $token = str_repeat('A', 40);
        $out   = $this->redactor->redact($token);
        $this->assertSame('[REDACTED]', $out);
    }

    public function testPreservesShortInnocuousStrings(): void
    {
        $out = $this->redactor->redact('hello world');
        $this->assertSame('hello world', $out);
    }

    /**
     * A UUID is 36 characters, space-free, and built from characters the
     * long-opaque-token heuristic accepts — so every UUID in every job payload
     * was being stored as "[REDACTED]". A deployment retry job whose
     * deployment_uuid arrived redacted could never find its deployment and
     * retried until it dead-lettered.
     */
    public function testPreservesUuids(): void
    {
        $uuids = [
            '871f4e3b-f3a6-4aec-a966-387bea6d9b44',
            '00000000-0000-0000-0000-000000000000',
            '164AF6F9-9302-42BF-BAE2-0C4CFB120B98',
        ];

        foreach ($uuids as $uuid) {
            $this->assertSame($uuid, $this->redactor->redact($uuid));
            $this->assertSame(
                $uuid,
                $this->redactor->redact(['deployment_uuid' => $uuid])['deployment_uuid'],
            );
        }
    }

    /**
     * Machine identifiers — error codes, slugs, job type keys — are not
     * credentials. "community_answer_generation_failed" is 34 characters, so
     * the failure reason on every failed generation was logged as "[REDACTED]".
     */
    public function testPreservesSnakeAndKebabCaseIdentifiers(): void
    {
        $identifiers = [
            'community_answer_generation_failed',
            'reach-community-official-answer-published',
            'aicountly-compliance-desk',
        ];

        foreach ($identifiers as $identifier) {
            $this->assertSame($identifier, $this->redactor->redact($identifier));
        }
    }

    /**
     * The exemptions above must not open a hole: a real key is an unbroken run
     * of mixed-case alphanumerics and still has to be redacted.
     */
    public function testStillRedactsCredentialShapedStrings(): void
    {
        $secrets = [
            'sk-proj-' . str_repeat('a1B2', 12),
            str_repeat('0123456789abcdef', 4),
            'AIzaSy' . str_repeat('Xy9', 12),
        ];

        foreach ($secrets as $secret) {
            $this->assertSame('[REDACTED]', $this->redactor->redact($secret), "should redact: {$secret}");
        }
    }

    /**
     * 'token' matches token counts as readily as auth tokens; usage metrics
     * were being written to the audit log as "[REDACTED]".
     */
    public function testPreservesTokenCountKeys(): void
    {
        $out = $this->redactor->redact([
            'total_tokens'  => 2279,
            'input_tokens'  => 295,
            'output_tokens' => 3252,
            'api_token'     => 'shouldstillberedacted',
        ]);

        $this->assertSame(2279, $out['total_tokens']);
        $this->assertSame(295, $out['input_tokens']);
        $this->assertSame(3252, $out['output_tokens']);
        $this->assertSame('[REDACTED]', $out['api_token']);
    }

    public function testRedactKeysHelper(): void
    {
        $out = $this->redactor->redactKeys(
            ['a' => 1, 'password' => 'p', 'note' => 'ok'],
            ['password']
        );
        $this->assertSame(1, $out['a']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('ok', $out['note']);
    }

    public function testHandlesDepthLimit(): void
    {
        $deep = ['x' => 1];
        $node = &$deep;
        for ($i = 0; $i < 12; $i++) {
            $node['n'] = ['x' => 1];
            $node      = &$node['n'];
        }
        $out = $this->redactor->redact($deep);
        $this->assertIsArray($out);
    }
}
