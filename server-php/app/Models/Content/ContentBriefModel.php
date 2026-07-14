<?php

namespace App\Models\Content;

use CodeIgniter\Model;

class ContentBriefModel extends Model
{
    protected $table         = 'reach_content_briefs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'content_item_id', 'objective', 'audience_description', 'persona_id',
        'funnel_stage', 'primary_keyword', 'secondary_keywords', 'questions_to_answer',
        'required_claim_ids', 'excluded_claim_ids', 'cta', 'tone',
        'min_word_count', 'max_word_count', 'format_notes', 'due_date',
        'sources', 'competitor_urls', 'is_approved', 'approved_at', 'approved_by',
        'created_by', 'updated_by',
    ];

    protected array $casts = [
        'secondary_keywords'   => '?json-array',
        'questions_to_answer'  => '?json-array',
        'required_claim_ids'   => '?json-array',
        'excluded_claim_ids'   => '?json-array',
        'sources'              => '?json-array',
        'competitor_urls'      => '?json-array',
        'is_approved'          => 'boolean',
    ];

    public function forItem(int $contentItemId): ?array
    {
        return $this->where('content_item_id', $contentItemId)->first();
    }
}
