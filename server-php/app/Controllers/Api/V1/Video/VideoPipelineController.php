<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Video;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Media\VideoMediaStore;
use App\Libraries\Video\Automation\VideoAutomationService;
use App\Libraries\Video\Automation\VideoPipelineRepository;
use App\Libraries\Video\Automation\VideoPipelineRunner;
use App\Libraries\Video\Automation\VideoPipelineStatus;

/**
 * The automated video pipeline as staff see it (Reach → Video).
 *
 *   GET  v1/video/pipeline              items, newest first
 *   GET  v1/video/pipeline/status       what is configured, honestly
 *   GET  v1/video/pipeline/{uuid}       one item with its assets
 *   POST v1/video/pipeline/{uuid}/retry retry a blocked / failed item, or re-vet a pending one
 */
class VideoPipelineController extends BaseApiController
{
    public function index()
    {
        [, $limit, $offset] = $this->pagination(25, 100);
        $filters = array_filter([
            'status' => (string) ($this->request->getGet('status') ?? ''),
            'format' => (string) ($this->request->getGet('format') ?? ''),
        ]);
        $page = (new VideoPipelineRepository())->list($filters, $limit, $offset);

        return $this->ok([
            'items' => array_map(fn (array $i) => $this->present($i), $page['items']),
            'total' => $page['total'],
        ]);
    }

    public function show(string $uuid)
    {
        $repo = new VideoPipelineRepository();
        $item = $repo->findByUuid($uuid);
        if ($item === null) {
            return $this->notFound('Video not found.');
        }
        $store  = new VideoMediaStore();
        $assets = [];
        foreach ($repo->assets((int) $item['id']) as $a) {
            $preview = null;
            if ($a['media_id']) {
                $media = $store->find((int) $a['media_id']);
                try {
                    $preview = $media !== null ? $store->signedUrl($media, 3600) : null;
                } catch (\Throwable) {
                    $preview = null;
                }
            }
            $assets[] = [
                'kind'        => $a['kind'],
                'scene_order' => (int) $a['scene_order'],
                'source'      => $a['source'],
                'status'      => $a['status'],
                'duration_ms' => $a['duration_ms'] !== null ? (int) $a['duration_ms'] : null,
                'detail'      => $a['detail'],
                'preview_url' => $preview,
            ];
        }

        return $this->ok($this->present($item, true) + [
            'assets'  => $assets,
            'jobs'    => $this->stepJobs($repo, (int) $item['id']),
            'history' => $this->history($repo, (int) $item['id']),
        ]);
    }

