<?php

declare(strict_types=1);

namespace App\Models\Distribution;

use CodeIgniter\Model;

class AudienceSegmentRuleModel extends Model
{
    protected $table      = 'reach_audience_segment_rules';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'segment_id', 'rule_group', 'field', 'operator', 'value', 'negated', 'created_at',
    ];

    public static array $allowedFields_ = [
        'email', 'phone', 'channel', 'lead_status', 'tag', 'country',
        'consent_channel', 'consent_status', 'created_at',
    ];

    public function findBySegment(int $segmentId): array
    {
        return $this->where('segment_id', $segmentId)->orderBy('rule_group', 'ASC')->findAll();
    }

    public function isAllowedField(string $field): bool
    {
        return in_array($field, self::$allowedFields_, true);
    }
}
