<?php

namespace App\Controllers\Api\V1\Content;

use App\Models\Content\ContentBriefModel;

/**
 * Brief CRUD for a content item (1:1 relationship).
 *
 * Routes:
 *   GET  /v1/content/items/:id/brief
 *   POST /v1/content/items/:id/brief
 *   PUT  /v1/content/items/:id/brief
 */
class ContentBriefController extends BaseContentController
{
    private ContentBriefModel $briefs;

    public function __construct()
    {
        parent::__construct();
        $this->briefs = new ContentBriefModel();
    }

    public function show($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        return $this->ok($this->briefs->forItem($item['id']) ?? (object) []);
    }

    public function upsert($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body = $this->input();
        $body['content_item_id'] = $item['id'];
        $body['updated_by']      = $this->userId();

        $existing = $this->briefs->forItem($item['id']);
        if ($existing) {
            $this->briefs->update($existing['id'], $body);
            $brief = $this->briefs->find($existing['id']);
        } else {
            $body['created_by'] = $this->userId();
            $briefId = $this->briefs->insert($body, true);
            $brief   = $this->briefs->find($briefId);
        }

        return $this->ok($brief);
    }
}
