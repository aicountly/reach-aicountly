<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentItemModel extends Model
{
    protected $table         = 'reach_content_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $useSoftDeletes = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $allowedFields = [
        'uuid', 'content_type', 'title', 'slug', 'summary', 'objective', 'language',
        'market_id', 'primary_product_id', 'primary_module_id', 'primary_feature_id',
        'primary_persona_id', 'primary_industry_id', 'primary_search_intent_id',
        'primary_topic_cluster_id', 'funnel_stage', 'priority', 'risk_level',
        'creation_source', 'current_version_id',
        'workflow_status', 'approval_status', 'validation_status', 'publication_status',
        'scheduled_at', 'approved_at', 'approved_by', 'review_due_at', 'refresh_due_at',
        'published_at', 'archived_at',
        'created_actor_type', 'created_by_user_id', 'created_by_service',
        'updated_by_user_id', 'generation_job_id', 'request_id', 'internal_notes',
    ];

    protected array $casts = [
        'internal_notes' => '?json-array',
    ];

    /** Return paginated list with optional filters. */
    public function listPaged(array $filters = [], int $perPage = 25): array
    {
        $builder = $this->withDeleted(false);

        if (!empty($filters['content_type'])) {
            $builder->where('content_type', $filters['content_type']);
        }
        if (!empty($filters['workflow_status'])) {
            $builder->where('workflow_status', $filters['workflow_status']);
        }
        if (!empty($filters['approval_status'])) {
            $builder->where('approval_status', $filters['approval_status']);
        }
        if (!empty($filters['risk_level'])) {
            $builder->where('risk_level', $filters['risk_level']);
        }
        if (!empty($filters['primary_product_id'])) {
            $builder->where('primary_product_id', (int) $filters['primary_product_id']);
        }
        if (!empty($filters['market_id'])) {
            $builder->where('market_id', (int) $filters['market_id']);
        }
        if (!empty($filters['search'])) {
            $builder->groupStart()
                ->like('title', $filters['search'])
                ->orLike('slug', $filters['search'])
                ->groupEnd();
        }

        return $builder->orderBy('created_at', 'DESC')->paginate($perPage);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->where('slug', $slug)->withDeleted(false)->first();
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->where('uuid', $uuid)->withDeleted(false)->first();
    }

    /** Items needing review (for approval centre). */
    public function forApprovalQueue(array $filters = []): array
    {
        $builder = $this->whereIn('workflow_status', ['review_pending', 'validation_pending'])
            ->withDeleted(false);

        if (!empty($filters['overdue'])) {
            $builder->where('review_due_at <', date('Y-m-d H:i:s'));
        }
        if (!empty($filters['high_risk'])) {
            $builder->whereIn('risk_level', ['high', 'critical']);
        }
        if (!empty($filters['content_type'])) {
            $builder->where('content_type', $filters['content_type']);
        }

        return $builder->orderBy('review_due_at', 'ASC')->findAll();
    }

    /** Generate a slug from the title, ensuring uniqueness. */
    public function buildUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
        $base = trim($base, '-');
        $slug = $base;
        $i    = 1;

        while (true) {
            $q = $this->where('slug', $slug);
            if ($excludeId !== null) {
                $q = $q->where('id !=', $excludeId);
            }
            if ($q->countAllResults() === 0) {
                break;
            }
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
