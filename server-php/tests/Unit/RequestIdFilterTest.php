<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\RequestIdFilter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure-logic filter tests. We reach into RequestIdFilter's private helpers
 * via reflection so the tests don't need to bootstrap CI4's HTTP stack
 * (which pulls in ext-intl on the local dev box).
 *
 * Environment isolation:
 *   Every test that mutates REACH_REQUEST_ID_PREFIX captures the original
 *   value of getenv(), $_ENV, and $_SERVER before the mutation and restores
 *   all three in a finally block.
 *
 * Root-cause note:
 *   CI4's env() helper uses the null-coalescing operator to check $_ENV
 *   before getenv(). When CI4's DotEnv bootstrap loads .env.example it
 *   stores '' in $_ENV['REACH_REQUEST_ID_PREFIX'] via putenv(). An empty
 *   string satisfies ?? without falling through to getenv() or $_SERVER,
 *   so a test-supplied putenv() value was silently ignored on CI.
 *   The filter now uses its own resolvePrefix() method that checks
 *   getenv() first, making putenv() overrides reliable everywhere.
 *
 * @internal
 */
final class RequestIdFilterTest extends TestCase
{
    private const ENV_KEY = 'REACH_REQUEST_ID_PREFIX';

    // -------------------------------------------------------------------------
    // UUID shape
    // -------------------------------------------------------------------------

