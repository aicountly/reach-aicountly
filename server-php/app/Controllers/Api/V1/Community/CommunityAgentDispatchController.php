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

    private function unprocessable(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => $message]);
    }
}
