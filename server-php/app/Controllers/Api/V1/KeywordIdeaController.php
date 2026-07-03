<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\KeywordIdeaModel;

class KeywordIdeaController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new KeywordIdeaModel();
        foreach (['status', 'priority', 'source'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function store()
    {
        $body = $this->input();
        $row  = array_intersect_key($body, array_flip(['keyword', 'search_intent', 'priority', 'source', 'status', 'notes']));
        $row['created_by'] = $this->userId();
        $row['status']    ??= 'open';
        $row['priority']  ??= 'medium';
        $row['source']    ??= 'manual';
        $m = new KeywordIdeaModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m = new KeywordIdeaModel();
        if (! $m->find($id)) {
            return $this->fail('Keyword idea not found.', 404);
        }
        $body   = $this->input();
        $update = array_intersect_key($body, array_flip(['keyword', 'search_intent', 'priority', 'source', 'status', 'notes']));
        $m->update($id, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new KeywordIdeaModel();
        if (! $m->find($id)) {
            return $this->fail('Keyword idea not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        return $this->ok(['message' => 'Archived.']);
    }
}
