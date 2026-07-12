<?php

namespace Tests\Unit;

use App\Libraries\UrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests — deliberately skip CIUnitTestCase to keep runnable in
 * environments without ext-intl (the framework bootstrap depends on Locale).
 *
 * @internal
 */
final class UrlPolicyTest extends TestCase
{
    private UrlPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UrlPolicy();
    }

    public function testAllowsPublicHttpsUrl(): void
    {
        $result = $this->policy->validate('https://engage.aicountly.org/api/v1/leads', [
            'allowedHosts' => ['engage.aicountly.org'],
        ]);
        $this->assertTrue($result->allowed, $result->reason ?? '');
    }

    public function testRejectsNonHttpScheme(): void
    {
        $result = $this->policy->validate('ftp://example.com/file');
        $this->assertFalse($result->allowed);
        $this->assertSame('scheme', $result->rule);
    }

    public function testRejectsLoopbackLiteral(): void
    {
        $result = $this->policy->validate('http://127.0.0.1:8080/');
        $this->assertFalse($result->allowed);
        $this->assertSame('reserved_ip', $result->rule);
    }

    public function testRejectsAwsMetadataAddress(): void
    {
        $result = $this->policy->validate('http://169.254.169.254/latest/meta-data/');
        $this->assertFalse($result->allowed);
        $this->assertSame('reserved_ip', $result->rule);
    }

    public function testRejectsGcpMetadataHostname(): void
    {
        $result = $this->policy->validate('http://metadata.google.internal/');
        $this->assertFalse($result->allowed);
        $this->assertSame('metadata_host', $result->rule);
    }

    public function testRejectsPrivateRange(): void
    {
        $result = $this->policy->validate('http://10.0.0.1/');
        $this->assertFalse($result->allowed);
        $this->assertSame('reserved_ip', $result->rule);
    }

    public function testRejectsUserinfo(): void
    {
        $result = $this->policy->validate('https://user:pass@example.com/');
        $this->assertFalse($result->allowed);
        $this->assertSame('userinfo', $result->rule);
    }

    public function testRejectsMalformedUrl(): void
    {
        $result = $this->policy->validate('not-a-url');
        $this->assertFalse($result->allowed);
        $this->assertSame('malformed', $result->rule);
    }

    public function testRejectsIpv6Loopback(): void
    {
        $result = $this->policy->validate('http://[::1]/');
        $this->assertFalse($result->allowed);
    }

    public function testAllowListSubdomainWildcard(): void
    {
        $result = $this->policy->validate('https://cdn.aicountly.org/asset.css', [
            'allowedHosts' => ['.aicountly.org'],
        ]);
        $this->assertTrue($result->allowed);
    }
}
