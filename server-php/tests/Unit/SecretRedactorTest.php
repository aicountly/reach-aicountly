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