    /**
     * Every queue job that ran (or will run) a step of this item, oldest first:
     * the pipeline's step-by-step trail, each one openable in the Job Monitor.
     *
     * @return list<array<string, mixed>>
     */
    private function stepJobs(VideoPipelineRepository $repo, int $itemId): array
    {
        try {
            $rows = $repo->db()->query(
                "SELECT id, status, attempts, max_attempts, payload_json->>'step' AS step, error_message,
                        available_at, started_at, completed_at, created_at
                 FROM reach_jobs
                 WHERE job_type = ? AND payload_json->>'item_id' = ?
                 ORDER BY id ASC LIMIT 200",
                [VideoPipelineRunner::JOB_TYPE, (string) $itemId],
            )->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'VideoPipelineController::stepJobs: ' . $e->getMessage());
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'attempts'     => (int) $r['attempts'],
            'max_attempts' => (int) $r['max_attempts'],
        ] + $r, $rows);
    }

    /**
     * Staff actions on the item (retry, re-vet) from the audit log, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function history(VideoPipelineRepository $repo, int $itemId): array
    {
        try {
            $rows = $repo->db()->table('reach_audit_logs')
                ->select('id, action, user_id, old_value, new_value, created_at')
                ->where('entity_type', 'video_pipeline')->where('entity_id', $itemId)
                ->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'VideoPipelineController::history: ' . $e->getMessage());
            return [];
        }

        return array_map(static function (array $r): array {
            foreach (['old_value', 'new_value'] as $k) {
                if (isset($r[$k]) && is_string($r[$k])) {
                    $r[$k] = json_decode($r[$k], true);
                }
            }

            return ['id' => (int) $r['id']] + $r;
        }, $rows);
    }

    public function retry(string $uuid)
    {
        $repo = new VideoPipelineRepository();
        $item = $repo->findByUuid($uuid);
        if ($item === null) {
            return $this->notFound('Video not found.');
        }
        if ($item['status'] === 'pending_vetting') {
            $result = (new VideoAutomationService($repo))->vet((int) $item['id']);
            $this->audit('video.pipeline.revet', 'video_pipeline', (int) $item['id']);
            return $this->ok($result);
        }
        if (! in_array($item['status'], ['blocked', 'failed'], true)) {
            return $this->fail('Only blocked or failed videos can be retried (this one is ' . $item['status'] . ').', 409);
        }
        $repo->resetAssets((int) $item['id']);
        $fields = ['status' => 'in_progress', 'last_error' => null, 'step_detail' => 'Retry requested.'];
        // A YouTube post social-api.ai took and then failed is history: a retry
        // submits a fresh post (social-api.ai refuses an identical one within 24 h).
        if (! empty($item['youtube_social_post_id'])) {
            $post = $repo->db()->table('reach_social_posts')->select('status, external_post_id')->where('id', (int) $item['youtube_social_post_id'])->get()->getRowArray();
            if ($post !== null && $post['status'] === 'failed' && ! empty($post['external_post_id'])) {
                $fields['youtube_social_post_id'] = null;
            }
        }
        $repo->update((int) $item['id'], $fields);
        VideoPipelineRunner::enqueue((int) $item['id'], (string) $item['step']);
        $this->audit('video.pipeline.retry', 'video_pipeline', (int) $item['id'], null, ['status' => $item['status'], 'step' => $item['step']]);

        return $this->ok($this->present((array) $repo->find((int) $item['id'])));
    }

    /** Configuration as it really is — nothing reports "ready" unless it is. */
    public function status()
    {
        return $this->ok(VideoPipelineStatus::describe());
    }

    /** @return array<string, mixed> */
    private function present(array $item, bool $full = false): array
    {
        $out = [
            'uuid'             => $item['uuid'],
            'external_key'     => $item['external_key'],
            'format'           => $item['format'],
            'title'            => $item['title'],
            'product_slug'     => $item['product_slug'],
            'status'           => $item['status'],
            'step'             => $item['step'],
            'step_detail'      => $item['step_detail'],
            'last_error'       => $item['last_error'],
            'warnings'         => $item['warnings_json'],
            'vetting_decision' => $item['vetting_decision'],
            'vetting_reasons'  => $item['vetting_reasons_json'],
            'vetting_model'    => $item['vetting_model'],
            'approved_at'      => $item['approved_at'],
            'duration_secs'    => $item['duration_secs'] !== null ? (float) $item['duration_secs'] : null,
            'render_status'    => $item['render_status'],
            'preview_url'      => $item['youtube_video_id'] === null ? $item['render_url'] : null,
            'poster_url'       => $item['render_poster_url'],
            'youtube_video_id' => $item['youtube_video_id'],
            'youtube_url'      => $item['youtube_url'],
            'youtube_privacy'  => $item['youtube_privacy'],
            'youtube_publisher' => $item['youtube_publisher'] ?? null,
            'youtube_social_post_id' => isset($item['youtube_social_post_id']) ? (int) $item['youtube_social_post_id'] : null,
            'thumbnail_status' => $item['thumbnail_status'],
            'captions_status'  => $item['captions_status'],
            'social_post_ids'  => $item['social_post_ids_json'],
            'published_at'     => $item['published_at'],
            'created_at'       => $item['created_at'],
            'updated_at'       => $item['updated_at'],
        ];
        if ($full) {
            $script = (array) $item['script_json'];
            unset($script['external_key']);
            $out['script']          = $script;
            $out['vetting_run_ids'] = $item['vetting_run_ids_json'];
            $out['upload_progress'] = $item['youtube_upload_bytes'] ? (int) floor(((int) $item['youtube_upload_offset']) * 100 / max(1, (int) $item['youtube_upload_bytes'])) : null;
        }
        return $out;
    }
}
