<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * `php spark reach:migrate`
 *
 * Deploy-safe replacement for the framework's `migrate` command.
 *
 * WHY THIS EXISTS: CodeIgniter's Migrate::run() returns nothing on every
 * path — including its `catch (Throwable)` — and Boot::runCommand() maps a
 * non-int return to EXIT_SUCCESS. So `spark migrate` exits 0 even when a
 * migration throws. The cPanel post-deploy script runs under
 * `set -euo pipefail`, which cannot see a zero exit, so a failed migration
 * left the deploy green and the site serving new code against a stale
 * schema. The framework command also prints "Migrations complete." after a
 * general fault, so the log could not be trusted either.
 *
 * This command reports the truth: non-zero exit on any failure, so the
 * deploy step fails loudly and the run is marked failed.
 */
class ReachMigrate extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:migrate';
    protected $description = 'Run all pending migrations, exiting non-zero if any fail.';
    protected $usage       = 'reach:migrate [-g group]';
    protected $options     = ['-g' => 'Set database group'];

    public function run(array $params): int
    {
        $runner = service('migrations');
        $runner->clearCliMessages();

        $group = $params['g'] ?? CLI::getOption('g');

        try {
            // latest() returns false on a general fault without throwing —
            // the framework command only prints a warning and carries on.
            if ($runner->latest($group) === false) {
                $this->writeMessages($runner);
                CLI::error('Migration run reported a general fault — schema may be incomplete.');

                return EXIT_ERROR;
            }
        } catch (Throwable $e) {
            $this->writeMessages($runner);
            CLI::error('Migration failed: ' . $e->getMessage());
            CLI::error('  at ' . $e->getFile() . ':' . $e->getLine());

            return EXIT_ERROR;
        }

        $this->writeMessages($runner);
        CLI::write('Migrations complete.', 'green');

        return EXIT_SUCCESS;
    }

    /** Surface whatever the runner recorded before we report the outcome. */
    private function writeMessages($runner): void
    {
        foreach ($runner->getCliMessages() as $message) {
            CLI::write($message);
        }
    }
}
