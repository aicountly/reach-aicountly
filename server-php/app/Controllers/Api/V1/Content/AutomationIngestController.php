<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Blog\AutomationDraftIngestService;
use App\Libraries\Blog\ContentBaseService;
use App\Libraries\Media\MediaGalleryDeficitService;
use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseDraftIngestService;

/**
 * Endpoints for the Claude Code routines (X-Automation-Token authenticated).
 *
 *   GET  v1/automation/content-base    what to write next + strategy base
 *   GET  v1/automation/gallery/status  cover deficit + per-entry prompts
 *   GET  v1/automation/kb-plan         today's per-product KB quotas
 *   POST v1/automation/blog-drafts     submit a verified marketing blog draft
 *   POST v1/automation/kb-drafts       submit a verified KB article draft
 */
class AutomationIngestController extends BaseApiController
{
    public function contentBase()
    {
        $service = new ContentBaseService();

        return $this->ok([
            'base_markdown' => $service->baseMarkdown(),
            'blog_index'    => $service->blogIndex(),
            'last_sync'     => $service->lastSyncRun(),
        ]);
    }

    public function galleryStatus()
    {
        return $this->ok((new MediaGalleryDeficitService())->report());
    }

    public function kbPlan()
    {
        return $this->ok((new KnowledgeBaseDraftIngestService())->plan());
    }

    public function storeBlogDraft()
    {
        try {
            $result = (new AutomationDraftIngestService())->ingestBlogDraft($this->input());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($result, $result['status'] === 'ingested' ? 201 : 200);
    }

    public function storeKbDraft()
    {
        try {
            $result = (new KnowledgeBaseDraftIngestService())->ingestKbDraft($this->input());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($result, $result['status'] === 'ingested' ? 201 : 200);
    }
}
