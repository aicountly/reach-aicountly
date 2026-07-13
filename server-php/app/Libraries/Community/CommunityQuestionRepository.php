<?php

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use App\Models\CommunityQuestionModel;
use RuntimeException;

/**
 * Repository for community question aggregate queries.
 * Controllers should use this through service classes; never bypass to the model directly from controllers.
 */
class CommunityQuestionRepository
{
    public function __construct(
        private readonly CommunityQuestionModel $model = new CommunityQuestionModel()
    ) {}

    public function findById(int $id): ?array
    {
        return $this->model->find($id);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->model->findByUuid($uuid);
    }

    public function requireByUuid(string $uuid): array
    {
        $q = $this->model->findByUuid($uuid);
        if ($q === null) {
            throw new RuntimeException("Community question not found: {$uuid}");
        }
        return $q;
    }

    public function save(array $data): int
    {
        if (empty($data['id'])) {
            $this->model->insert($data);
            return (int) $this->model->getInsertID();
        }
        $this->model->update($data['id'], $data);
        return (int) $data['id'];
    }

    public function transitionStatus(int $id, CommunityQuestionStatus $from, CommunityQuestionStatus $to): void
    {
        if (!$from->canTransitionTo($to)) {
            throw new RuntimeException(
                "Invalid question status transition: {$from->value} → {$to->value}"
            );
        }
        $affected = $this->model->db->table($this->model->table)
            ->set('status', $to->value)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->where('id', $id)
            ->where('status', $from->value)
            ->update();

        if (!$affected) {
            throw new RuntimeException("Status transition failed (concurrent modification?) for question #{$id}");
        }
    }

    public function listForInbox(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return $this->model->listForInbox($filters, $page, $perPage);
    }

    public function countByStatus(): array
    {
        return $this->model->countByStatus();
    }

    public function findSimilar(string $title, string $body, int $limit = 10): array
    {
        // Basic trigram-like search. Production deployments should use pg_trgm or embedding similarity.
        $db = $this->model->db;
        $words = array_filter(array_slice(explode(' ', strtolower($title)), 0, 5));
        if (empty($words)) {
            return [];
        }

        $builder = $db->table('reach_community_questions q')
            ->select('q.id, q.uuid, q.title, q.status')
            ->where('q.status !=', CommunityQuestionStatus::Archived->value)
            ->where('q.status !=', CommunityQuestionStatus::DuplicateMerged->value);

        $builder->groupStart();
        foreach ($words as $word) {
            $builder->orLike('q.title', $word, 'both');
        }
        $builder->groupEnd();

        return $builder->limit($limit)->get()->getResultArray();
    }
}
