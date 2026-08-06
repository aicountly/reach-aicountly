<?php

namespace Tests\Feature\Support;

use App\Libraries\Database\SchemaGuard;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * The stale-schema-cache bug, pinned.
 *
 * CodeIgniter answers tableExists() from a listTables() snapshot taken on
 * first use and never invalidated by raw DDL, and fieldExists() from an
 * equivalent per-table field cache. A process that outlives a migration
 * therefore reports live tables and columns as missing — and because every
 * guard in this codebase reads "missing" as "no data", a populated table
 * renders as an empty screen with no error anywhere.
 *
 * Each test below warms the cache first, exactly as a long-running worker
 * would, then changes the schema underneath it.
 */
final class SchemaGuardTest extends DatabaseTestCase
{
    private const TABLE = 'reach_schema_guard_probe';

    protected function setUp(): void
    {
        parent::setUp();
        SchemaGuard::reset();
        Database::connect()->query('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function tearDown(): void
    {
        Database::connect()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        SchemaGuard::reset();
        parent::tearDown();
    }

    public function testCodeIgnitersCachedCheckIsStaleAfterRawDdl(): void
    {
        $db = Database::connect();

        // Warm the snapshot, as any earlier query in the process would.
        $db->tableExists('reach_content_items');

        $db->query('CREATE TABLE ' . self::TABLE . ' (id BIGSERIAL PRIMARY KEY)');

        // This is the bug, asserted rather than described: the table is there,
        // and the framework says it is not.
        $this->assertFalse(
            $db->tableExists(self::TABLE),
            'If this starts passing, CodeIgniter invalidates its table cache and SchemaGuard can be simplified',
        );
    }

    public function testSchemaGuardSeesATableCreatedAfterTheCacheWarmed(): void
    {
        $db = Database::connect();
        $db->tableExists('reach_content_items');

        $db->query('CREATE TABLE ' . self::TABLE . ' (id BIGSERIAL PRIMARY KEY)');

        $this->assertTrue(SchemaGuard::hasTable($db, self::TABLE));
    }

    public function testSchemaGuardStillReportsMissingTablesAsMissing(): void
    {
        $this->assertFalse(SchemaGuard::hasTable(Database::connect(), 'reach_no_such_table_here'));
    }

    public function testANegativeAnswerIsNotMemoised(): void
    {
        $db = Database::connect();

        // Ask before the table exists — the answer must not stick.
        $this->assertFalse(SchemaGuard::hasTable($db, self::TABLE));

        $db->query('CREATE TABLE ' . self::TABLE . ' (id BIGSERIAL PRIMARY KEY)');

        $this->assertTrue(SchemaGuard::hasTable($db, self::TABLE), 'A missing table that appears must be picked up');
    }

    public function testSchemaGuardSeesAColumnAddedAfterTheFieldCacheWarmed(): void
    {
        $db = Database::connect();
        $db->query('CREATE TABLE ' . self::TABLE . ' (id BIGSERIAL PRIMARY KEY)');

        // Warm the per-table field list, then add a column behind it.
        $this->assertTrue(SchemaGuard::hasColumn($db, self::TABLE, 'id'));
        $this->assertFalse(SchemaGuard::hasColumn($db, self::TABLE, 'added_later'));

        $db->query('ALTER TABLE ' . self::TABLE . ' ADD COLUMN added_later VARCHAR(32)');

        $this->assertTrue(
            SchemaGuard::hasColumn($db, self::TABLE, 'added_later'),
            'A column added by a later migration must not read as missing',
        );
    }

    public function testColumnLookupOnAMissingTableIsFalseNotAnError(): void
    {
        $this->assertFalse(SchemaGuard::hasColumn(Database::connect(), 'reach_no_such_table_here', 'id'));
    }
}
