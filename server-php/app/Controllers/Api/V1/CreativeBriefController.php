<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\CreativeBriefModel;

class CreativeBriefController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q      = new CreativeBriefModel();
        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $q->where('status', $status);
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('updated_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new CreativeBriefModel())->find($id);
        if (! $row) {
            return $this->fail('Creative brief not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m = new CreativeBriefModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m = new CreativeBriefModel();
        if (! $m->find($id)) {
            return $this->fail('Creative brief not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new CreativeBriefModel();
        if (! $m->find($id)) {
            return $this->fail('Creative brief not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        return $this->ok(['message' => 'Archived.']);
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $out = array_intersect_key($body, array_flip([
            'campaign_id', 'title', 'brief', 'audience', 'deliverables', 'status', 'bot_generated',
        ]));
        if (isset($out['deliverables']) && is_array($out['deliverables'])) {
            $out['deliverables'] = json_encode($out['deliverables']);
        }
        if (! $partial) {
            $out['title'] ??= 'Untitled brief';
            $out['brief'] ??= '';
            $out['status'] ??= 'draft';
        }
        return $out;
    }
}
