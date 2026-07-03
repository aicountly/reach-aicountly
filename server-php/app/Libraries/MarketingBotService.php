<?php

namespace App\Libraries;

use App\Models\ApprovalModel;
use App\Models\BlogPostModel;
use App\Models\BotSettingModel;
use App\Models\CampaignModel;
use App\Models\ContentCalendarItemModel;
use App\Models\CreativeBriefModel;
use App\Models\KeywordIdeaModel;
use App\Models\MarketingBotQueueModel;
use App\Models\SeoPlanModel;
use App\Models\SocialPostModel;
use Config\Services;

/**
 * Reach Marketing Bot.
 *
 * Enforces the Confirm/Auto policy from reach_bot_settings:
 *   - Confirm mode: every action creates a MarketingBotReport with
 *     approval_status=pending and a reach_approvals row. Nothing is
 *     published/posted until a superadmin approves.
 *   - Auto mode: internal-only actions listed in allowed_auto_actions
 *     execute immediately (report approval_status=not_required). Public-
 *     publishing actions ALWAYS require approval, regardless of mode.
 *
 * Public-publish actions:
 *   - queue_approved_publishing (approved content → external channels)
 *   - generate_blog_draft when {publish:true}
 *   - generate_social_posts when {publish:true}
 *   - generate_campaign_copy when {publish:true}
 *
 * LLM keys are read from .env. If both are empty the service still runs
 * (it just returns stubbed content) so the whole pipeline can be tested.
 */
class MarketingBotService
{
    public const ACTIONS = [
        'generate_campaign_ideas',
        'generate_campaign_copy',
        'generate_blog_draft',
        'generate_seo_brief',
        'generate_social_posts',
        'generate_creative_brief',
        'generate_content_calendar',
        'suggest_hashtags_keywords',
        'generate_analytics_summary',
        'recommend_campaign_improvements',
        'prepare_approval_package',
        'queue_approved_publishing',
    ];

    /** Actions that touch AICOUNTLY.com / external social — always need approval. */
    private const PUBLIC_PUBLISH_ACTIONS = ['queue_approved_publishing'];

    private BotSettingModel $botSettings;
    private MarketingBotQueueModel $queue;
    private MarketingBotReporter $reporter;
    private ApprovalModel $approvals;

    public function __construct()
    {
        $this->botSettings = new BotSettingModel();
        $this->queue       = new MarketingBotQueueModel();
        $this->reporter    = Services::marketingBotReporter();
        $this->approvals   = new ApprovalModel();
    }

