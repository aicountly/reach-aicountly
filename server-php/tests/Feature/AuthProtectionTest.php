<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

/**
 * B2 test #1 — Console SSO / JWT protection on authenticated routes.
 * Verifies unauthenticated callers cannot reach the /v1/me endpoint or
 * any route inside the JWT-gated group.
 */
final class AuthProtectionTest extends ApiTestCase
{
    public function testMeWithoutTokenReturns401(): void
    {
        $response = $this->call('GET', 'v1/me');
        $this->assertSame(401, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['ok'] ?? true);
    }

    public function testMeWithValidTokenReturnsProfile(): void
    {
        $headers = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/me');
        $this->assertSame(200, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame('super_admin@test.aicountly.org', $body['data']['email']);
        $this->assertSame('super_admin', $body['data']['role']);
    }

    public function testLegacyLoginRouteIsDisabled(): void
    {
        $response = $this->call('POST', 'v1/auth/login', ['email' => 'x@y.z', 'password' => 'irrelevant']);
        // Backend returns 403 with a Console SSO message.
        $this->assertSame(403, $response->response()->getStatusCode());
    }
}