    public function testGenerateProducesUuidV4Shape(): void
    {
        $this->withPrefix('', function (RequestIdFilter $filter): void {
            $id = $this->invokePrivate($filter, 'generate');
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'Unprefixed request ID must be a valid UUIDv4'
            );
        });
    }

    // -------------------------------------------------------------------------
    // Prefix honoured (Failure 3 regression)
    // -------------------------------------------------------------------------

    /**
     * This is the test that failed in CI.
     *
     * On Linux GitHub Actions, CI4's DotEnv bootstrap populates
     * $_ENV['REACH_REQUEST_ID_PREFIX'] with '' from .env.example.
     * The old code used CI4's env() which checked $_ENV first via ??,
     * so the test's putenv() override was silently ignored because ''
     * satisfies ?? without triggering the fallback.
     *
     * The fix changes resolvePrefix() to call getenv() first, which is
     * updated synchronously by putenv() on all platforms.
     */
    public function testGenerateHonoursPrefixEnv(): void
    {
        $this->withPrefix('reach-test', function (RequestIdFilter $filter): void {
            $id = $this->invokePrivate($filter, 'generate');
            $this->assertStringStartsWith(
                'reach-test:',
                $id,
                'Generated request ID must start with prefix + colon separator'
            );
        });
    }

    // -------------------------------------------------------------------------
    // Blank / missing prefix produces an unprefixed UUID
    // -------------------------------------------------------------------------

    public function testBlankPrefixProducesUnprefixedId(): void
    {
        $this->withPrefix('', function (RequestIdFilter $filter): void {
            $id = $this->invokePrivate($filter, 'generate');
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'Blank prefix must produce a bare UUIDv4'
            );
        });
    }

    public function testWhitespacePrefixProducesUnprefixedId(): void
    {
        $this->withPrefix('   ', function (RequestIdFilter $filter): void {
            $id = $this->invokePrivate($filter, 'generate');
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'Whitespace-only prefix must produce a bare UUIDv4'
            );
        });
    }

    public function testMissingPrefixProducesUnprefixedId(): void
    {
        // Remove the key entirely so getenv() returns false
        $prevGetenv    = getenv(self::ENV_KEY);
        $prevServerVal = $_SERVER[self::ENV_KEY] ?? null;
        $prevEnvVal    = $_ENV[self::ENV_KEY]    ?? null;

        putenv(self::ENV_KEY);           // remove from process environment
        unset($_SERVER[self::ENV_KEY]);
        unset($_ENV[self::ENV_KEY]);

        try {
            $filter = new RequestIdFilter();
            $id     = $this->invokePrivate($filter, 'generate');
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'Absent prefix must produce a bare UUIDv4'
            );
        } finally {
            $this->restoreEnvKey($prevGetenv, $prevServerVal, $prevEnvVal);
        }
    }

    // -------------------------------------------------------------------------
    // Suffix is a valid UUID
    // -------------------------------------------------------------------------

    public function testPrefixedIdSuffixIsValidUuid(): void
    {
        $this->withPrefix('svc', function (RequestIdFilter $filter): void {
            $id     = $this->invokePrivate($filter, 'generate');
            $suffix = substr($id, strlen('svc:'));
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $suffix,
                'The UUID portion after the colon separator must be a valid UUIDv4'
            );
        });
    }

    // -------------------------------------------------------------------------
    // Repeated calls do not reuse stale prefix state
    // -------------------------------------------------------------------------

    public function testRepeatedCallsHonourCurrentPrefix(): void
    {
        // Call once with prefix
        $this->withPrefix('alpha', function (RequestIdFilter $filterA): void {
            $idA = $this->invokePrivate($filterA, 'generate');
            $this->assertStringStartsWith('alpha:', $idA);
        });

        // Call again with a different prefix — must not inherit the previous one
        $this->withPrefix('beta', function (RequestIdFilter $filterB): void {
            $idB = $this->invokePrivate($filterB, 'generate');
            $this->assertStringStartsWith('beta:', $idB);
            $this->assertStringNotContainsString('alpha', $idB);
        });
    }

    public function testRepeatedCallsWithNoPrefix(): void
    {
        $this->withPrefix('', function (RequestIdFilter $filter1): void {
            $id1 = $this->invokePrivate($filter1, 'generate');

            $this->withPrefix('', function (RequestIdFilter $filter2) use ($id1): void {
                $id2 = $this->invokePrivate($filter2, 'generate');
                // Each call generates a distinct ID
                $this->assertNotSame($id1, $id2, 'Each generate() call must produce a unique ID');
            });
        });
    }

    // -------------------------------------------------------------------------
    // Environment state is restored after the test
    // -------------------------------------------------------------------------

    public function testEnvironmentStateIsRestoredAfterPrefixTest(): void
    {
        $keyBefore = getenv(self::ENV_KEY);
        $envBefore = $_ENV[self::ENV_KEY]    ?? null;
        $srvBefore = $_SERVER[self::ENV_KEY] ?? null;

        $this->withPrefix('isolation-check', function (RequestIdFilter $filter): void {
            // Just exercise the generate call — we only care about cleanup
            $this->invokePrivate($filter, 'generate');
        });

        // After withPrefix(), all three sources must be back to their prior values
        $this->assertSame($keyBefore, getenv(self::ENV_KEY),
            'getenv() must be restored to original value after test');
        $this->assertSame($envBefore, $_ENV[self::ENV_KEY] ?? null,
            '$_ENV must be restored to original value after test');
        $this->assertSame($srvBefore, $_SERVER[self::ENV_KEY] ?? null,
            '$_SERVER must be restored to original value after test');
    }

    // -------------------------------------------------------------------------
    // Pattern constant
    // -------------------------------------------------------------------------

    public function testPatternAcceptsWellFormedIds(): void
    {
        $ref  = new ReflectionClass(RequestIdFilter::class);
        $prop = $ref->getReflectionConstant('PATTERN');
        $this->assertNotFalse($prop);
        $pattern = $prop->getValue();

        $this->assertSame(1, preg_match($pattern, 'reach-test-1234-abcd'));
        $this->assertSame(1, preg_match($pattern, 'aBcDeFgHiJ'));
        $this->assertSame(0, preg_match($pattern, 'has spaces'));
        $this->assertSame(0, preg_match($pattern, 'short'));
        $this->assertSame(0, preg_match($pattern, str_repeat('a', 65)));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Set REACH_REQUEST_ID_PREFIX to $prefix for the duration of $callback,
     * then restore getenv(), $_ENV, and $_SERVER to their prior values.
     *
     * Setting all three sources ensures the test works regardless of which
     * source the resolver prefers on the current platform / PHP configuration.
     */
    private function withPrefix(string $prefix, callable $callback): void
    {
        $key           = self::ENV_KEY;
        $prevGetenv    = getenv($key);
        $prevEnvVal    = $_ENV[$key]    ?? null;
        $prevServerVal = $_SERVER[$key] ?? null;

        if ($prefix === '') {
            // Set to empty so resolvePrefix() returns '' on all three sources
            putenv("{$key}=");
            $_ENV[$key]    = '';
            $_SERVER[$key] = '';
        } else {
            putenv("{$key}={$prefix}");
            $_ENV[$key]    = $prefix;
            $_SERVER[$key] = $prefix;
        }

        try {
            $callback(new RequestIdFilter());
        } finally {
            $this->restoreEnvKey($prevGetenv, $prevServerVal, $prevEnvVal);
        }
    }

    /**
     * Restore the three environment sources to their captured state.
     *
     * @param string|false $prevGetenv  Return value of getenv() before mutation
     * @param string|null  $prevServer  $_SERVER value (null = key was absent)
     * @param string|null  $prevEnv     $_ENV value (null = key was absent)
     */
    private function restoreEnvKey(
        string|false $prevGetenv,
        string|null  $prevServer,
        string|null  $prevEnv,
    ): void {
        $key = self::ENV_KEY;

        // Restore process environment
        if ($prevGetenv === false) {
            putenv($key);          // remove from process env entirely
        } else {
            putenv("{$key}={$prevGetenv}");
        }

        // Restore $_SERVER
        if ($prevServer === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $prevServer;
        }

        // Restore $_ENV
        if ($prevEnv === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $prevEnv;
        }
    }

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