    /**
     * Dispatch a bot action.
     *
     * @return array{
     *   queue_id:int,
     *   report_id:int,
     *   approval_id:?int,
     *   status:string,      // 'completed' | 'awaiting_approval' | 'failed'
     *   mode:string,
     *   result:array,
     * }
     */
    public function dispatch(string $action, array $payload, ?int $userId): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown bot action: {$action}");
        }

        $mode              = $this->botSettings->currentMode();
        $allowedAutoAllow  = $this->botSettings->currentAllowedAutoActions();
        $requiresApproval  = $this->requiresApproval($action, $payload, $mode, $allowedAutoAllow);

        $queueId = $this->createQueueRow($action, $payload, $userId);

        try {
            $result = $this->execute($action, $payload, $requiresApproval);
        } catch (\Throwable $e) {
            $this->queue->update($queueId, [
                'status'        => 'failed',
                'finished_at'   => date('Y-m-d H:i:s'),
                'error_message' => substr($e->getMessage(), 0, 4000),
            ]);
            $reportId = $this->reporter->record([
                'queue_id'                => $queueId,
                'action'                  => $action,
                'understanding'           => 'Failed to execute action.',
                'mode'                    => $mode,
                'errors'                  => ['exception' => $e->getMessage()],
                'next_recommended_action' => 'Retry manually after resolving the error.',
                'created_by'              => $userId,
                'approval_status'         => 'not_required',
                'publishing_status'       => 'none',
            ]);
            return [
                'queue_id'    => $queueId,
                'report_id'   => $reportId,
                'approval_id' => null,
                'status'      => 'failed',
                'mode'        => $mode,
                'result'      => ['error' => $e->getMessage()],
            ];
        }

        // Persist content for internal-only actions right away (idea rows,
        // calendar rows, drafts). Public-facing side effects (posting to
        // social/publishing to AICOUNTLY.com) are ONLY performed by
        // executeApprovedPublishing() after approval.
        $persisted = $this->persistInternalArtifacts($action, $payload, $result, $userId, $requiresApproval);

        $approvalStatus = $requiresApproval ? 'pending' : 'not_required';
        $publishStatus  = $requiresApproval ? 'pending' : ($this->isPublicPublish($action, $payload) ? 'queued' : 'none');
        $actionTaken    = $requiresApproval
            ? 'Draft/artifact prepared. Awaiting superadmin approval before public publication.'
            : ($persisted['message'] ?? 'Executed internal-only marketing bot action.');

        $reportId = $this->reporter->record([
            'queue_id'                => $queueId,
            'action'                  => $action,
            'understanding'           => $result['understanding']       ?? $this->defaultUnderstanding($action, $payload),
            'data_accessed'           => $result['data_accessed']       ?? [],
            'content_generated'       => $result['content_generated']   ?? [],
            'recommended_action'      => $result['recommended_action']  ?? null,
            'action_taken'            => $actionTaken,
            'approval_status'         => $approvalStatus,
            'publishing_status'       => $publishStatus,
            'next_recommended_action' => $requiresApproval
                ? 'Review the generated content in Reach → Bot Queue and approve or reject.'
                : ($result['next_recommended_action'] ?? null),
            'mode'                    => $mode,
            'evidence'                => $result['evidence'] ?? [],
            'errors'                  => $result['errors']   ?? [],
            'created_by'              => $userId,
        ]);

        $approvalId = null;
        if ($requiresApproval) {
            $approvalId = (int) $this->approvals->insert([
                'subject_type' => 'bot',
                'subject_id'   => $reportId,
                'summary'      => sprintf('Marketing Bot: %s', $action),
                'requested_by' => $userId,
                'decision'     => 'pending',
                'metadata'     => json_encode(['action' => $action, 'payload' => $payload], JSON_UNESCAPED_SLASHES),
            ], true);
        }

        $this->queue->update($queueId, [
            'status'         => $requiresApproval ? 'completed' : 'completed',
            'result_summary' => json_encode([
                'report_id'         => $reportId,
                'approval_id'       => $approvalId,
                'requires_approval' => $requiresApproval,
                'artifacts'         => $persisted['artifacts'] ?? [],
            ], JSON_UNESCAPED_SLASHES),
            'finished_at'    => date('Y-m-d H:i:s'),
        ]);

        return [
            'queue_id'    => $queueId,
            'report_id'   => $reportId,
            'approval_id' => $approvalId,
            'status'      => $requiresApproval ? 'awaiting_approval' : 'completed',
            'mode'        => $mode,
            'result'      => $result + ['artifacts' => $persisted['artifacts'] ?? []],
        ];
    }

    /**
     * Called when a superadmin approves a bot report. Runs the public
     * publishing side effects for actions that produce public content.
     */
    public function executeApprovedPublishing(int $reportId, int $approverId): array
    {
        $report = (new \App\Models\MarketingBotReportModel())->find($reportId);
        if (! $report) {
            return ['ok' => false, 'error' => 'Report not found.'];
        }
        // Publishing execution proper is delegated to the target module
        // (Blog → AicountlySitePublisher, Social → manual queue, etc.).
        // Here we simply mark the report and downstream artifacts as
        // "queued for publishing"; specific controllers do the real work.
        $this->reporter->markApproved($reportId, $approverId, 'queued');
        return ['ok' => true, 'report_id' => $reportId];
    }

    // -----------------------------------------------------------------------
    // Action execution — LLM-agnostic. Real LLM calls plug in here later.
    // -----------------------------------------------------------------------

    private function execute(string $action, array $payload, bool $requiresApproval): array
    {
        $llmConfigured = $this->llmConfigured();
        $stubNote      = $llmConfigured
            ? 'LLM keys detected. Content generated by LLM stub (integration pending).'
            : 'REACH_BOT_OPENAI_KEY / REACH_BOT_ANTHROPIC_KEY not configured — returning placeholder content.';

        return match ($action) {
            'generate_campaign_ideas' => $this->generateCampaignIdeas($payload, $stubNote),
            'generate_campaign_copy'  => $this->generateCampaignCopy($payload, $stubNote),
            'generate_blog_draft'     => $this->generateBlogDraft($payload, $stubNote),
            'generate_seo_brief'      => $this->generateSeoBrief($payload, $stubNote),
            'generate_social_posts'   => $this->generateSocialPosts($payload, $stubNote),
            'generate_creative_brief' => $this->generateCreativeBrief($payload, $stubNote),
            'generate_content_calendar' => $this->generateContentCalendar($payload, $stubNote),
            'suggest_hashtags_keywords' => $this->suggestHashtagsKeywords($payload, $stubNote),
            'generate_analytics_summary' => $this->generateAnalyticsSummary($payload, $stubNote),
            'recommend_campaign_improvements' => $this->recommendCampaignImprovements($payload, $stubNote),
            'prepare_approval_package' => $this->prepareApprovalPackage($payload, $stubNote),
            'queue_approved_publishing' => $this->queueApprovedPublishing($payload, $stubNote),
            default => throw new \InvalidArgumentException("Unhandled action: {$action}"),
        };
    }

    private function generateCampaignIdeas(array $payload, string $note): array
    {
        $topic = (string) ($payload['topic'] ?? 'AICOUNTLY marketing');
        return [
            'understanding'      => "Generate campaign ideas for topic: {$topic}",
            'data_accessed'      => ['recent_campaigns' => (new CampaignModel())->orderBy('created_at', 'DESC')->limit(5)->findAll()],
            'content_generated'  => [
                'ideas' => [
                    ['title' => "{$topic}: value-driven onboarding series", 'channel' => 'email'],
                    ['title' => "{$topic}: social carousel — 5 accounting mistakes AI catches", 'channel' => 'linkedin'],
                    ['title' => "{$topic}: webinar with a CA thought leader", 'channel' => 'webinar'],
                ],
                'note' => $note,
            ],
            'recommended_action' => 'Pick 1–2 ideas and dispatch generate_campaign_copy or generate_creative_brief.',
        ];
    }

    private function generateCampaignCopy(array $payload, string $note): array
    {
        return [
            'understanding'      => 'Generate campaign copy variants.',
            'content_generated'  => [
                'subject_line' => 'Save 3 hours a week on GST reconciliation',
                'preheader'    => 'AICOUNTLY does the matching. You review.',
                'body'         => "Hi {{name}},\n\nAICOUNTLY reconciles your GSTR-2A automatically. See how in 90 seconds.\n\n— The AICOUNTLY team",
                'cta'          => 'Book a 15-min demo',
                'note'         => $note,
            ],
            'recommended_action' => 'Save the copy to the target campaign and prepare an approval package.',
        ];
    }

    private function generateBlogDraft(array $payload, string $note): array
    {
        $topic = (string) ($payload['topic'] ?? 'Introducing AICOUNTLY');
        return [
            'understanding'      => "Generate blog draft for topic: {$topic}",
            'content_generated'  => [
                'title'          => $topic,
                'slug'           => $this->slugify($topic),
                'excerpt'        => "A quick look at how {$topic} helps accountants save hours every week.",
                'body'           => "# {$topic}\n\nDraft body generated by Marketing Bot. Replace with LLM output.\n\n{$note}",
                'seo_title'      => $topic . ' | AICOUNTLY',
                'seo_description'=> "Learn how {$topic} helps small firms automate accounting.",
                'focus_keyword'  => $topic,
                'tags'           => ['accounting', 'automation', 'AI'],
            ],
            'recommended_action' => 'Move blog through SEO review → internal review → approval before publishing.',
        ];
    }

    private function generateSeoBrief(array $payload, string $note): array
    {
        $keyword = (string) ($payload['focus_keyword'] ?? 'AI for accountants');
        return [
            'understanding'      => "Generate SEO brief for focus keyword: {$keyword}",
            'content_generated'  => [
                'focus_keyword'      => $keyword,
                'secondary_keywords' => ['AI accounting software', 'automation for CAs', 'GST reconciliation AI'],
                'suggested_headings' => ['What is ' . $keyword, 'Why it matters in 2026', 'How AICOUNTLY helps'],
                'target_length'      => '1200-1500 words',
                'note'               => $note,
            ],
            'recommended_action' => 'Attach brief to a blog draft or content calendar entry.',
        ];
    }

    private function generateSocialPosts(array $payload, string $note): array
    {
        $topic = (string) ($payload['topic'] ?? 'AICOUNTLY update');
        return [
            'understanding'      => "Generate social posts across LinkedIn, X, Instagram for topic: {$topic}",
            'content_generated'  => [
                'posts' => [
                    ['channel' => 'linkedin', 'content' => "Announcing: {$topic}. Here's what changes for accountants →"],
                    ['channel' => 'twitter',  'content' => "{$topic} — the 2-minute recap."],
                    ['channel' => 'instagram','content' => "New: {$topic}. Swipe for the details."],
                ],
                'note' => $note,
            ],
            'recommended_action' => 'Approve or edit posts in the Social Planner, then queue for posting.',
        ];
    }

    private function generateCreativeBrief(array $payload, string $note): array
    {
        return [
            'understanding'      => 'Generate a creative brief for the designer / video team.',
            'content_generated'  => [
                'audience'     => 'CA firm owners, 3–20 person practices in India',
                'value_prop'   => 'AICOUNTLY saves 6+ hours a week per accountant on reconciliation and reporting.',
                'deliverables' => ['1x hero video (30s)', '3x carousel', '1x static ad', '1x landing hero image'],
                'tone'         => 'Practical, credible, warm.',
                'note'         => $note,
            ],
            'recommended_action' => 'Save to Creative Briefs, share with design/video team.',
        ];
    }

    private function generateContentCalendar(array $payload, string $note): array
    {
        $days = max(7, (int) ($payload['days'] ?? 14));
        $items = [];
        for ($i = 0; $i < $days; $i++) {
            $items[] = [
                'date'      => date('Y-m-d', strtotime("+{$i} days")),
                'item_kind' => $i % 3 === 0 ? 'blog' : ($i % 3 === 1 ? 'social' : 'email'),
                'title'     => 'Suggested marketing action for ' . date('D d M', strtotime("+{$i} days")),
            ];
        }
        return [
            'understanding'      => "Generate {$days}-day content calendar.",
            'content_generated'  => ['items' => $items, 'note' => $note],
            'recommended_action' => 'Import selected items into the Content Calendar module.',
        ];
    }

    private function suggestHashtagsKeywords(array $payload, string $note): array
    {
        $topic = (string) ($payload['topic'] ?? 'accounting AI');
        return [
            'understanding'      => "Suggest hashtags and keywords for topic: {$topic}",
            'content_generated'  => [
                'hashtags' => ['#AICOUNTLY', '#AIforCAs', '#AccountingAI', '#GSTAutomation', '#FinTechIndia'],
                'keywords' => [$topic, 'AI accounting software', 'automation for accountants', 'AICOUNTLY features'],
                'note'     => $note,
            ],
            'recommended_action' => 'Save selected keywords to Keyword Ideas and hashtags to relevant social posts.',
        ];
    }

    private function generateAnalyticsSummary(array $payload, string $note): array
    {
        return [
            'understanding'      => 'Summarise recent marketing performance.',
            'data_accessed'      => [
                'campaigns_recent' => (new CampaignModel())->orderBy('created_at', 'DESC')->limit(10)->countAllResults(),
                'blogs_recent'     => (new BlogPostModel())->where('status', 'published')->countAllResults(),
                'leads_recent'     => (new \App\Models\LeadModel())->countAllResults(),
            ],
            'content_generated'  => [
                'headline' => 'Marketing operations digest',
                'note'     => $note,
            ],
            'recommended_action' => 'Share the digest with leadership; import into weekly report.',
        ];
    }

    private function recommendCampaignImprovements(array $payload, string $note): array
    {
        return [
            'understanding'      => 'Recommend improvements to recent campaigns.',
            'content_generated'  => [
                'recommendations' => [
                    'Try shorter subject lines (< 42 chars) on next email campaign.',
                    'Add carousel format to LinkedIn plan — currently zero carousels.',
                    'Retarget landing-page visitors who did not convert within 7 days via email.',
                ],
                'note' => $note,
            ],
            'recommended_action' => 'Attach recommendations to the target campaign and re-run copy generation.',
        ];
    }

    private function prepareApprovalPackage(array $payload, string $note): array
    {
        $subjectType = (string) ($payload['subject_type'] ?? '');
        $subjectId   = (int) ($payload['subject_id'] ?? 0);
        return [
            'understanding'      => "Prepare approval package for {$subjectType}#{$subjectId}",
            'content_generated'  => [
                'checklist' => [
                    'Content reviewed for accuracy',
                    'Brand tone approved',
                    'SEO fields populated',
                    'Compliance verified (no unverified claims)',
                    'Attribution / UTM values correct',
                ],
                'note' => $note,
            ],
            'recommended_action' => 'Superadmin approves the linked draft in the Approvals module.',
        ];
    }

    private function queueApprovedPublishing(array $payload, string $note): array
    {
        return [
            'understanding'      => 'Queue approved content for public publishing.',
            'content_generated'  => [
                'targets' => $payload['targets'] ?? [],
                'note'    => $note,
            ],
            'recommended_action' => 'Requires superadmin approval. Once approved, Reach publishes to configured channels.',
        ];
    }

    // -----------------------------------------------------------------------
    // Approval policy
    // -----------------------------------------------------------------------

    private function requiresApproval(string $action, array $payload, string $mode, array $allowedAutoActions): bool
    {
        if ($this->isPublicPublish($action, $payload)) {
            return true; // always
        }
        if ($mode === 'auto' && in_array($action, $allowedAutoActions, true)) {
            return false;
        }
        return true; // confirm mode default
    }

    private function isPublicPublish(string $action, array $payload): bool
    {
        if (in_array($action, self::PUBLIC_PUBLISH_ACTIONS, true)) {
            return true;
        }
        if (in_array($action, ['generate_blog_draft', 'generate_social_posts', 'generate_campaign_copy'], true)
            && ! empty($payload['publish'])) {
            return true;
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Persistence of internal artifacts (ideas, calendar, drafts)
    // -----------------------------------------------------------------------

    private function persistInternalArtifacts(string $action, array $payload, array $result, ?int $userId, bool $requiresApproval): array
    {
        $artifacts = [];
        $message   = 'Executed marketing bot action.';

        // Only persist artifacts that don't require approval OR are internal drafts.
        if ($action === 'generate_blog_draft') {
            $c = $result['content_generated'] ?? [];
            $blog = new BlogPostModel();
            $slug = (string) ($c['slug'] ?? '');
            $slug = $this->uniqueBlogSlug($blog, $slug !== '' ? $slug : 'bot-draft-' . time());
            $blog->insert([
                'title'           => $c['title']           ?? 'Untitled bot draft',
                'slug'            => $slug,
                'excerpt'         => $c['excerpt']         ?? null,
                'content'         => $c['body']            ?? '',
                'category'        => $c['category']        ?? null,
                'tags'            => isset($c['tags']) ? json_encode($c['tags']) : null,
                'seo_title'       => $c['seo_title']       ?? null,
                'seo_description' => $c['seo_description'] ?? null,
                'focus_keyword'   => $c['focus_keyword']   ?? null,
                'status'          => 'draft',
                'approval_status' => $requiresApproval ? 'pending' : 'not_required',
                'bot_generated'   => true,
                'current_version' => 1,
                'created_by'      => $userId,
            ]);
            $artifacts[] = ['type' => 'blog_post', 'id' => (int) $blog->db->insertID(), 'slug' => $slug];
            $message = 'Blog draft created in Reach (status=draft).';
        }

        if ($action === 'generate_content_calendar') {
            $items = $result['content_generated']['items'] ?? [];
            $ccal  = new ContentCalendarItemModel();
            foreach ($items as $item) {
                $ccal->insert([
                    'date'       => $item['date'],
                    'item_kind'  => $item['item_kind'],
                    'title'      => $item['title'] ?? null,
                    'created_by' => $userId,
                ]);
                $artifacts[] = ['type' => 'calendar_item', 'id' => (int) $ccal->db->insertID()];
            }
            $message = sprintf('Inserted %d calendar items.', count($items));
        }

        if ($action === 'suggest_hashtags_keywords') {
            $keywords = $result['content_generated']['keywords'] ?? [];
            $kw = new KeywordIdeaModel();
            foreach ($keywords as $k) {
                $kw->insert([
                    'keyword'    => (string) $k,
                    'source'     => 'bot',
                    'status'     => 'open',
                    'created_by' => $userId,
                ]);
                $artifacts[] = ['type' => 'keyword_idea', 'id' => (int) $kw->db->insertID()];
            }
            $message = sprintf('Added %d keyword ideas.', count($keywords));
        }

        if ($action === 'generate_seo_brief') {
            $c = $result['content_generated'] ?? [];
            $seo = new SeoPlanModel();
            $seo->insert([
                'title'              => 'SEO Brief: ' . ($c['focus_keyword'] ?? 'brief'),
                'focus_keyword'      => (string) ($c['focus_keyword'] ?? ''),
                'secondary_keywords' => json_encode($c['secondary_keywords'] ?? []),
                'brief'              => json_encode($c),
                'status'             => 'draft',
                'bot_generated'      => true,
                'created_by'         => $userId,
            ]);
            $artifacts[] = ['type' => 'seo_plan', 'id' => (int) $seo->db->insertID()];
            $message = 'SEO brief saved.';
        }

        if ($action === 'generate_creative_brief') {
            $c = $result['content_generated'] ?? [];
            $cb = new CreativeBriefModel();
            $cb->insert([
                'title'         => 'Creative Brief',
                'brief'         => json_encode($c),
                'audience'      => $c['audience'] ?? null,
                'deliverables'  => json_encode($c['deliverables'] ?? []),
                'status'        => 'draft',
                'bot_generated' => true,
                'created_by'    => $userId,
            ]);
            $artifacts[] = ['type' => 'creative_brief', 'id' => (int) $cb->db->insertID()];
            $message = 'Creative brief saved.';
        }

        if ($action === 'generate_social_posts') {
            $posts = $result['content_generated']['posts'] ?? [];
            $sp = new SocialPostModel();
            foreach ($posts as $p) {
                $sp->insert([
                    'channel'         => $p['channel'],
                    'content'         => $p['content'],
                    'status'          => 'draft',
                    'approval_status' => $requiresApproval ? 'pending' : 'not_required',
                    'bot_generated'   => true,
                    'created_by'      => $userId,
                ]);
                $artifacts[] = ['type' => 'social_post', 'id' => (int) $sp->db->insertID()];
            }
            $message = sprintf('Saved %d social post drafts.', count($posts));
        }

        return ['artifacts' => $artifacts, 'message' => $message];
    }

    private function createQueueRow(string $action, array $payload, ?int $userId): int
    {
        $this->queue->insert([
            'action'       => $action,
            'payload'      => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'status'       => 'running',
            'requested_by' => $userId,
            'started_at'   => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->queue->db->insertID();
    }

    private function defaultUnderstanding(string $action, array $payload): string
    {
        return sprintf('Dispatch of %s with %d input keys.', $action, count($payload));
    }

    private function llmConfigured(): bool
    {
        return ((string) env('REACH_BOT_OPENAI_KEY', '') !== '')
            || ((string) env('REACH_BOT_ANTHROPIC_KEY', '') !== '');
    }

    private function slugify(string $input): string
    {
        $s = strtolower(trim($input));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'bot-draft';
    }

    private function uniqueBlogSlug(BlogPostModel $blog, string $base): string
    {
        $slug = $base;
        $n    = 1;
        while ($blog->where('slug', $slug)->countAllResults() > 0) {
            $slug = $base . '-' . (++$n);
        }
        return $slug;
    }
}
