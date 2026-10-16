<?php

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Services\JsonColumnRepairService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark reach:repair-json [--apply] [--table build_flow_handoffs]
 *
 * Finds JSONB values an older release stored double-encoded (a JSON string
 * wrapping the real document) and, with --apply, rewrites them as the
 * document itself. Without --apply it only reports. Safe to run repeatedly.
 */
class RepairJsonColumns extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:repair-json';
    protected $description = 'Unwrap double-encoded JSONB values (dry run unless --apply).';
    protected $usage       = 'reach:repair-json [--apply] [--table <table>]';
    protected $options     = [
        '--apply' => 'Rewrite the rows. Without it the command only counts them.',
        '--table' => 'Limit to one table.',
    ];

    public function run(array $params)
    {
        $apply = $this->sparkFlag('apply', $params);
        $table = $this->sparkOption('table', $params);
        $table = is_string($table) && $table !== '' ? $table : null;

        $results = (new JsonColumnRepairService())->run($apply, $table);

        $total = 0;
        $fixed = 0;
        foreach ($results as $r) {
            $total += $r['candidates'];
            $fixed += $r['repaired'];
            if ($r['candidates'] === 0) {
                continue;
            }
            CLI::write(sprintf(
                '%s.%s: %d double-encoded%s',
                $r['table'],
                $r['column'],
                $r['candidates'],
                $apply ? sprintf(', %d repaired, %d skipped (changed meanwhile)', $r['repaired'], $r['skipped']) : '',
            ));
        }

        if ($apply) {
            CLI::write("Repaired {$fixed} of {$total} double-encoded JSON values across " . count($results) . ' columns.', 'green');
        } else {
            CLI::write("Dry run: {$total} double-encoded JSON values across " . count($results) . ' columns. Re-run with --apply to repair.', $total > 0 ? 'yellow' : 'green');
        }

        return 0;
    }
}
