<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Filters\EngageTokenFilter;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\EnvOverride;

/**
 * X-Engage-Token guard on v1/inbound/engage/*: 503 unset or short, 401 wrong/missing,
 * pass-through on an exact match.
 *
 * @internal
 */
final class EngageTokenFilterTest extends CIUnitTestCase
{
    use EnvOverride;

    private const KEY   = 'REACH_INBOUND_ENGAGE_TOKEN';
    private const TOKEN = 'engage-outcomes-test-token-0123456789abcdef';

    private function run_(?string $configured, ?string $header): mixed
    {
        return $this->withEnv(self::KEY, $configured, static function () use ($header) {
            Services::reset(true);
            $request = Services::incomingrequest(null, false);
            if ($header !== null) {
                $request->setHeader('X-Engage-Token', $header);
            }

            return (new EngageTokenFilter())->before($request);
        });
    }

    public function testUnsetTokenAnswers503(): void
    {
        $res = $this->run_(null, self::TOKEN);
        $this->assertInstanceOf(ResponseInterface::class, $res);
        $this->assertSame(503, $res->getStatusCode());
        $this->assertStringContainsString('REACH_INBOUND_ENGAGE_TOKEN', (string) $res->getBody());
    }

    public function testTokenShorterThan32CharactersIsTreatedAsUnset(): void
    {
        $short = str_repeat('a', 31);
        $res = $this->run_($short, $short);
        $this->assertSame(503, $res->getStatusCode());
        $this->assertNull($this->withEnv(self::KEY, $short, static fn () => EngageTokenFilter::configuredToken()));
    }

    public function testMissingHeaderAnswers401(): void
    {
        $this->assertSame(401, $this->run_(self::TOKEN, null)->getStatusCode());
    }

    public function testWrongTokenAnswers401(): void
    {
        $this->assertSame(401, $this->run_(self::TOKEN, self::TOKEN . 'x')->getStatusCode());
        $this->assertSame(401, $this->run_(self::TOKEN, substr(self::TOKEN, 0, -1))->getStatusCode());
    }

    public function testExactTokenPassesThrough(): void
    {
        $this->assertNull($this->run_(self::TOKEN, self::TOKEN));
    }
}
