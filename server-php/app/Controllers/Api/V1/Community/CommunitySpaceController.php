<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseController;
use App\Models\CommunitySpaceModel;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class CommunitySpaceController extends BaseController
{
    private CommunitySpaceModel $model;

    public function __construct()
    {
        $this->model = new CommunitySpaceModel();
    }

    /** GET /community/spaces */
    public function index(): ResponseInterface
    {
        $spaces = $this->model->listActive();
        return $this->response->setJSON(['data' => $spaces]);
    }

    /** GET /community/spaces/(:segment) */
    public function show(string $slug): ResponseInterface
    {
        $space = $this->model->findBySlug($slug);
        if (!$space) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        return $this->response->setJSON(['data' => $space]);
    }

    /** POST /community/spaces */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];
        $required = ['slug', 'title'];
        foreach ($required as $f) {
            if (empty($body[$f])) {
                return $this->response->setStatusCode(422)->setJSON(['error' => "Field '{$f}' is required."]);
            }
        }

        $data = [
            'slug'                    => $body['slug'],
            'title'                   => $body['title'],
            'description'             => $body['description'] ?? null,
            'visibility'              => $body['visibility'] ?? 'public',
            'moderation_mode'         => $body['moderation_mode'] ?? 'post_review',
            'official_answer_policy'  => $body['official_answer_policy'] ?? 'optional',
            'is_active'               => true,
        ];

        $id    = $this->model->insert($data, true);
        $space = $this->model->find($id);
        AuditLogger::log(AuditLogger::COMMUNITY_SPACE_CREATED, ['slug' => $body['slug']]);
        return $this->response->setStatusCode(201)->setJSON(['data' => $space]);
    }

    /** PUT /community/spaces/(:segment) */
    public function update(string $slug): ResponseInterface
    {
        $space = $this->model->findBySlug($slug);
        if (!$space) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        $body       = $this->request->getJSON(true) ?? [];
        $updateData = array_intersect_key($body, array_flip(['title', 'description', 'visibility', 'moderation_mode', 'official_answer_policy', 'is_active']));
        $this->model->update($space['id'], $updateData);
        AuditLogger::log(AuditLogger::COMMUNITY_SPACE_UPDATED, ['slug' => $slug]);
        return $this->response->setJSON(['data' => $this->model->findBySlug($slug)]);
    }
}
