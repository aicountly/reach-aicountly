<?php

namespace Tests\Unit;

use App\Commands\ReachMigrate;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * The whole point of reach:migrate is its exit code.
 *
 * CodeIgniter's own `migrate` command returns nothing even when a migration
 * throws, and Boot::runCommand() maps a non-int return to EXIT_SUCCESS — so
 * `spark migrate` exits 0 on failure and the deploy script's `set -e` never
 * fires. These tests pin the behaviour that makes a failed migration fail
 * the deploy.
 */
final class ReachMigrateTest extends CIUnitTestCase
{
    private function commandWithRunner(object $runner): ReachMigrate
    {
        \CodeIgniter\Config\Services::injectMock('migrations', $runner);

        return new ReachMigrate(service('logger'), service('commands'));
    }

    protected function tearDown(): void
    {
        \CodeIgniter\Config\Services::reset();
        parent::tearDown();
    }

    public function testReturnsSuccessWhenMigrationsApply(): void
    {
        $runner = new class {
            public function clearCliMessages(): void {}

            public function getCliMessages(): array
            {
                return ['Running: 2026-08-06-000000_Something'];
            }

            public function latest($group = null): bool
            {
                return true;
            }
        };

        $this->assertSame(EXIT_SUCCESS, $this->commandWithRunner($runner)->run([]));
    }

    public function testReturnsErrorWhenAMigrationThrows(): void
    {
        $runner = new class {
            public function clearCliMessages(): void {}

            public function getCliMessages(): array
            {
                return [];
            }

            public function latest($group = null): bool
            {
                throw new RuntimeException('syntax error at or near "TRUE"');
            }
        };

        // The framework command swallows this and exits 0; ours must not.
        $this->assertSame(EXIT_ERROR, $this->commandWithRunner($runner)->run([]));
    }

    public function testReturnsErrorOnGeneralFaultWithoutAnException(): void
    {
        $runner = new class {
            public function clearCliMessages(): void {}

            public function getCliMessages(): array
            {
                return [];
            }

            public function latest($group = null): bool
            {
                return false;
            }
        };

        // latest() === false is a fault the framework command only warns
        // about — it still prints "Migrations complete." afterwards.
        $this->assertSame(EXIT_ERROR, $this->commandWithRunner($runner)->run([]));
    }
}
