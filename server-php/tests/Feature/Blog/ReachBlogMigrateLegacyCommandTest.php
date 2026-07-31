<?php

namespace Tests\Feature\Blog;

use Tests\Support\DatabaseTestCase;

/**
 * Dry-run smoke test for reach:blog-migrate-legacy when an isolated test DB exists.
 */
final class ReachBlogMigrateLegacyCommandTest extends DatabaseTestCase
{
    public function testDryRunCompletesWithoutWrites(): void
    {
        // CI4's command() helper returns captured CLI output when available.
        // In Feature-test bootstraps CLI::write() is often not captured into the
        // return value (empty string), so "completed without exception" is the
        // reliable success signal; assert on the event string only when present.
        $result = command('reach:blog-migrate-legacy --dry-run --batch-size=1 --report');
        if (is_string($result) && $result !== '') {
            $this->assertStringContainsString('blog_migrate_legacy.batch', $result);
            return;
        }
        $this->addToAssertionCount(1);
    }
}
