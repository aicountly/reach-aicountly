<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Enums\CommunityRiskTier;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\Community\OfficialAnswerRepository;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Official answer HTTP surface.
 *
 * Every route segment is a UUID. All state changes are delegated to
 * OfficialAnswerLifecycleService, which is the single place that resolves a
 * UUID to the internal numeric ID before calling the domain services. This
 * controller performs no ID translation and holds no lifecycle rules of its own.
 */
class OfficialAnswerController extends BaseApiController
{
    private OfficialAnswerRepository $repo;
    private OfficialAnswerLifecycleService $lifecycle;

    public function __construct()
    {
        $this->repo      = new OfficialAnswerRepository();
        $this->lifecycle = new OfficialAnswerLifecycleService();
    }

    /** GET /community/answers */
    public function index(): ResponseInterface
    {
        $status  = (string) ($this->request->getGet('status') ?? '');
        $perPage = min((int) ($this->request->getGet('per_page') ?? 100), 100);

        try {
            $db = db_connect();
            if (! $db->tableExists('reach_community_official_answers')) {
                return $this->response->setJSON(['data' => [], 'meta' => ['total' => 0]]);
            }

            // Empty status = "All" in the UI — list everything. The old
            // 'draft_requested' default made the All view silently show a
            // single status and hide failed/generated drafts entirely.
            $items = $this->repo->listByStatus($status !== '' ? $status : null, $perPage);

            return $this->response->setJSON([
                'data' => is_array($items) ? $items : [],
                'meta' => ['total' => is_array($items) ? count($items) : 0],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'OfficialAnswerController::index: ' . $e->getMessage());
            return $this->response->setJSON(['data' => [], 'meta' => ['total' => 0]]);
        }
    }

    /** GET /community/answers/(:segment) */
    public function show(string $uuid): ResponseInterface
    {
        $answer = $this->repo->findByUuid($uuid);
        if ($answer === null) {
            return $this->notFound();
        }
        return $this->response->setJSON(['data' => $answer]);
    }

    /** POST /community/answers — reserve a draft answer for a question */
    public function create(): ResponseInterface
    {
        $body         = $this->request->getJSON(true) ?? [];
        $questionUuid = (string) ($body['question_uuid'] ?? '');
        $identitySlug = (string) ($body['official_identity_slug'] ?? 'aicountly-official');

        if ($questionUuid === '') {
            return $this->unprocessable('question_uuid is required.');
        }

        return $this->guard(
            fn () => $this->response->setStatusCode(201)->setJSON([
                'data' => $this->lifecycle->createDraft($questionUuid, $identitySlug, $this->userId()),
            ])
        );
    }

    /** POST /community/answers/(:segment)/generate */
    public function generate(string $uuid): ResponseInterface
    {
        $body       = $this->request->getJSON(true) ?? [];
        $answerType = (string) ($body['answer_type'] ?? 'detailed');

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->requestGeneration($uuid, $answerType, $this->userId()),
        ]));
    }

    /** PUT /community/answers/(:segment) — save a human edit as a new version */
    public function update(string $uuid): ResponseInterface
    {
        $body    = $this->request->getJSON(true) ?? [];
        $content = (string) ($body['content'] ?? '');
        $excerpt = (string) ($body['excerpt'] ?? '');
        $sources = (array) ($body['sources'] ?? []);

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->saveHumanEdit($uuid, $content, $excerpt, $sources, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/validate */
    public function validateAnswer(string $uuid): ResponseInterface
    {
        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->validate($uuid),
        ]));
    }

    /** POST /community/answers/(:segment)/submit-review */
    public function submitForReview(string $uuid): ResponseInterface
    {
        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->submitForReview($uuid, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/approve */
    public function approve(string $uuid): ResponseInterface
    {
        $body         = $this->request->getJSON(true) ?? [];
        $approvalType = (string) ($body['approval_type'] ?? 'standard');
        $reason       = (string) ($body['reason'] ?? $body['note'] ?? '');
        $version      = isset($body['version_number']) ? (int) $body['version_number'] : null;
        $userId       = $this->userId();

        if ($userId === null) {
            return $this->unprocessable('An authenticated approver is required.');
        }

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->approve($uuid, $userId, $version, $approvalType, $reason),
        ]));
    }

    /** POST /community/answers/(:segment)/reject */
    public function reject(string $uuid): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = (string) ($body['reason'] ?? '');
        $userId = $this->userId();

        if ($userId === null) {
            return $this->unprocessable('An authenticated reviewer is required.');
        }

        return $this->guard(function () use ($uuid, $userId, $reason, $body) {
            $this->lifecycle->reject(
                $uuid,
                $userId,
                $reason,
                isset($body['version_number']) ? (int) $body['version_number'] : null
            );
            return $this->response->setJSON(['success' => true]);
        });
    }

    /** POST /community/answers/(:segment)/schedule */
    public function schedule(string $uuid): ResponseInterface
    {
        $body        = $this->request->getJSON(true) ?? [];
        $scheduledAt = (string) ($body['scheduled_at'] ?? '');

        if ($scheduledAt === '') {
            return $this->unprocessable('scheduled_at is required.');
        }

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->schedule($uuid, $scheduledAt, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/publish */
    public function publish(string $uuid): ResponseInterface
    {
        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->publish($uuid, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/unpublish */
    public function unpublish(string $uuid): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = (string) ($body['reason'] ?? '');

        if (trim($reason) === '') {
            return $this->unprocessable('A reason is required to unpublish an answer.');
        }

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->unpublish($uuid, $reason, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/withdraw */
    public function withdraw(string $uuid): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = (string) ($body['reason'] ?? '');

        return $this->guard(function () use ($uuid, $reason) {
            $this->lifecycle->withdraw($uuid, $reason, $this->userId());
            return $this->response->setJSON(['success' => true]);
        });
    }

    /** POST /community/answers/(:segment)/restore */
    public function restore(string $uuid): ResponseInterface
    {
        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->restore($uuid, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/correct */
    public function correct(string $uuid): ResponseInterface
    {
        $body    = $this->request->getJSON(true) ?? [];
        $content = (string) ($body['content'] ?? '');
        $excerpt = (string) ($body['excerpt'] ?? '');
        $note    = (string) ($body['correction_note'] ?? '');
        $sources = (array) ($body['sources'] ?? []);

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->correct($uuid, $content, $excerpt, $note, $sources, $this->userId()),
        ]));
    }

    /** POST /community/answers/(:segment)/archive */
    public function archive(string $uuid): ResponseInterface
    {
        return $this->guard(function () use ($uuid) {
            $this->lifecycle->archive($uuid, $this->userId());
            return $this->response->setJSON(['success' => true]);
        });
    }

    /**
     * POST /community/answers/(:segment)/risk
     * Lowering a tier is an override: it requires a reason and is audited.
     */
    public function setRisk(string $uuid): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $tier   = CommunityRiskTier::tryFrom((int) ($body['risk_tier'] ?? -1));
        $reason = (string) ($body['reason'] ?? '');
        $userId = $this->userId();

        if ($tier === null) {
            return $this->unprocessable('risk_tier must be an integer between 0 and 4.');
        }
        if ($userId === null) {
            return $this->unprocessable('An authenticated actor is required to change risk tier.');
        }

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->setRiskTierWithOverride($uuid, $tier, $reason, $userId),
        ]));
    }

    /**
     * DELETE /community/answers/(:segment)
     *
     * Permanent removal, not a withdrawal: the answer, its versions, approvals
     * and deployment records all go. A published answer is taken down first;
     * pass {"force": true} to delete from Reach even if that fails.
     */
    public function destroy(string $uuid): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));
        $force  = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($this->repo->findByUuid($uuid) === null) {
            return $this->notFound();
        }

        return $this->guard(fn () => $this->response->setJSON([
            'data' => $this->lifecycle->purge(
                $uuid,
                $reason !== '' ? $reason : 'Deleted from the Reach panel',
                $this->userId(),
                $force
            ),
        ]));
    }

    /** GET /community/answers/(:segment)/versions */
    public function versions(string $uuid): ResponseInterface
    {
        $answer = $this->repo->findByUuid($uuid);
        if ($answer === null) {
            return $this->notFound();
        }
        return $this->response->setJSON(['data' => $this->repo->listVersions((int) $answer['id'])]);
    }

    // -------------------------------------------------------------------------

    /**
     * Translate domain exceptions into the canonical API error envelope so that
     * a failed lifecycle action can never be reported as a success.
     */
    private function guard(callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return $this->notFound($e->getMessage());
            }
            return $this->unprocessable($e->getMessage());
        }
    }

    private function notFound(string $message = 'Official answer not found.'): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON(['ok' => false, 'error' => $message]);
    }

    private function unprocessable(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => $message]);
    }
}
