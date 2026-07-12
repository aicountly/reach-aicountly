<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base class for tests that touch the isolated test database.
 *
 * Behaviour:
 *   - Runs all Reach migrations against the `tests` DB group.
 *   - Refreshes schema between tests (fast rollback via transactions
 *     where the driver supports it).
 *   - Self-skips the entire class when TEST_DB / phpunit env is not
 *     configured, so contributors without a local Postgres do not
 *     experience false failures — and so the production DB can never
 *     be reached from a test run.
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';
    protected $DBGroup   = 'tests';

    protected function setUp(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        parent::setUp();
    }

    protected static function hasTestDatabase(): bool
    {
        return self::missingTestDatabaseReason() === null;
    }

    protected static function missingTestDatabaseReason(): ?string
    {
        $name = getenv('database.tests.database');
        if ($name === false || $name === '') {
            $name = getenv('TEST_DB_NAME') ?: '';
        }

        if ($name !== '') {
            return null;
        }

        return 'Isolated PostgreSQL test database unavailable: set database.tests.database or TEST_DB_NAME (and matching host/user/password via database.tests.* or TEST_DB_* env keys).';
    }
}
