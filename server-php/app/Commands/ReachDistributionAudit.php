<?php

namespace App\Commands;

use App\Libraries\Distribution\DistributionAuditService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:distribution-audit [--fix] [--json] [--limit=50]`
 *
 * Lists distribution rows that claim a delivery no real provider made (mock
 * `mock-post-…` ids, mock-accepted attempts, campaigns `sent` by a mock) plus
 * skipped and unverifiable gateway sends. Dry run by default; --fix marks the
 * mock-delivered rows failed with a reason (skipped/unverifiable rows are only
 * listed).
 */
class ReachDistributionAudit extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:distribution-audit';
    protected $description = 'List (and with --fix, mark failed) distribution rows falsely recorded as sent/posted by mock providers.';
    protected $usage       = 'reach:distribution-audit [--fix] [--json] [--limit=50]';
    protected $options     = [
        '--fix'   => 'Mark mock-delivered rows failed with a reason (default: dry run).',
        '--json'  => 'Print the findings as JSON.',
        '--limit' => 'Rows shown per kind (default 50).',
    ];

    public function run(array $params)
    {
        $fix   = $this->sparkFlag('fix', $params);
        $json  = $this->sparkFlag('json', $params);
        $limit = max(1, (int) ($this->sparkOption('limit', $params, '50') ?? '50'));

        $service  = new DistributionAuditService();
        $findings = $service->findings($limit);
        $fixable  = DistributionAuditService::fixableKinds();

        if ($json) {
            CLI::write(json_encode(['mode' => $fix ? 'fix' : 'dry-run', 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            CLI::write('Distribution honesty audit (' . ($fix ? 'FIX' : 'dry run') . ')', 'yellow');
            foreach ($findings as $kind => $rows) {
                $tag = in_array($kind, $fixable, true) ? 'fixable' : 'info';
                CLI::write(sprintf('  %-28s %5d  [%s]', $kind, count($rows), $tag), count($rows) > 0 && $tag === 'fixable' ? 'red' : 'white');
                foreach (array_slice($rows, 0, $limit) as $row) {
                    CLI::write('      ' . json_encode($row, JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $total = array_sum(array_map(static fn ($k) => count($findings[$k]), $fixable));
        if (! $fix) {
            if (! $json) {
                CLI::write($total > 0 ? "{$total} row(s) would be marked failed. Re-run with --fix." : 'Nothing to fix.', $total > 0 ? 'yellow' : 'green');
            }
            return $total > 0 ? 1 : 0;
        }

        $changed = $service->fix();
        CLI::write('Marked failed: ' . json_encode($changed), 'green');

        return 0;
    }
}
