<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Libraries\Database\SchemaGuard;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET v1/readiness/migrations?state=applied|pending|orphaned
 *
 * Every App migration file next to CodeIgniter's `migrations` history table:
 * applied (with batch and time), pending (file not run yet) or orphaned (run,
 * but the file is gone). Read-only — the history table is read directly so
 * this never creates it (MigrationRunner::getHistory would).
 */
class MigrationStatusController extends BaseApiController
{
    private const STATES = ['applied', 'pending', 'orphaned'];

    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();

            // The files themselves, named the way MigrationRunner names them
            // (version_ClassName, class in App\Database\Migrations). Read from
            // disk rather than via the runner's locator, which in a web request
            // can come back empty.
            $files = [];
            foreach (glob(APPPATH . 'Database/Migrations/*.php') ?: [] as $path) {
                if (preg_match('/\A(\d{4}[_-]?\d{2}[_-]?\d{2}[_-]?\d{6})_(\w+)\z/', basename($path, '.php'), $m) !== 1) {
                    continue;
                }
                $class = 'App\\Database\\Migrations\\' . $m[2];
                $files[preg_replace('/[^0-9]/', '', $m[1]) . $class] = ['version' => $m[1], 'class' => $class, 'name' => $m[2]];
            }

            $history = [];
            if (SchemaGuard::hasTable($db, 'migrations')) {
                foreach ($db->table('migrations')->where('namespace', 'App')->orderBy('id', 'ASC')->get()->getResultArray() as $h) {
                    $history[preg_replace('/[^0-9]/', '', (string) $h['version']) . $h['class']] = $h;
                }
            }

            $items = [];
            foreach ($files as $uid => $f) {
                $h       = $history[$uid] ?? null;
                $items[] = $f + [
                    'state'      => $h ? 'applied' : 'pending',
                    'batch'      => $h ? (int) $h['batch'] : null,
                    'applied_at' => $h ? date('c', (int) $h['time']) : null,
                ];
            }
            foreach (array_diff_key($history, $files) as $h) {
                $items[] = [
                    'version'    => (string) $h['version'],
                    'class'      => (string) $h['class'],
                    'name'       => substr(strrchr('\\' . $h['class'], '\\'), 1),
                    'state'      => 'orphaned',
                    'batch'      => (int) $h['batch'],
                    'applied_at' => date('c', (int) $h['time']),
                ];
            }
            usort($items, static fn (array $a, array $b): int => strcmp($b['version'], $a['version']));

            $counts = array_count_values(array_column($items, 'state')) + array_fill_keys(self::STATES, 0);
            $last   = $history === [] ? null : end($history);

            $state = (string) $this->request->getGet('state');
            if (in_array($state, self::STATES, true)) {
                $items = array_values(array_filter($items, static fn (array $i): bool => $i['state'] === $state));
            }

            return $this->ok([
                'summary' => [
                    'total'           => count($files),
                    'applied'         => $counts['applied'],
                    'pending'         => $counts['pending'],
                    'orphaned'        => $counts['orphaned'],
                    'last_batch'      => $last ? (int) $last['batch'] : null,
                    'last_applied_at' => $last ? date('c', (int) $last['time']) : null,
                ],
                'items'   => $items,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'MigrationStatusController::index: ' . $e->getMessage());
            return $this->fail('Unable to read migration status', 500);
        }
    }
}
