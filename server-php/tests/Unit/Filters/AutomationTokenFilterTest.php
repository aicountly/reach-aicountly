<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Filters\AutomationTokenFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * @covers \App\Filters\AutomationTokenFilter
 */
final class AutomationTokenFilterTest extends CIUnitTestCase
{
    private const TOKEN = 'routine-token-0123456789abcdef0123456789';
    private ?string $backup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = $_ENV['REACH_AUTOMATION_TOKEN'] ?? null;
        $_ENV['REACH_AUTOMATION_TOKEN'] = self::TOKEN;
        putenv('REACH_AUTOMATION_TOKEN=' . self::TOKEN);
    }

    protected function tearDown(): void
    {
        if ($this->backup === null) {
            unset($_ENV['REACH_AUTOMATION_TOKEN']);
            putenv('REACH_AUTOMATION_TOKEN');
        } else {
            $_ENV['REACH_AUTOMATION_TOKEN'] = $this->backup;
            putenv('REACH_AUTOMATION_TOKEN=' . $this->backup);
        }
        parent::tearDown();
    }

    private function request(array $headers): IncomingRequest
    {
        $config = new App();
        $req    = new IncomingRequest($config, new SiteURI($config, 'api/v1/automation/content-base'), null, new UserAgent());
        foreach ($headers as $name => $value) {
            $req->setHeader($name, $value);
        }
        return $req;
    }

    private function statusFor(array $headers): ?int
    {
        $res = (new AutomationTokenFilter())->before($this->request($headers));
        return $res === null ? null : $res->getStatusCode();
    }

    public function testAcceptsTheCustomHeader(): void
    {
        $this->assertNull($this->statusFor(['X-Automation-Token' => self::TOKEN]));
    }

    public function testAcceptsABearerToken(): void
    {
        $this->assertNull($this->statusFor(['Authorization' => 'Bearer ' . self::TOKEN]));
        $this->assertNull($this->statusFor(['Authorization' => 'bearer ' . self::TOKEN]));
    }

    public function testRejectsWrongOrMissingTokens(): void
    {
        $this->assertSame(401, $this->statusFor([]));
        $this->assertSame(401, $this->statusFor(['Authorization' => 'Bearer nope']));
        $this->assertSame(401, $this->statusFor(['Authorization' => 'Basic ' . base64_encode('a:' . self::TOKEN)]));
        $this->assertSame(401, $this->statusFor(['X-Automation-Token' => 'nope', 'Authorization' => 'Bearer ' . self::TOKEN]), 'an explicit wrong custom header is not rescued by the bearer');
    }

    public function testFailsClosedWhenTheServerHasNoToken(): void
    {
        $_ENV['REACH_AUTOMATION_TOKEN'] = 'short';
        putenv('REACH_AUTOMATION_TOKEN=short');
        $this->assertSame(503, $this->statusFor(['Authorization' => 'Bearer short']));
    }
}
