<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ContentCalendarItemModel;

class ContentCalendarController extends BaseApiController
{
    private const ALLOWED_KINDS = ['blog', 'social', 'email', 'whatsapp', 'campaign', 'webinar', 'other'];

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
        $body = $this->input() ?: [];
        $row  = array_intersect_key($body, array_flip(['date', 'item_kind', 'ref_type', 'ref_id', 'title', 'notes']));
        if ($err = $this->validateItem($row, true)) {
            return $this->fail($err, 422);
        }
        $row['title']      = trim((string) $row['title']);
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
        $update = array_intersect_key($this->input() ?: [], array_flip(['date', 'item_kind', 'ref_type', 'ref_id', 'title', 'notes']));
        if ($update === []) {
            return $this->fail('No fields to update.', 422);
        }
        if ($err = $this->validateItem($update, false)) {
            return $this->fail($err, 422);
        }
        if (array_key_exists('title', $update)) {
            $update['title'] = trim((string) $update['title']);
        }
        $m->update($id, $update);
        return $this->ok($m->find($id));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function validateItem(array $row, bool $requireAll): ?string
    {
        if ($requireAll || array_key_exists('title', $row)) {
            if (! array_key_exists('title', $row) || trim((string) ($row['title'] ?? '')) === '') {
                return 'Title is required.';
            }
        }
        if ($requireAll || array_key_exists('date', $row)) {
            $date = trim((string) ($row['date'] ?? ''));
            if ($date === '') {
                return 'Date is required.';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return 'Date must be YYYY-MM-DD.';
            }
        }
        if ($requireAll || array_key_exists('item_kind', $row)) {
            $kind = (string) ($row['item_kind'] ?? '');
            if ($kind === '' || ! in_array($kind, self::ALLOWED_KINDS, true)) {
                return 'Invalid item kind.';
            }
        }
        return null;
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
