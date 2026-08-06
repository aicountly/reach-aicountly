<?php

namespace Tests\Feature\Migrations;

use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Migrations must survive being rebuilt more than once in a single process.
 *
 * They did not. AddActorColumns guarded its ALTERs with fieldExists(), which
 * answers from a field list cached on the connection and never invalidated by
 * raw DDL. On a rebuild the cache still described the previous schema, the
 * guard reported the columns as already present, the ADD COLUMN was skipped,
 * and the CHECK constraint that references created_actor_type then ran against
 * a table without it:
 *
 *   ERROR: column "created_actor_type" does not exist
 *
 * DatabaseTestTrait regresses and re-migrates between cases, so this bit on
 * the *second* test of any class that refreshes — it took out whole test
 * classes at a time and made the schema unbuildable from scratch twice over.
 *
 * More than one test method here is therefore load-bearing: the first builds
 * the schema, and every one after it exercises the rebuild that used to fail.
 * Keep at least two.
 */
final class MigrationIdempotencyTest extends DatabaseTestCase
{
    /** Columns AddActorColumns adds, and the table its CHECK constraint hit. */
    private const ACTOR_COLUMNS = ['created_actor_type', 'created_by_service', 'generation_job_id'];

    private function assertActorColumnsPresent(string $table): void
    {
        $db = Database::connect();
        // Read past the very cache that caused the bug.
        $db->dataCache = [];

        foreach (self::ACTOR_COLUMNS as $column) {
            $this->assertTrue(
                $db->fieldExists($column, $table),
                sprintf('%s.%s is missing after a schema rebuild', $table, $column),
            );
        }
    }

    public function testSchemaBuildsOnce(): void
    {
        $this->assertActorColumnsPresent('reach_approvals');
    }

    public function testSchemaRebuildsASecondTime(): void
    {
        $this->assertActorColumnsPresent('reach_approvals');
    }

    public function testSchemaRebuildsAThirdTime(): void
    {
        $this->assertActorColumnsPresent('reach_blog_posts');
    }

    public function testCheckConstraintSurvivesTheRebuild(): void
    {
        $db  = Database::connect();
        $row = $db->query(
            "SELECT 1 FROM pg_constraint WHERE conname = 'reach_approvals_created_actor_type_chk'",
        )->getRowArray();

        $this->assertNotNull($row, 'The CHECK constraint whose ALTER used to fail is missing');
    }
}
