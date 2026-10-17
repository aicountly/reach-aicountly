<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\GrowthIntelSchedule;
use App\Libraries\Intelligence\Insights\InsightsService;
use App\Libraries\Intelligence\Insights\StrategyMemoService;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * AI Insights page: latest report, history, the "Act on this" list and the
 * weekly strategy memos.
 */
class InsightsController extends BaseApiController
{
    /** GET intelligence/insights/latest — the newest completed report plus readiness of the feature. */
    public function latest(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->fail('AI Insights tables are not migrated yet. Run database migrations.', 503);
        }
        $svc  = new InsightsService();
        $last = \Config\Database::connect()->table('reach_ai_insight_reports')->select('id, status, error, created_at')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $pending = \Config\Database::connect()->table('reach_jobs')
            ->where('job_type', GrowthIntelSchedule::INSIGHTS_RUN)
            ->whereIn('status', ['pending', 'reserved', 'processing'])
            ->countAllResults();

        return $this->ok([
            'report'      => $svc->latest(),
            'last_run'    => $last ?: null,
            'run_pending' => $pending > 0,
            'configured'  => ReachConfig::secret('ai.gemini.api_key') !== null,
            'model'       => ReachConfig::vettingModel('insights'),
        ]);
    }

    /** GET intelligence/insights */
    public function index(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->ok([]);
        }

        return $this->ok((new InsightsService())->history((int) ($this->request->getGet('limit') ?? 30)));
    }

    /** GET intelligence/insights/{id} */
    public function show(int $id): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->fail('Not found', 404);
        }
        $report = (new InsightsService())->find($id);

        return $report === null ? $this->fail('Not found', 404) : $this->ok($report);
    }

    /**
     * POST intelligence/insights/run — queue a run for the worker. 409 when
     * Gemini is not configured (nothing is queued, nothing is pretended).
     */
    public function run(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->fail('AI Insights tables are not migrated yet. Run database migrations.', 503);
        }
        if (ReachConfig::secret('ai.gemini.api_key') === null) {
            return $this->fail('AI Insights is not configured: set the Gemini API key in Settings → AI & Automation.', 409);
        }
        $jobId = Services::jobService()->enqueue(GrowthIntelSchedule::INSIGHTS_RUN, ['trigger' => 'manual', 'user_id' => $this->userId()], [
            'queue'               => 'default',
            'idempotency_key'     => 'ai-insights-manual',
            'max_attempts'        => 1,
            'enqueued_by_user_id' => $this->userId(),
            'enqueued_actor_type' => 'human',
            'request_id'          => $this->request->reachRequestId ?? null,
        ]);
        $this->audit('insights.run.queued', 'ai_insight_report', null, null, null, ['job_id' => $jobId]);

        return $this->ok(['job_id' => $jobId, 'status' => 'queued', 'message' => 'Queued. The worker picks it up within a minute; the report usually takes under two minutes.'], 202);
    }

    /** GET intelligence/insights/actions?status=open */
    public function actions(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->ok([]);
        }
        $filters = [];
        $status  = (string) ($this->request->getGet('status') ?? '');
        if ($status !== '') {
            $filters['status'] = $status;
        }
        $report = $this->request->getGet('report_id');
        if ($report !== null && $report !== '') {
            $filters['report_id'] = (int) $report;
        }

        return $this->ok((new InsightsService())->actions($filters));
    }

    /** PATCH intelligence/insights/actions/{id} {status: open|done|dismissed, note?} */
    public function updateAction(int $id): ResponseInterface
    {
        $body = $this->input();
        try {
            $action = (new InsightsService())->setActionStatus($id, (string) ($body['status'] ?? ''), $this->userId(), isset($body['note']) ? (string) $body['note'] : null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        if ($action === null) {
            return $this->fail('Not found', 404);
        }
        $this->audit('insights.action.' . $action['status'], 'ai_insight_action', $id, null, ['status' => $action['status']]);

        return $this->ok($action);
    }

    /** GET intelligence/insights/memos */
    public function memos(): ResponseInterface
    {
        if (! $this->installed()) {
            return $this->ok([]);
        }

        return $this->ok((new StrategyMemoService())->list((int) ($this->request->getGet('limit') ?? 20)));
    }

    /** GET intelligence/insights/memos/{id} */
    public function memo(int $id): ResponseInterface
    {
        $memo = $this->installed() ? (new StrategyMemoService())->find($id) : null;

        return $memo === null ? $this->fail('Not found', 404) : $this->ok($memo);
    }

    private function installed(): bool
    {
        return SchemaGuard::hasTable(\Config\Database::connect(), 'reach_ai_insight_reports');
    }
}
