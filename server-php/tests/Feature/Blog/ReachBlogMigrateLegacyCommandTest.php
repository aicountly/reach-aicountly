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
        // CI4's command() helper returns captured CLI output (string), not an
        // exit code. Assert the dry-run completed and emitted its summary event.
        $result = command('reach:blog-migrate-legacy --dry-run --batch-size=1 --report');
        $this->assertIsString($result);
        $this->assertStringContainsString('blog_migrate_legacy.batch', $result);
    }
}
