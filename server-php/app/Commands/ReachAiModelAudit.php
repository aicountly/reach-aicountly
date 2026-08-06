<?php

namespace App\Commands;

use App\Libraries\Ai\AiProviderError;
use App\Libraries\Database\SchemaGuard;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * `php spark reach:ai-model-audit` — find models the provider has withdrawn.
 *
 * A model row can be enabled locally long after the provider has removed it:
 * "This model models/gemini-2.0-flash is no longer available". The router and
 * the fallback resolver both filter on `enabled`, so they keep choosing it,
 * and every request routed there burns an attempt before failing.
 *
 * Runtime now routes around this — a withdrawn model classifies as
 * model_retired and reaches the fallback chain instead of failing the request
 * — but the dead row stays selectable and stays first in line. This reports
 * models that have actually produced that error and, with --apply, disables
 * them along with the routes that point at them.
 *
 * Evidence-based: only models the providers have actually rejected are listed.
 * Nothing is disabled on a guess about which model names are current.
 *
 * Dry run by default.
 */
class ReachAiModelAudit extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:ai-model-audit';
    protected $description = 'Report (and optionally disable) AI models the provider has withdrawn.';
    protected $usage       = 'reach:ai-model-audit [--apply]';
    protected $options     = [
        '--apply' => 'Disable the withdrawn models and the routes pointing at them.',
    ];

    public function run(array $params): int
    {
        // CLI::getOption() only sees real argv; command() passes flags in $params.
        $apply = (CLI::getOption('apply') !== null) || array_key_exists('apply', $params);

        $db = Database::connect();

        try {
            foreach (['reach_ai_models', 'reach_ai_generation_runs'] as $table) {
                if (! SchemaGuard::hasTable($db, $table)) {
                    CLI::error($table . ' does not exist.');

                    return EXIT_ERROR;
                }
            }

            $withdrawn = $this->withdrawnModels($db);

            if ($withdrawn === []) {
                CLI::write('No models have reported themselves withdrawn.', 'green');

                return EXIT_SUCCESS;
            }

            CLI::write(sprintf(
                '%s %d model(s) the provider has withdrawn:',
                $apply ? 'Disabling' : 'Found',
                count($withdrawn),
            ), $apply ? 'green' : 'yellow');
            CLI::newLine();
            CLI::table(
                array_map(static fn ($m) => [
                    $m['id'],
                    $m['model_key'],
                    $m['enabled'] ? 'enabled' : 'disabled',
                    $m['routes'],
                    mb_substr((string) $m['last_error'], 0, 70),
                ], $withdrawn),
                ['#', 'model', 'state', 'routes', 'provider said'],
            );

            if (! $apply) {
                CLI::newLine();
                CLI::write('Dry run — nothing written. Re-run with --apply to disable these.', 'yellow');

                return EXIT_SUCCESS;
            }

            $models = 0;
            $routes = 0;

            foreach ($withdrawn as $model) {
                $db->transStart();

                $db->table('reach_ai_models')
                    ->where('id', $model['id'])
                    ->update(['enabled' => false, 'updated_at' => date('Y-m-d H:i:s')]);
                $models++;

                if (SchemaGuard::hasTable($db, 'reach_ai_model_routes')) {
                    $routes += $db->table('reach_ai_model_routes')
                        ->where('primary_model_id', $model['id'])
                        ->where('enabled', true)
                        ->countAllResults(false);

                    $db->table('reach_ai_model_routes')
                        ->where('primary_model_id', $model['id'])
                        ->update(['enabled' => false, 'updated_at' => date('Y-m-d H:i:s')]);
                }

                $db->transComplete();

                if ($db->transStatus() === false) {
                    CLI::error(sprintf('Failed to disable model #%d — rolled back.', $model['id']));

                    return EXIT_ERROR;
                }
            }

            CLI::newLine();
            CLI::write(sprintf('Disabled %d model(s) and %d route(s).', $models, $routes), 'green');
            CLI::write(
                'Check that every task type still has a working route — disabling the last '
                . 'route for a task leaves it with nothing to fall back to.',
                'yellow',
            );

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('reach:ai-model-audit failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * Models with at least one run that failed because the provider withdrew
     * them. Reads both the new category and the raw message, so runs recorded
     * before model_retired existed (classified 'unknown') are still found.
     *
     * @return list<array<string, mixed>>
     */
    private function withdrawnModels($db): array
    {
        $rows = $db->table('reach_ai_generation_runs')
            ->select(
                'reach_ai_models.id, reach_ai_models.model_key, reach_ai_models.enabled, '
                . 'MAX(reach_ai_generation_runs.redacted_error_message) AS last_error',
                false,
            )
            ->join(
                'reach_ai_models',
                'reach_ai_models.id = reach_ai_generation_runs.model_id',
                'inner',
            )
            ->groupStart()
            ->where('reach_ai_generation_runs.error_category', AiProviderError::CATEGORY_MODEL_RETIRED)
            ->orGroupStart()
            ->like('reach_ai_generation_runs.redacted_error_message', 'no longer available')
            ->orLike('reach_ai_generation_runs.redacted_error_message', 'is not found')
            ->orLike('reach_ai_generation_runs.redacted_error_message', 'has been deprecated')
            ->groupEnd()
            ->groupEnd()
            ->groupBy(['reach_ai_models.id', 'reach_ai_models.model_key', 'reach_ai_models.enabled'])
            ->get()
            ->getResultArray();

        $hasRoutes = SchemaGuard::hasTable($db, 'reach_ai_model_routes');

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'model_key'  => (string) $row['model_key'],
                'enabled'    => in_array($row['enabled'], [true, 't', '1', 1], true),
                'last_error' => $row['last_error'],
                'routes'     => $hasRoutes
                    ? (int) $db->table('reach_ai_model_routes')
                        ->where('primary_model_id', (int) $row['id'])
                        ->where('enabled', true)
                        ->countAllResults()
                    : 0,
            ];
        }

        return $out;
    }
}
