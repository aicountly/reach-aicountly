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
        $result = command('reach:blog-migrate-legacy --dry-run --batch-size=1 --report');
        $this->assertSame(0, $result);
    }
}
