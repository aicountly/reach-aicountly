<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * SEO Tracker API. Positions come only from stored DataForSEO checks; with
 * no credentials the status is `not_configured` and the table is empty.
 */
class SeoTrackerController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        try {
            $date = (string) ($this->request->getGet('date') ?? '');

            return $this->ok((new SeoTrackerService())->keywordTable(
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null
            ));
        } catch (\Throwable $e) {
            log_message('error', 'SeoTrackerController::index: ' . $e->getMessage());

            return $this->error('Unable to read SEO Tracker data.', 500);
        }
    }

    /**
     * Queue a run now (paid calls — seo.manage). Keywords already checked
     * successfully today are skipped, so pressing this twice costs nothing extra.
     */
    public function run(): ResponseInterface
    {
        $status = (new SeoTrackerService())->status();
        if ($status['status'] === 'not_configured' || $status['status'] === 'not_migrated') {
            return $this->error($status['detail'], 409, ['status' => $status['status']]);
        }

        $body  = $this->input();
        $today = date('Y-m-d');
        $jobId = Services::jobService()->enqueue('reach.seo_tracker', [
            'date'  => $today,
            'force' => true,
            'limit' => isset($body['limit']) ? max(1, (int) $body['limit']) : null,
        ], [
            'queue'               => 'default',
            'idempotency_key'     => 'seo-tracker-manual-' . $today,
            'enqueued_by_user_id' => $this->userId(),
        ]);

        return $this->ok(['job_id' => $jobId, 'message' => 'SEO Tracker run queued (job #' . $jobId . ').'], 202);
    }

    public function createKeyword(): ResponseInterface
    {
        $body    = $this->input();
        $keyword = trim((string) ($body['keyword'] ?? ''));
        if ($keyword === '' || mb_strlen($keyword) > 300) {
            return $this->error('keyword is required (max 300 characters).', 422);
        }
        $priority = (string) ($body['priority'] ?? 'medium');
        if (! in_array($priority, ['low', 'medium', 'high'], true)) {
            return $this->error('priority must be low, medium or high.', 422);
        }

        $product = trim((string) ($body['product'] ?? ''));
        if (mb_strlen($product) > 40) {
            return $this->error('product must be at most 40 characters.', 422);
        }

        $db = db_connect();
        if (! SchemaGuard::hasTable($db, 'reach_seo_tracked_keywords')) {
            return $this->error('SEO Tracker tables are missing. Run migrations.', 503);
        }
        $exists = $db->query('SELECT id FROM reach_seo_tracked_keywords WHERE LOWER(keyword) = LOWER(?)', [$keyword])->getRowArray();
        if ($exists) {
            return $this->error('That keyword is already tracked.', 409, ['id' => (int) $exists['id']]);
        }

        $row = $db->query(
            'INSERT INTO reach_seo_tracked_keywords (keyword, product, priority, enabled, notes) VALUES (?, ?, ?, TRUE, ?) RETURNING id',
            [$keyword, $product !== '' ? $product : null, $priority, ($body['notes'] ?? null) ?: null]
        )->getRowArray();

        $this->audit('seo.tracker.keyword_created', 'seo_tracked_keyword', (int) $row['id'], null, ['keyword' => $keyword]);

        return $this->ok(['id' => (int) $row['id'], 'keyword' => $keyword], 201);
    }

    public function updateKeyword(int $id): ResponseInterface
    {
        $body = $this->input();
        $db   = db_connect();
        $row  = $db->query('SELECT * FROM reach_seo_tracked_keywords WHERE id = ?', [$id])->getRowArray();
        if (! $row) {
            return $this->notFound('Tracked keyword not found.');
        }

        $set = [];
        if (array_key_exists('enabled', $body)) {
            $set['enabled'] = filter_var($body['enabled'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('priority', $body)) {
            if (! in_array($body['priority'], ['low', 'medium', 'high'], true)) {
                return $this->error('priority must be low, medium or high.', 422);
            }
            $set['priority'] = $body['priority'];
        }
        if (array_key_exists('product', $body)) {
            $product = trim((string) ($body['product'] ?? ''));
            if (mb_strlen($product) > 40) {
                return $this->error('product must be at most 40 characters.', 422);
            }
            $set['product'] = $product !== '' ? $product : null;
        }
        if ($set === []) {
            return $this->error('Nothing to update (enabled, priority, product).', 422);
        }
        $set['updated_at'] = date('Y-m-d H:i:s');
        $db->table('reach_seo_tracked_keywords')->where('id', $id)->update($set);

        $this->audit('seo.tracker.keyword_updated', 'seo_tracked_keyword', $id, null, $set);

        return $this->ok($db->query('SELECT * FROM reach_seo_tracked_keywords WHERE id = ?', [$id])->getRowArray());
    }
}
