<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * `php spark reach:media-reconcile [--fix]`
 *
 * Reports gallery rows whose binary is no longer on disk, and with --fix
 * retires them.
 *
 * Why this exists: deploys rsync the API directory with --delete, and the
 * protect rule for writable/uploads/ matched only the directory entry rather
 * than the tree beneath it, so every cover binary was deleted on each release
 * while its database row survived. The console then showed a gallery full of
 * images that could not be served, and the deficit report counted covers that
 * did not exist — so an operator was told they had rotation-ready covers when
 * every article was heading for a broken hero.
 *
 * Retiring a row is honest bookkeeping, not data loss: the asset is gone
 * either way, and retiring it removes it from assignment and makes the deficit
 * count what actually has to be re-uploaded.
 */
class ReachMediaReconcile extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:media-reconcile';
    protected $description = 'Report (and optionally retire) gallery assets whose file is missing on disk.';
    protected $usage       = 'reach:media-reconcile [--fix]';
    protected $options     = ['--fix' => 'Retire the assets whose file is missing.'];

    public function run(array $params): int
    {
        $fix = array_key_exists('fix', $params) || in_array('--fix', $params, true);
        $db  = Database::connect();

        $rows = $db->table('reach_media_gallery_assets')
            ->select('id, asset_uuid, kind, status, file_path')
            ->where('status', 'active')
            ->get()->getResultArray();

        $missing = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! is_file((string) $row['file_path'])
        ));

        foreach ($missing as $row) {
            CLI::write(sprintf('missing: id=%s uuid=%s kind=%s path=%s', $row['id'], $row['asset_uuid'], $row['kind'], $row['file_path']));
        }

        $retired = 0;
        if ($fix && $missing !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $missing);
            $db->table('reach_media_gallery_assets')
                ->whereIn('id', $ids)
                ->update(['status' => 'retired', 'updated_at' => date('Y-m-d H:i:s')]);
            $retired = count($ids);
        }

        CLI::write(json_encode([
            'event'   => 'media_reconcile.completed',
            'ts'      => gmdate('c'),
            'active'  => count($rows),
            'missing' => count($missing),
            'retired' => $retired,
            'fix'     => $fix,
        ], JSON_UNESCAPED_SLASHES));

        if ($missing !== [] && ! $fix) {
            CLI::write(CLI::color('Re-run with --fix to retire them, then re-upload covers in Quality Centre.', 'yellow'));
        }

        return 0;
    }
}
