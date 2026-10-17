<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Intelligence;

use App\Controllers\BaseApiController;
use App\Libraries\Intelligence\Insights\GrowthIntelligenceSummary;
use App\Libraries\Intelligence\Insights\StrategyMemoService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `v1/automation/*` endpoints for the reach-growth-strategy Claude routine
 * (X-Automation-Token): read the latest insights + AI visibility, and store
 * the weekly strategy memo.
 */
class InsightsAutomationController extends BaseApiController
{
    /** GET v1/automation/insights-digest */
    public function insightsDigest(): ResponseInterface
    {
        $digest = (new GrowthIntelligenceSummary())->automationDigest();
        if (! ($digest['available'] ?? false)) {
            return $this->fail((string) ($digest['reason'] ?? 'Not available.'), 503);
        }

        return $this->ok($digest);
    }

    /** POST v1/automation/strategy-memos {title, body_markdown, period_start?, period_end?, routine_run_id?, model?, sources?[]} */
    public function storeStrategyMemo(): ResponseInterface
    {
        try {
            $res = (new StrategyMemoService())->store($this->input());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $memo = $res['memo'];
        if (! $res['duplicate']) {
            \Config\Services::auditLogger()->log(
                userId:       null,
                action:       'insights.strategy_memo.stored',
                entityType:   'strategy_memo',
                entityId:     (int) $memo['id'],
                newValue:     ['title' => $memo['title'], 'routine_run_id' => $memo['routine_run_id'] ?? null],
                actorType:    'service',
                actorService: 'claude-routine:reach-growth-strategy',
                requestId:    $this->request->reachRequestId ?? null,
            );
        }

        return $this->ok([
            'id'        => $memo['id'],
            'title'     => $memo['title'],
            'duplicate' => $res['duplicate'],
            'view_url'  => '/intelligence/insights?memo=' . $memo['id'],
        ], $res['duplicate'] ? 200 : 201);
    }
}
