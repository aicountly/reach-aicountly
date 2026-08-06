<?php

namespace Tests\Unit\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use PHPUnit\Framework\TestCase;

/**
 * CI4's argv parser stores `--queue=a,b` as an option *named* "queue=a,b" with
 * a null value, so `CLI::getOption('queue')` returns null for the exact form
 * the cron guide documents. In production that made
 *
 *     php spark reach:work --queue=default,blog,publishing,community --once --limit=20
 *
 * start with queues ["default"] and limit 0 — blog, publishing and community
 * jobs were never drained by the every-minute cron.
 */
final class ParsesSparkOptionsTest extends TestCase
{
    private object $subject;

    /** @var array<int,string> */
    private array $originalArgv;

    protected function setUp(): void
    {
        $this->originalArgv = $_SERVER['argv'] ?? [];
        $this->subject = new class () {
            use ParsesSparkOptions {
                sparkOption as public;
                sparkFlag as public;
            }
        };
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->originalArgv;
    }

    /** @param list<string> $argv */
    private function withArgv(array $argv): void
    {
        $_SERVER['argv'] = array_merge(['spark', 'reach:work'], $argv);
    }

    public function testEqualsFormIsParsed(): void
    {
        $this->withArgv(['--queue=default,blog,publishing,community', '--once', '--limit=20']);

        $this->assertSame('default,blog,publishing,community', $this->subject->sparkOption('queue', [], 'default'));
        $this->assertSame('20', $this->subject->sparkOption('limit', [], '0'));
        $this->assertTrue($this->subject->sparkFlag('once', []));
    }

    public function testSpaceSeparatedFormIsParsed(): void
    {
        $this->withArgv(['--queue', 'default,blog', '--limit', '5']);

        $this->assertSame('default,blog', $this->subject->sparkOption('queue', [], 'default'));
        $this->assertSame('5', $this->subject->sparkOption('limit', [], '0'));
    }

    public function testDefaultsApplyWhenTheOptionIsAbsent(): void
    {
        $this->withArgv(['--once']);

        $this->assertSame('default', $this->subject->sparkOption('queue', [], 'default'));
        $this->assertSame('0', $this->subject->sparkOption('limit', [], '0'));
        $this->assertFalse($this->subject->sparkFlag('dry-run', []));
    }

    public function testHyphenatedOptionNamesAreParsed(): void
    {
        $this->withArgv(['--worker-id=cron2', '--max-generation-attempts=3']);

        $this->assertSame('cron2', $this->subject->sparkOption('worker-id', []));
        $this->assertSame('3', $this->subject->sparkOption('max-generation-attempts', [], '3'));
    }

    public function testValuelessFlagDoesNotSwallowTheNextOption(): void
    {
        $this->withArgv(['--force', '--limit=25']);

        $this->assertTrue($this->subject->sparkFlag('force', []));
        $this->assertSame('25', $this->subject->sparkOption('limit', [], '1'));
    }

    public function testExplicitParamsWin(): void
    {
        $this->withArgv(['--limit=20']);

        $this->assertSame('7', $this->subject->sparkOption('limit', ['limit' => '7'], '0'));
    }
}
