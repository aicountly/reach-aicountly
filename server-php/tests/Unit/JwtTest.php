<?php

namespace Tests\Unit;

use App\Libraries\Jwt;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JwtTest extends TestCase
{
    private string $secret = 'unit-test-secret-32-chars-of-material-xyz';

    public function testIssueAndDecodeRoundTrip(): void
    {
        $jwt   = new Jwt($this->secret, 60);
        $token = $jwt->issue(42, 'test@example.com', 'reach_admin', 'Test User');
        $decoded = $jwt->decode($token);

        $this->assertIsArray($decoded);
        $this->assertSame('42', $decoded['sub']);
        $this->assertSame('test@example.com', $decoded['email']);
        $this->assertSame('reach_admin', $decoded['role']);
        $this->assertSame('Test User', $decoded['name']);
    }

    public function testDecodeRejectsTamperedToken(): void
    {
        $jwt   = new Jwt($this->secret, 60);
        $token = $jwt->issue(1, 'a@b', 'super_admin');
        $tampered = substr($token, 0, -4) . 'AAAA';
        $this->assertNull($jwt->decode($tampered));
    }

    public function testDecodeReturnsNullForWrongSecret(): void
    {
        $jwt   = new Jwt($this->secret, 60);
        $token = $jwt->issue(1, 'a@b', 'super_admin');
        $other = new Jwt('different-secret-32-characters-long-abcde', 60);
        $this->assertNull($other->decode($token));
    }

    public function testRequires32CharSecret(): void
    {
        $this->expectException(RuntimeException::class);
        new Jwt('too-short', 60);
    }
}
