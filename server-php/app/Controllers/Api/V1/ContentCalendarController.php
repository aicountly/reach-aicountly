<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ContentCalendarItemModel;

class ContentCalendarController extends BaseApiController
{
    public function index()
    {
        $from = trim((string) $this->request->getGet('from'));
        $to   = trim((string) $this->request->getGet('to'));
        $q    = new ContentCalendarItemModel();
        if ($from !== '') {
            $q->where('date >=', $from);
        }
        if ($to !== '') {
            $q->where('date <=', $to);
        }
        $rows = $q->orderBy('date', 'ASC')->orderBy('id', 'ASC')->findAll();
        return $this->ok(['items' => $rows]);
    }

    public function store()
    {
        $body = $this->input();
        $row  = array_intersect_key($body, array_flip(['date', 'item_kind', 'ref_type', 'ref_id', 'title', 'notes']));
        $row['created_by'] = $this->userId();
        $m = new ContentCalendarItemModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m = new ContentCalendarItemModel();
        if (! $m->find($id)) {
            return $this->fail('Calendar item not found.', 404);
        }
        $update = array_intersect_key($this->input(), array_flip(['date', 'item_kind', 'ref_type', 'ref_id', 'title', 'notes']));
        $m->update($id, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new ContentCalendarItemModel();
        if (! $m->find($id)) {
            return $this->fail('Calendar item not found.', 404);
        }
        $m->delete($id);
        return $this->ok(['message' => 'Deleted.']);
    }
}
