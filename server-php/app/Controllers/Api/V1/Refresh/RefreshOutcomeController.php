<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Refresh;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\IntelligenceEvidenceService;
use App\Libraries\Refresh\RefreshOutcomeService;
use App\Models\Intelligence\AiVisibilityObservationModel;
use App\Models\Intelligence\AttributionConversionLinkModel;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\ContentMetricFactModel;
use App\Models\Intelligence\IndexNowSubmissionModel;
use App\Models\Intelligence\SearchMetricFactModel;
use App\Models\Intelligence\SitemapSnapshotModel;
use App\Models\Refresh\RefreshOutcomeMetricModel;
use App\Models\Refresh\RefreshOutcomeWindowModel;
use App\Models\Refresh\RefreshPublicationLinkModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class RefreshOutcomeController extends BaseApiController
{
    private const STATUSES = ['pending', 'partial', 'complete', 'insufficient_data'];

    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_refresh_outcome_windows')) {
                return $this->ok([]);
            }

            $model  = new RefreshOutcomeWindowModel();
            $status = trim((string) ($this->request->getGet('measurement_status') ?? ''));
            $contentId = (int) ($this->request->getGet('content_identity_id') ?? 0);

            if ($status !== '' && in_array($status, self::STATUSES, true)) {
                $model->where('measurement_status', $status);
            }
            if ($contentId > 0) {
                $model->where('content_identity_id', $contentId);
            }

            $rows = $model->orderBy('id', 'DESC')->findAll(200);

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'RefreshOutcomeController::index: ' . $e->getMessage());
            return $this->ok([]);
        }
    }

    public function show(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_refresh_outcome_windows')) {
                return $this->fail('Outcome storage is not available. Run database migrations first.', 503);
            }

            $windowModel = new RefreshOutcomeWindowModel();
            $window      = $windowModel->find($id);
            if (! $window) {
                return $this->fail('Outcome window not found', 404);
            }

            $metrics = [];
            if ($db->tableExists('reach_refresh_outcome_metrics')) {
                $metrics = (new RefreshOutcomeMetricModel())->getForWindow($id);
            }

            return $this->ok([
                'window'  => $window,
                'metrics' => is_array($metrics) ? $metrics : [],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'RefreshOutcomeController::show: ' . $e->getMessage());
            return $this->fail('Unable to load outcome window', 500);
        }
    }

    public function measure(int $id): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_refresh_outcome_windows')) {
                return $this->fail('Outcome storage is not available. Run database migrations first.', 503);
            }

            $result = $this->service()->measureOutcome($id);

            if (($result['status'] ?? '') === 'post_period_not_complete') {
                return $this->fail(
                    'Post-refresh period is not complete yet. Measurement available after ' . ($result['post_to'] ?? 'the post window ends') . '.',
                    422,
                    $result,
                );
            }

            $window  = (new RefreshOutcomeWindowModel())->find($id);
            $metrics = (new RefreshOutcomeMetricModel())->getForWindow($id);

            return $this->ok([
                'result'  => $result,
                'window'  => $window,
                'metrics' => is_array($metrics) ? $metrics : [],
            ]);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        } catch (\Throwable $e) {
            log_message('error', 'RefreshOutcomeController::measure: ' . $e->getMessage());
            return $this->fail('Unable to measure outcome window', 500);
        }
    }

    private function service(): RefreshOutcomeService
    {
        return new RefreshOutcomeService(
            new RefreshPublicationLinkModel(),
            new RefreshOutcomeWindowModel(),
            new RefreshOutcomeMetricModel(),
            new IntelligenceEvidenceService(
                new ContentIdentityModel(),
                new SearchMetricFactModel(),
                new ContentMetricFactModel(),
                new SitemapSnapshotModel(),
                new IndexNowSubmissionModel(),
                new AiVisibilityObservationModel(),
                new AttributionConversionLinkModel(),
            ),
            new AuditLogger(),
        );
    }
}
