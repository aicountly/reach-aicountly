<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SeoPlanModel;

class SeoPlanController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q      = new SeoPlanModel();
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
        $row = (new SeoPlanModel())->find($id);
        if (! $row) {
            return $this->fail('SEO plan not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m = new SeoPlanModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('seo.create', 'seo_plan', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m   = new SeoPlanModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('SEO plan not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        $this->audit('seo.update', 'seo_plan', $id, $row, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new SeoPlanModel();
        if (! $m->find($id)) {
            return $this->fail('SEO plan not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        $this->audit('seo.archive', 'seo_plan', $id);
        return $this->ok(['message' => 'Archived.']);
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $out = array_intersect_key($body, array_flip([
            'title', 'focus_keyword', 'secondary_keywords', 'brief', 'target_url', 'status', 'bot_generated',
        ]));
        if (isset($out['secondary_keywords']) && is_array($out['secondary_keywords'])) {
            $out['secondary_keywords'] = json_encode($out['secondary_keywords']);
        }
        if (! $partial) {
            $out['title']         ??= 'Untitled SEO plan';
            $out['focus_keyword'] ??= '';
            $out['status']        ??= 'draft';
        }
        return $out;
    }
}
