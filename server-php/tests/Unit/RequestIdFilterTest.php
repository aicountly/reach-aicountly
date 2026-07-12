<?php

namespace Tests\Unit;

use App\Filters\RequestIdFilter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure-logic filter tests. We reach into RequestIdFilter's private helpers
 * via reflection so the tests don't need to bootstrap CI4's HTTP stack
 * (which pulls in ext-intl on the local dev box).
 *
 * @internal
 */
final class RequestIdFilterTest extends TestCase
{
    public function testGenerateProducesUuidV4Shape(): void
    {
        $filter = new RequestIdFilter();
        $id     = $this->invokePrivate($filter, 'generate');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testGenerateHonoursPrefixEnv(): void
    {
        $_SERVER['REACH_REQUEST_ID_PREFIX'] = 'reach-test';
        $prev = getenv('REACH_REQUEST_ID_PREFIX');
        putenv('REACH_REQUEST_ID_PREFIX=reach-test');

        try {
            $filter = new RequestIdFilter();
            $id     = $this->invokePrivate($filter, 'generate');
            $this->assertStringStartsWith('reach-test:', $id);
        } finally {
            if ($prev === false) {
                putenv('REACH_REQUEST_ID_PREFIX');
                unset($_SERVER['REACH_REQUEST_ID_PREFIX']);
            } else {
                putenv('REACH_REQUEST_ID_PREFIX=' . $prev);
            }
        }
    }

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

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($obj);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
