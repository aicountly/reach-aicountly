<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Models\CommunityModerationFindingModel;
use App\Libraries\Community\OfficialAnswerModerationService;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class CommunityModerationController extends BaseApiController
{
    private CommunityModerationFindingModel $model;

    public function __construct()
    {
        $this->model = new CommunityModerationFindingModel();
    }

    /** GET /community/moderation/queue — open findings requiring review */
    public function queue(): ResponseInterface
    {
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $perPage = min((int) ($this->request->getGet('per_page') ?? 25), 100);
        $offset  = ($page - 1) * $perPage;
        $empty   = [
            'data' => [],
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => 0,
                'last_page'    => 0,
            ],
        ];

        try {
            $db = db_connect();
            if (! SchemaGuard::hasTable($db, 'reach_community_moderation_findings')) {
                return $this->response->setJSON($empty);
            }

            $total = (int) $db->table('reach_community_moderation_findings')
                ->where('status', 'open')
                ->countAllResults();

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $builder = $db->table('reach_community_moderation_findings')
                ->select('reach_community_moderation_findings.*')
                ->where('reach_community_moderation_findings.status', 'open')
                ->orderBy('reach_community_moderation_findings.created_at', 'ASC')
                ->limit($perPage, $offset);

            if (SchemaGuard::hasTable($db, 'reach_community_answer_versions')) {
                $builder->select(
                    'reach_community_moderation_findings.*, '
                    . 'reach_community_answer_versions.answer_id, '
                    . 'reach_community_answer_versions.version_number'
                )->join(
                    'reach_community_answer_versions',
                    'reach_community_answer_versions.id = reach_community_moderation_findings.answer_version_id',
                    'left'
                );
            }

            $rows = $builder->get()->getResultArray();

            return $this->response->setJSON([
                'data' => is_array($rows) ? $rows : [],
                'meta' => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => $total,
                    'last_page'    => (int) ceil($total / max($perPage, 1)),
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityModerationController::queue: ' . $e->getMessage());
            return $this->response->setJSON($empty);
        }
    }

    /** POST /community/moderation/(:num)/resolve */
    public function resolve(int $findingId): ResponseInterface
    {
        $body   = $this->input() ?: [];
        $note   = $body['resolution_note'] ?? '';
        $userId = $this->userId();

        $db = db_connect();
        $db->table('reach_community_moderation_findings')
            ->where('id', $findingId)
            ->update([
                'status'          => 'resolved',
                'override_by'     => $userId,
                'override_reason' => $note,
                'override_at'     => date('Y-m-d H:i:s'),
            ]);

        AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_FINDING_RESOLVED, ['finding_id' => $findingId, 'resolver_id' => $userId]);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /community/moderation/(:num)/escalate */
    public function escalate(int $findingId): ResponseInterface
    {
        $body = $this->input() ?: [];
        $note = $body['note'] ?? '';

        $db = db_connect();
        $db->table('reach_community_moderation_findings')
            ->where('id', $findingId)
            ->update(['status' => 'overridden', 'override_reason' => $note]);

        AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_FINDING_ESCALATED, ['finding_id' => $findingId]);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /community/answers/(:segment)/run-moderation — manual re-run */
    public function runModeration(string $answerUuid): ResponseInterface
    {
        try {
            $findings = (new \App\Libraries\Community\OfficialAnswerLifecycleService())
                ->moderate($answerUuid, null, $this->userId());

            AuditLogger::record(
                AuditLogger::COMMUNITY_MODERATION_RUN,
                ['answer_uuid' => $answerUuid, 'decision' => $findings['decision'] ?? null],
                $this->userId()
            );
            return $this->response->setJSON(['data' => $findings]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'not found') ? 404 : 422;
            return $this->response->setStatusCode($status)->setJSON(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}

