<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Content\ContentBaseOverviewService;

/**
 * Read-only console view of the repo-versioned content base. Edits happen in
 * git (rsync --delete would clobber server-side edits on every deploy).
 */
class ContentBaseController extends BaseApiController
{
    /**
     * The whole base — blog, knowledge base and community Q&A — in one shape.
     *
     * The base has always fed all three surfaces; only the blog half was ever
     * visible, and only from inside the Blog Command Centre. This is the
     * surface-neutral view Quality Centre links to.
     */
    public function overview()
    {
        return $this->ok((new ContentBaseOverviewService())->overview());
    }

    /** Blog-only view, kept for the Blog Command Centre roadmap tab. */
    public function index()
    {
        $service = new ContentBaseService();
        $db      = db_connect();

        $index   = $service->blogIndex();
        $entries = [];
        foreach ((array) ($index['entries'] ?? []) as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $candidate = $key !== '' ? $db->table('reach_topic_candidates')
                ->select('id, status, content_item_id, updated_at')
                ->where('content_base_key', $key)
                ->get()->getRowArray() : null;

            $entry['sync'] = $candidate
                ? ['state' => 'synced', 'candidate_status' => $candidate['status'], 'content_item_id' => $candidate['content_item_id']]
                : ['state' => 'pending_sync'];
            $entries[] = $entry;
        }

        return $this->ok([
            'base_markdown' => $service->baseMarkdown(),
            'base_path'     => $service->basePath(),
            'index_meta'    => ['version' => $index['version'] ?? null, 'updated_at' => $index['updated_at'] ?? null],
            'entries'       => $entries,
            'kb_index'      => $service->knowledgeBaseIndex(),
            'last_sync'     => $service->lastSyncRun(),
        ]);
    }
}
