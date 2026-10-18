<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Content\ContentBaseOverviewService;

/**
 * Console view of the living content base (reach_content_base_entries). The
 * routines add topics and Reach moves them along through
 * v1/automation/content-base/*; git holds only the seed baseline.
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
        $service  = new ContentBaseService();
        $overview = (new ContentBaseOverviewService())->overview();
        $blog     = $overview['surfaces']['blog'] ?? ['entries' => [], 'counts' => []];

        $entries = array_map(static fn (array $e): array => [
            'key'              => $e['key'],
            'content_base_key' => $e['content_base_key'],
            'title'            => $e['title'],
            'portfolio_stream' => $e['stream'],
            'target_date'      => $e['target_date'],
            'status'           => $e['status'],
            'status_reason'    => $e['status_reason'],
            'origin'           => $e['origin'],
            'brief_prompt'     => $e['prompt'],
            'sync'             => ['state' => $e['status'], 'content_item_id' => $e['content_item_id']],
        ], $blog['entries']);

        return $this->ok([
            'base_markdown' => $service->baseMarkdown(),
            'base_path'     => $service->basePath(),
            'index_meta'    => ['version' => null, 'updated_at' => null],
            'entries'       => $entries,
            'counts'        => $blog['counts'],
            'routine'       => $blog['routine'] ?? null,
            'kb_index'      => $service->knowledgeBaseIndex(),
            'last_sync'     => $overview['last_sync'] ?? null,
        ]);
    }
}
