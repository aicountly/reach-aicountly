<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Libraries\Community\CommunityOperationalAgentService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP surface for the role-routed operational agent runtime (Decision 2A).
 *
 * Deliberately the only entry point: CommunityOperationalAgentService is
 * never instantiated from a route/job outside this controller and
 * CommunitySchedule, so every human-triggered agent action is
 * permission-gated here and every automated one goes through the identical
 * dispatch() call. `force_window` is intentionally not accepted from the
 * request body — bypassing the automation window is a test-only escape
 * hatch on the service, never an HTTP-reachable one.
 */
class CommunityAgentDispatchController extends BaseApiController
{
    private CommunityOperationalAgentService $agents;

    public function __construct()
    {
        $this->agents = new CommunityOperationalAgentService();
    }

    /**
     * POST /community/agents/dispatch
     * Body: { identity_slug: string, action: string, context?: object }
     */
    public function dispatch(): ResponseInterface
    {
        $body         = $this->request->getJSON(true) ?? [];
        $identitySlug = trim((string) ($body['identity_slug'] ?? ''));
        $action       = trim((string) ($body['action'] ?? ''));
        $context      = is_array($body['context'] ?? null) ? $body['context'] : [];

        if ($identitySlug === '' || $action === '') {
            return $this->unprocessable('identity_slug and action are required.');
        }

        try {
            $result = $this->agents->dispatch($identitySlug, $action, $context, $this->userId());
            return $this->response->setJSON(['data' => $result]);
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        } catch (RuntimeException $e) {
            return $this->unprocessable($e->getMessage());
        }
    }

    /**
     * GET /community/agents/runs — dispatch history (success/blocked/failed),
     * newest first, filterable by identity/action/status.
     */
    public function runs(): ResponseInterface
    {
        $db      = db_connect();
        $builder = $db->table('reach_community_agent_runs r')
            ->select('r.*, i.slug AS identity_slug, i.display_name AS identity_name')
            ->join('reach_community_official_identities i', 'i.id = r.identity_id', 'left')
            ->orderBy('r.id', 'DESC')
            ->limit(min(200, max(1, (int) ($this->request->getGet('limit') ?: 100))));

        foreach (['status' => 'r.outcome', 'action' => 'r.action'] as $param => $column) {
            $value = trim((string) $this->request->getGet($param));
            if ($value !== '') {
                $builder->where($column, $value);
            }
        }
        $identity = trim((string) $this->request->getGet('identity'));
        if ($identity !== '') {
            $builder->where('i.slug', $identity);
        }

        return $this->response->setJSON(['ok' => true, 'data' => ['runs' => $builder->get()->getResultArray()]]);
    }

    /**
     * GET /community/agents/status — per-identity role, actions, caps and
     * today's usage, plus whether the automation window is currently open.
     */
    public function status(): ResponseInterface
    {
        $db         = db_connect();
        $identities = $db->table('reach_community_official_identities')
            ->where('is_active', true)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $rows = [];
        foreach ($identities as $identity) {
            $actions = [];
            foreach (CommunityOperationalAgentService::actionsForRole((string) $identity['operational_role']) as $action) {
                $cap  = CommunityOperationalAgentService::dailyCapFor($action);
                $used = (int) ($db->query(
                    "SELECT COUNT(*) AS c FROM reach_community_agent_runs
                     WHERE identity_id = ? AND action = ? AND outcome = 'success' AND created_at >= ?",
                    [(int) $identity['id'], $action, date('Y-m-d 00:00:00')]
                )->getRowArray()['c'] ?? 0);
                $actions[] = [
                    'action'       => $action,
                    'daily_cap'    => $cap,
                    'used_today'   => $used,
                    'window_gated' => CommunityOperationalAgentService::isWindowGated($action),
                ];
            }
            $rows[] = [
                'slug'         => $identity['slug'],
                'display_name' => $identity['display_name'],
                'role'         => $identity['operational_role'],
                'actions'      => $actions,
            ];
        }

        return $this->response->setJSON(['ok' => true, 'data' => [
            'window_open' => \App\Libraries\Community\CommunityAutomationWindow::isOpenNow(),
            'identities'  => $rows,
        ]]);
    }

    private function unprocessable(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => $message]);
    }
}
