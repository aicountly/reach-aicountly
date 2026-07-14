<?php

namespace App\Controllers\Api\V1\Community;

use App\Controllers\BaseApiController;
use App\Models\CommunityOfficialIdentityModel;
use App\Libraries\AuditLogger;
use CodeIgniter\HTTP\ResponseInterface;

class OfficialIdentityController extends BaseApiController
{
    private CommunityOfficialIdentityModel $model;

    public function __construct()
    {
        $this->model = new CommunityOfficialIdentityModel();
    }

    /** GET /community/identities */
    public function index(): ResponseInterface
    {
        $includeInactive = (bool) $this->request->getGet('include_inactive');
        $identities = $includeInactive
            ? $this->model->findAll()
            : $this->model->listActive();

        return $this->response->setJSON(['data' => $identities]);
    }

    /** GET /community/identities/(:segment) */
    public function show(string $slug): ResponseInterface
    {
        $identity = $this->model->findBySlug($slug);
        if (!$identity) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        return $this->response->setJSON(['data' => $identity]);
    }

    /** POST /community/identities */
    public function create(): ResponseInterface
    {
        $body = $this->input() ?: [];

        $required = ['slug', 'display_name'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->response->setStatusCode(422)->setJSON(['error' => "Field '{$field}' is required."]);
            }
        }

        $existing = $this->model->findBySlug($body['slug']);
        if ($existing) {
            return $this->response->setStatusCode(409)->setJSON(['error' => 'Identity with this slug already exists.']);
        }

        $data = [
            'slug'                 => $body['slug'],
            'display_name'         => $body['display_name'],
            'department'           => $body['department'] ?? null,
            'badge_type'           => $body['badge_type'] ?? 'official',
            'disclosure_template'  => $body['disclosure_template'] ?? null,
            'is_active'            => true,
        ];

        $id = $this->model->insert($data, true);
        $identity = $this->model->find($id);

        AuditLogger::record(AuditLogger::COMMUNITY_IDENTITY_CREATED, ['slug' => $body['slug']]);
        return $this->response->setStatusCode(201)->setJSON(['data' => $identity]);
    }

    /** PUT /community/identities/(:segment) */
    public function update(string $slug): ResponseInterface
    {
        $identity = $this->model->findBySlug($slug);
        if (!$identity) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }

        $body = $this->input() ?: [];
        $updateData = array_intersect_key($body, array_flip(['display_name', 'department', 'badge_type', 'disclosure_template', 'is_active']));

        $this->model->update($identity['id'], $updateData);
        AuditLogger::record(AuditLogger::COMMUNITY_IDENTITY_UPDATED, ['slug' => $slug]);
        return $this->response->setJSON(['data' => $this->model->findBySlug($slug)]);
    }

    /** DELETE /community/identities/(:segment) — soft deactivate */
    public function deactivate(string $slug): ResponseInterface
    {
        $identity = $this->model->findBySlug($slug);
        if (!$identity) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
        }
        $this->model->update($identity['id'], ['is_active' => false]);
        AuditLogger::record(AuditLogger::COMMUNITY_IDENTITY_DEACTIVATED, ['slug' => $slug]);
        return $this->response->setJSON(['success' => true]);
    }
}

