<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Content;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Automation\Routine\BlogPlanService;
use App\Libraries\Automation\Routine\RoutineContentPipeline;
use App\Libraries\Automation\Routine\RoutineIntakeRefused;
use App\Libraries\Automation\Routine\RoutineSubmissionView;
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
 *   GET  v1/automation/blog-plan       quota, existing titles, priority topics, links
 *   POST v1/automation/blog-drafts     submit a marketing blog draft (Gemini vets it)
 *   POST v1/automation/kb-drafts       submit a KB article draft (Gemini vets it)
 *   GET  v1/automation/regenerate/next failed item to rewrite first (same slug)
 *
 * A submitted draft is stored, then vetted, approved and queued for
 * publication in the same request where possible; the response says how far
 * it got. Anything that could not finish (vetting unavailable, no cover yet,
 * the worker has not sent it) is carried on by `reach:routine-sweep`.
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

    /**
     * Linkable-URL inventory for authoring-time internal links: the routine
     * weaves 2–4 of these into each draft (and may add its own marked
     * related-reading section, which the payload builder then leaves as-is).
     */
    public function linkRegistry()
    {
        return $this->ok([
            'site_base' => rtrim((string) env('AICOUNTLY_PUBLIC_SITE_BASE_URL', 'https://aicountly.com'), '/'),
            'links'     => (new \App\Libraries\Publishing\LinkRegistryService())->excerpt(150),
        ]);
    }

    public function kbPlan()
    {
        return $this->ok((new KnowledgeBaseDraftIngestService())->plan());
    }

    public function blogPlan()
    {
        return $this->ok((new BlogPlanService())->plan());
    }

    /**
     * GET v1/automation/regenerate/next?type=blog|knowledge_base — the blog
     * and KB routines call this first. Returns (and claims for two hours) the
     * oldest item whose text failed vetting and must be rewritten on the same
     * topic and slug, or `item: null` when there is none.
     */
    public function regenerateNext()
    {
        $type = (string) ($this->request->getGet('type') ?? 'blog');
        if (! in_array($type, ['blog', 'knowledge_base'], true)) {
            return $this->fail('type must be blog or knowledge_base', 422);
        }
        $queue = new \App\Libraries\Content\Quality\RegenerationQueue();

        return $this->ok(['item' => $queue->claimNext($type), 'open' => $queue->counts()]);
    }

    public function storeBlogDraft()
    {
        return $this->ingest(fn (array $input) => (new AutomationDraftIngestService())->ingestBlogDraft($input));
    }

    public function storeKbDraft()
    {
        return $this->ingest(fn (array $input) => (new KnowledgeBaseDraftIngestService())->ingestKbDraft($input));
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $ingest
     */
    private function ingest(callable $ingest)
    {
        try {
            $result = $ingest($this->input());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (RoutineIntakeRefused $e) {
            return $this->fail($e->getMessage(), $e->httpStatus, $e->details ?: null);
        }

        if ($result['status'] === 'ingested' && ($result['submission_id'] ?? 0) > 0) {
            // Vetting a long article (and re-vetting a revision) can take a
            // couple of minutes; a request cut short leaves the submission
            // pending and the sweep finishes it.
            @set_time_limit(600);
            ignore_user_abort(true);
            try {
                $row = (new RoutineContentPipeline())->process((int) $result['submission_id']);
                $result['pipeline'] = RoutineSubmissionView::summary($row);
            } catch (\Throwable $e) {
                log_message('error', '[AutomationIngest] pipeline pass failed: ' . $e->getMessage());
                $result['pipeline'] = ['status' => 'pending_vetting', 'note' => 'Stored; the scheduled sweep will vet and publish it.'];
            }
        } elseif (($result['submission_id'] ?? 0) > 0) {
            $row = (new \App\Libraries\Automation\Routine\RoutineSubmissions())->find((int) $result['submission_id']);
            if ($row !== null) {
                $result['pipeline'] = RoutineSubmissionView::summary($row);
            }
        }

        return $this->ok($result, $result['status'] === 'ingested' ? 201 : 200);
    }
}
