<?php

namespace App\Models;

use CodeIgniter\Model;

class ContentCalendarItemModel extends Model
{
    protected $table         = 'reach_content_calendar_items';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'date', 'item_kind', 'ref_type', 'ref_id', 'title', 'notes', 'created_by',
    ];
}
