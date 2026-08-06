<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * CodeIgniter's own CLI parser understands `--opt value` but not `--opt=value`:
 * the =-form leaves CLI::getOption() null and the command silently falls back
 * to its default.
 *
 * That is not academic. Every documented cron line uses the =-form, so
 *   reach:work --queue=default,blog,publishing,community --once --limit=20
 * ran as `--queue default --once --limit 0` — one job per minute off the
 * default queue, while blog, publishing and community jobs sat queued with
 * nothing reporting a problem.
 *
 * ParsesSparkOptions reads argv directly and accepts both spellings. These
 * tests pin the commands that cron actually invokes.
 */
final class CommandOptionParsingTest extends CIUnitTestCase
{
    /** Commands invoked from cron or runbooks with =-style options. */
    private const MUST_PARSE_EQUALS_FORM = [
        'ReachWork.php',
        'ReachSchedule.php',
        'ReachSearchConsole.php',
        'ReachBlogUrlDrift.php',
        'ReachBlogRepublish.php',
        'ReachBlogAdvance.php',
    ];

    /**
     * @dataProvider cronInvokedCommandProvider
     */
    public function testCronInvokedCommandsParseTheEqualsForm(string $file): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/' . $file);

        $this->assertStringContainsString(
            'ParsesSparkOptions',
            $source,
            $file . ' takes options from cron or a runbook using --opt=value, which '
                . 'CLI::getOption() does not see. It must use ParsesSparkOptions.',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cronInvokedCommandProvider(): array
    {
        $cases = [];
        foreach (self::MUST_PARSE_EQUALS_FORM as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * The queue list is the one that silently cost the most: a worker that
     * drops it processes the default queue only, and publishing jobs never run.
     */
    public function testWorkerQueueOptionIsNotReadThroughTheBrokenPath(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachWork.php');

        $this->assertStringNotContainsString("CLI::getOption('queue')", $source);
        $this->assertStringContainsString("\$this->sparkOption('queue', \$params, 'default')", $source);
    }

    public function testWorkerLimitIsNotReadThroughTheBrokenPath(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Commands/ReachWork.php');

        $this->assertStringNotContainsString("CLI::getOption('limit')", $source);
        $this->assertStringContainsString("\$this->sparkOption('limit', \$params, '0')", $source);
    }
}
