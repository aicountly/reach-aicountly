<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Models\CommunityModerationFindingModel;
use App\Libraries\Community\OfficialAnswerModerationService;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

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

        $db      = db_connect();
        $total   = (int) $db->table('reach_community_moderation_findings')
            ->where('status', 'open')
            ->countAllResults();

        $rows = $db->table('reach_community_moderation_findings f')
            ->select('f.*, av.answer_id, av.version_number')
            ->join('reach_community_answer_versions av', 'av.id = f.answer_version_id', 'left')
            ->where('f.status', 'open')
            ->orderBy('f.created_at', 'ASC')
            ->limit($perPage, $offset)
            ->get()->getResultArray();

        return $this->response->setJSON([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /** POST /community/moderation/(:num)/resolve */
    public function resolve(int $findingId): ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $note   = $body['resolution_note'] ?? '';
        $userId = auth_user_id();

        $db = db_connect();
        $db->table('reach_community_moderation_findings')
            ->where('id', $findingId)
            ->update([
                'status'          => 'resolved',
                'resolved_by'     => $userId,
                'resolution_note' => $note,
                'resolved_at'     => date('Y-m-d H:i:s'),
            ]);

        AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_FINDING_RESOLVED, ['finding_id' => $findingId, 'resolver_id' => $userId]);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /community/moderation/(:num)/escalate */
    public function escalate(int $findingId): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $note = $body['note'] ?? '';

        $db = db_connect();
        $db->table('reach_community_moderation_findings')
            ->where('id', $findingId)
            ->update(['status' => 'escalated', 'resolution_note' => $note]);

        AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_FINDING_ESCALATED, ['finding_id' => $findingId]);
        return $this->response->setJSON(['success' => true]);
    }

    /** POST /community/answers/(:segment)/run-moderation — manual re-run */
    public function runModeration(string $answerUuid): ResponseInterface
    {
        try {
            $modSvc   = new OfficialAnswerModerationService();
            $findings = $modSvc->moderate($answerUuid);
            AuditLogger::record(AuditLogger::COMMUNITY_MODERATION_RUN, ['answer_uuid' => $answerUuid]);
            return $this->response->setJSON(['data' => $findings]);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }
    }
}

