<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\LandingPageModel;

class LandingPageController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q      = new LandingPageModel();
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
        $row = (new LandingPageModel())->find($id);
        if (! $row) {
            return $this->fail('Landing page not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $m    = new LandingPageModel();
        $row  = array_intersect_key($body, array_flip(['campaign_id', 'slug', 'title', 'meta', 'body', 'status']));
        if (isset($row['meta']) && is_array($row['meta'])) {
            $row['meta'] = json_encode($row['meta']);
        }
        $row['status']     ??= 'draft';
        $row['created_by']   = $this->userId();
        $row['slug']         = $this->uniqueSlug($m, (string) ($row['slug'] ?? ($row['title'] ?? 'landing')));
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('landing.create', 'landing', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m   = new LandingPageModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Landing page not found.', 404);
        }
        $body   = $this->input();
        $update = array_intersect_key($body, array_flip(['campaign_id', 'slug', 'title', 'meta', 'body', 'status', 'published_at']));
        if (isset($update['meta']) && is_array($update['meta'])) {
            $update['meta'] = json_encode($update['meta']);
        }
        if (isset($update['slug'])) {
            $update['slug'] = $this->uniqueSlug($m, (string) $update['slug'], $id);
        }
        $m->update($id, $update);
        $this->audit('landing.update', 'landing', $id, $row, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new LandingPageModel();
        if (! $m->find($id)) {
            return $this->fail('Landing page not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        $this->audit('landing.archive', 'landing', $id);
        return $this->ok(['message' => 'Archived.']);
    }

    private function uniqueSlug(LandingPageModel $m, string $base, ?int $excludeId = null): string
    {
        $slug = strtolower(trim($base));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-') ?: 'landing';
        $try  = $slug;
        $n    = 1;
        while (true) {
            $q = $m->where('slug', $try);
            if ($excludeId) {
                $q->where('id !=', $excludeId);
            }
            if ($q->countAllResults() === 0) {
                return $try;
            }
            $try = $slug . '-' . (++$n);
        }
    }
}
