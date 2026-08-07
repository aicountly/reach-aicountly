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
    /**
     * ReachMigrate is the deliberate exception: it declares a single-dash
     * option (`-g group`), which CodeIgniter's parser handles correctly and
     * sparkOption — which scans argv for `--name` — would not. Converting it
     * would break the one command that currently works.
     */
    private const SINGLE_DASH_OPTIONS = ['ReachMigrate.php'];

    /**
     * Every command that reads a --option must go through the trait. Asserted
     * over the whole directory rather than a hand-kept list, so a new command
     * cannot quietly reintroduce the bug by not being added to it.
     */
    public function testEveryCommandReadingOptionsParsesTheEqualsForm(): void
    {
        $offenders = [];

        foreach (glob(APPPATH . 'Commands/*.php') ?: [] as $path) {
            $file = basename($path);
            if (in_array($file, self::SINGLE_DASH_OPTIONS, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (str_contains($source, 'CLI::getOption') && !str_contains($source, 'ParsesSparkOptions')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These read options through CLI::getOption() alone, which does not see --opt=value:\n  "
                . implode("\n  ", $offenders),
        );
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
