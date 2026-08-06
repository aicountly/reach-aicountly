<?php

namespace App\Libraries\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * Truthful "does this table/column exist?" checks.
 *
 * CodeIgniter answers tableExists() from a listTables() snapshot cached on the
 * connection, and fieldExists() from a per-table field list cached the same
 * way. Neither is invalidated by DDL issued as a raw query, which is how most
 * of this schema is created. So any process that outlives a migration — a
 * queue worker, a test run that refreshes between cases — keeps answering from
 * the snapshot it took first, and reports a live table as missing.
 *
 * That would be survivable if callers treated "missing" as an error, but the
 * codebase treats it as "no data": guards up and down the API return an empty
 * list when the table is absent, so a stale cache renders a populated table as
 * a healthy-looking empty screen. It is the same failure the Blog Command
 * Centre was showing, and it is invisible from the outside.
 *
 * Probes here are uncached. Positive answers are memoised — a table that
 * exists does not stop existing inside one process — while negative answers
 * are always re-probed, so a table that appears mid-process is picked up.
 */
final class SchemaGuard
{
    /** @var array<string, true> tables known to exist, keyed by database.table */
    private static array $tables = [];

    /** @var array<string, true> columns known to exist, keyed by database.table.column */
    private static array $columns = [];

    public static function hasTable(BaseConnection $db, string $table): bool
    {
        $key = self::key($db, $table);
        if (isset(self::$tables[$key])) {
            return true;
        }

        // The `false` is the point of this class: it forces a catalogue probe
        // instead of consulting the connection's listTables() snapshot.
        if ($db->tableExists($table, false)) {
            self::$tables[$key] = true;

            return true;
        }

        return false;
    }

    public static function hasColumn(BaseConnection $db, string $table, string $column): bool
    {
        $key = self::key($db, $table) . '.' . $column;
        if (isset(self::$columns[$key])) {
            return true;
        }

        if (! self::hasTable($db, $table)) {
            return false;
        }

        // fieldExists() has no uncached variant, so drop this table's cached
        // field list and let it be rebuilt.
        unset($db->dataCache['field_names'][$table]);

        if ($db->fieldExists($column, $table)) {
            self::$columns[$key] = true;

            return true;
        }

        return false;
    }

    /**
     * Drop everything memoised. For tests that rebuild the schema between
     * cases; production has no reason to call this.
     */
    public static function reset(): void
    {
        self::$tables  = [];
        self::$columns = [];
    }

    private static function key(BaseConnection $db, string $table): string
    {
        return $db->getDatabase() . '.' . $table;
    }
}
