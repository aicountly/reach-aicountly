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

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (! self::hasTestDatabase()) {
            self::markTestSkipped(
                'Test database not configured. Set TEST_DB_HOST/TEST_DB_NAME/TEST_DB_USER (or the database.tests.* env keys in phpunit.xml) to run feature tests.',
            );
        }
    }

    protected static function hasTestDatabase(): bool
    {
        // phpunit.xml env value beats OS env.
        $name = getenv('database.tests.database');
        if ($name === false || $name === '') {
            $name = getenv('TEST_DB_NAME') ?: '';
        }
        return $name !== '';
    }
}
