<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Content\BrandCasingFixService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * `php spark reach:brand-casing [--dry-run] [--apply] [--table=a,b] [--json]`
 *
 * Re-cases the brand in the content Reach stores (BrandCasing: "AICOUNTLY"
 * → "Aicountly", hostnames lowercase, the exact legal name). See
 * BrandCasingFixService for the tables and what is never touched.
 *
 *   (default)  dry run — counts per table and a few before/after samples
 *   --apply    writes, one transaction per table; nothing is published,
 *              queued or sent to aicountly.com or any social network
 */
class ReachBrandCasing extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:brand-casing';
    protected $description = 'Re-case the brand ("Aicountly") in stored blog, KB, release-note, guide, community, social-draft, content-base and video text. Dry run by default.';
    protected $usage       = 'reach:brand-casing [--dry-run] [--apply] [--table=<t1,t2>] [--json]';
    protected $options     = [
        '--dry-run' => 'Count only (the default).',
        '--apply'   => 'Write the changes (one transaction per table). Never publishes anything.',
        '--table'   => 'Only these tables (comma-separated).',
        '--json'    => 'Print the report as JSON.',
    ];

    public function run(array $params): int
    {
        $apply = $this->sparkFlag('apply', $params) && ! $this->sparkFlag('dry-run', $params);
        $only  = $this->sparkOption('table', $params);
        $only  = $only !== null && trim($only) !== '' ? array_values(array_filter(array_map('trim', explode(',', $only)))) : null;
        if ($only !== null && ($unknown = array_diff($only, array_keys(BrandCasingFixService::TARGETS))) !== []) {
            CLI::error('Unknown table(s): ' . implode(', ', $unknown) . '. Use: ' . implode(', ', array_keys(BrandCasingFixService::TARGETS)));

            return EXIT_ERROR;
        }

        try {
            $report = (new BrandCasingFixService())->run($apply, $only);
        } catch (Throwable $e) {
            CLI::error('[reach:brand-casing] ' . $e->getMessage() . ' — that table was rolled back; tables before it are committed.');

            return EXIT_ERROR;
        }

        if ($this->sparkFlag('json', $params)) {
            CLI::write((string) json_encode(['apply' => $apply, 'tables' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return EXIT_SUCCESS;
        }

        CLI::write('[reach:brand-casing] ' . ($apply ? 'APPLIED' : 'DRY RUN — nothing written'), $apply ? 'green' : 'yellow');
        $rows = [];
        $total = 0;
        foreach ($report as $table => $r) {
            $rows[] = [$table, $r['skipped'] ?? $r['rows_scanned'], $r['rows_changed'], $r['fields_changed'], $r['checksums_carried']];
            $total += $r['rows_changed'];
        }
        CLI::table($rows, ['table', 'rows mentioning aicountly', 'rows to change', 'fields', 'checksums carried']);
        foreach ($report as $table => $r) {
            foreach ($r['samples'] as $s) {
                CLI::write("  {$table}#{$s['id']}.{$s['column']}: {$s['before']}", 'dark_gray');
                CLI::write("  {$table}#{$s['id']}.{$s['column']}→ {$s['after']}", 'white');
            }
        }
        CLI::write("[reach:brand-casing] {$total} row(s) " . ($apply ? 'changed.' : 'would change. Run again with --apply to write them.'));

        return EXIT_SUCCESS;
    }
}
