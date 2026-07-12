<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\KnowledgeTaxonomyImporter;

/**
 * `php spark reach:import-taxonomy`
 *
 * Idempotent importer that seeds reach_products and reach_product_aliases
 * from SaasProductTaxonomy::products(). Safe to run multiple times.
 *
 * Options:
 *   --dry-run    Print what would be imported without writing to the database.
 */
class ReachImportTaxonomy extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:import-taxonomy';
    protected $description = 'Idempotently import legacy SaaS product taxonomy into the knowledge store.';
    protected $usage       = 'reach:import-taxonomy [--dry-run]';

    public function run(array $params): int
    {
        $dryRun = (bool) (CLI::getOption('dry-run') ?? ($params['dry-run'] ?? false));

        CLI::write('[reach:import-taxonomy] Starting legacy taxonomy import…', 'yellow');

        if ($dryRun) {
            CLI::write('[reach:import-taxonomy] DRY RUN — no database writes.', 'yellow');
            $this->printDryRun();
            return 0;
        }

        $importer = new KnowledgeTaxonomyImporter();
        $result   = $importer->run(null);

        $created = count($result['created']);
        $skipped = count($result['skipped']);
        $aliases = count($result['aliases']);
        $errors  = count($result['errors']);

        CLI::write("[reach:import-taxonomy] Products created : {$created}", 'green');
        CLI::write("[reach:import-taxonomy] Products skipped : {$skipped}", 'white');
        CLI::write("[reach:import-taxonomy] Aliases processed: {$aliases}", 'white');

        if ($errors > 0) {
            CLI::write("[reach:import-taxonomy] Errors          : {$errors}", 'red');
            foreach ($result['errors'] as $err) {
                $key = $err['slug'] ?? ($err['alias'] ?? 'unknown');
                CLI::write("  ERROR [{$key}]: {$err['message']}", 'red');
            }
        }

        foreach ($result['created'] as $item) {
            CLI::write("  + Created: {$item['slug']} ({$item['name']})", 'green');
        }
        foreach ($result['skipped'] as $item) {
            CLI::write("  ~ Skipped: {$item['slug']} ({$item['reason']})", 'dark_gray');
        }

        CLI::write('[reach:import-taxonomy] Import complete.', $errors > 0 ? 'red' : 'green');
        return $errors > 0 ? 1 : 0;
    }

    private function printDryRun(): void
    {
        $products = \App\Libraries\SaasProductTaxonomy::products();
        $aliases  = \App\Libraries\SaasProductTaxonomy::productAliases();

        CLI::write('Products that would be imported:', 'cyan');
        foreach ($products as $slug => $label) {
            CLI::write("  {$slug} => {$label}", 'white');
        }
        CLI::write('Aliases that would be processed:', 'cyan');
        foreach ($aliases as $alias => $canonical) {
            CLI::write("  {$alias} => {$canonical}", 'white');
        }
    }
}
