<?php

namespace App\Controllers\Api\V1\Content;

use App\Models\Content\ContentPublicationTargetModel;

/**
 * Publication channel target management.
 *
 * Routes:
 *   GET  /v1/content/publication-targets
 *   POST /v1/content/publication-targets
 *   GET  /v1/content/publication-targets/:id
 *   PUT  /v1/content/publication-targets/:id
 */
class ContentPublicationTargetController extends BaseContentController
{
    private ContentPublicationTargetModel $targets;

    public function __construct()
    {
        parent::__construct();
        $this->targets = new ContentPublicationTargetModel();
    }

    public function index()
    {
        return $this->ok(['targets' => $this->targets->activeTargets()]);
    }

    public function show($id)
    {
        $target = $this->targets->find((int) $id);
        if (!$target) {
            return $this->fail('Publication target not found.', 404);
        }
        return $this->ok($target);
    }

    public function create()
    {
        $body               = $this->input();
        $body['created_by'] = $this->userId();
        $id                 = $this->targets->insert($body, true);
        if (!$id) {
            return $this->fail('Failed to create publication target.', 422);
        }
        return $this->ok($this->targets->find($id), 201);
    }

    public function update($id)
    {
        $target = $this->targets->find((int) $id);
        if (!$target) {
            return $this->fail('Publication target not found.', 404);
        }

        $body               = $this->input();
        $body['updated_by'] = $this->userId();
        $this->targets->update($id, $body);
        return $this->ok($this->targets->find($id));
    }
}
