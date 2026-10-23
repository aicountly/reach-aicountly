<?php

namespace App\Libraries\Settings;

use App\Models\SettingModel;
use RuntimeException;

/**
 * Operator-editable configuration for the automation pipelines (AI keys, the
 * vetting model per content type, image model, volumes, report recipients,
 * third-party credentials). Values live in `reach_settings`; secrets are
 * encrypted at rest. Every key has a registry entry so the Settings → AI &
 * Automation screen knows what to offer, and an optional `.env` fallback so a
 * deployment configured the old way keeps working until someone saves a value.
 *
 * Read with get() / secret(); write with set(). Never read these keys straight
 * from SettingModel — the secret ones come back as ciphertext.
 */
final class ReachConfig
{
    private const ENC_PREFIX = 'v1:';

    /** A Claude Code Routines API trigger ("fire") URL. */
    public const ROUTINE_FIRE_URL_PATTERN = '#^https://api\.anthropic\.com/v1/claude_code/routines/trig_[A-Za-z0-9_-]{6,120}/fire$#';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * key => [group, label, type (string|secret|int|bool|email_list|model|choice|json_object), env?, default?, help?,
     *         options? (choice), format? (https_url|account_id — extra validation of a string)]
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // ── AI providers ────────────────────────────────────────────────
            'ai.gemini.api_key' => ['group' => 'AI providers', 'label' => 'Gemini API key', 'type' => 'secret', 'env' => 'AI_GEMINI_API_KEY',
                'help' => 'Google AI Studio key. Used for vetting, insights and AI-visibility checks.'],
            'ai.openai.api_key' => ['group' => 'AI providers', 'label' => 'OpenAI API key', 'type' => 'secret', 'env' => 'AI_OPENAI_API_KEY',
                'help' => 'Used for image generation (GPT Image), the video voice-over and AI-visibility checks.'],
            'ai.perplexity.api_key' => ['group' => 'AI providers', 'label' => 'Perplexity API key', 'type' => 'secret', 'env' => 'AI_PERPLEXITY_API_KEY',
                'help' => 'Optional. AI-visibility checks.'],

            // ── Vetting (the final decision maker; no human review) ─────────
            'ai.vetting.model.default' => ['group' => 'AI vetting', 'label' => 'Default vetting model', 'type' => 'model', 'env' => 'AI_GEMINI_DEFAULT_MODEL', 'default' => 'gemini-2.5-flash',
                'help' => 'Gemini model that vets and finalises content. Its decision is final.'],
            'ai.vetting.model.community' => ['group' => 'AI vetting', 'label' => 'Community Q&A vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.blog' => ['group' => 'AI vetting', 'label' => 'Blog vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.knowledge_base' => ['group' => 'AI vetting', 'label' => 'Knowledge Base vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.release_note' => ['group' => 'AI vetting', 'label' => 'Release notes vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.workflow_guide' => ['group' => 'AI vetting', 'label' => 'Workflow guide vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'brand.offer' => ['group' => 'AI vetting', 'label' => 'What we offer new customers (for vetting)', 'type' => 'string', 'default' => \App\Libraries\Settings\BrandOffer::DEFAULT,
                'help' => 'The only offer claims Gemini accepts in videos and social posts (e.g. "sign up free"). Blank = no offer claim is supported.'],
            'ai.insights.model' => ['group' => 'AI vetting', 'label' => 'AI Insights model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],

            // ── Stream H: AI visibility (GEO) + AI Insights (keep this block contiguous) ──
            'visibility.enabled' => ['group' => 'AI visibility & insights', 'label' => 'Daily AI-visibility checks', 'type' => 'bool', 'default' => true,
                'help' => 'Asks each active visibility prompt to every configured assistant once a day (05:00 IST) and records whether Aicountly is mentioned.'],
            'visibility.daily_prompt_limit' => ['group' => 'AI visibility & insights', 'label' => 'Visibility prompts per day', 'type' => 'int', 'default' => 25,
                'help' => 'Cost cap. Each prompt is one call per configured assistant. Prompts not reached today run first tomorrow.'],
            'visibility.providers' => ['group' => 'AI visibility & insights', 'label' => 'Assistants to check', 'type' => 'string', 'default' => 'gemini,openai,perplexity',
                'help' => 'Comma-separated: gemini, openai, perplexity. An assistant without an API key is skipped and shown as skipped.'],
            'visibility.brand_aliases' => ['group' => 'AI visibility & insights', 'label' => 'Names that count as an Aicountly mention', 'type' => 'string', 'default' => 'Aicountly,aicountly.com,AI Countly',
                'help' => 'Comma-separated, matched as whole words, case-insensitive.'],
            'visibility.gemini.search_grounding' => ['group' => 'AI visibility & insights', 'label' => 'Gemini answers with Google Search grounding', 'type' => 'bool', 'default' => true,
                'help' => 'On = closer to what the Gemini app answers and returns cited sources; grounded calls are billed separately by Google.'],
            'ai.openai.chat_model' => ['group' => 'AI visibility & insights', 'label' => 'OpenAI model for visibility checks', 'type' => 'model', 'env' => 'AI_OPENAI_DEFAULT_MODEL', 'default' => 'gpt-5-mini',
                'help' => 'Chat Completions model. Without web search it reflects what the model knows, not a live search.'],
            'ai.perplexity.model' => ['group' => 'AI visibility & insights', 'label' => 'Perplexity model for visibility checks', 'type' => 'model', 'env' => 'AI_PERPLEXITY_DEFAULT_MODEL', 'default' => 'sonar'],
            'insights.weekly.enabled' => ['group' => 'AI visibility & insights', 'label' => 'Weekly AI Insights report', 'type' => 'bool', 'default' => true,
                'help' => 'Runs every Sunday 16:00 IST with the AI Insights model. On-demand runs work either way.'],
            'insights.site_url' => ['group' => 'AI visibility & insights', 'label' => 'Site checked for AI-readiness signals', 'type' => 'string', 'default' => 'https://aicountly.com',
                'help' => 'llms.txt, schema.org JSON-LD and sitemap are read from here for the AI-readiness checklist.'],
            // ── end Stream H ──

            // ── Images ──────────────────────────────────────────────────────
            'ai.image.model' => ['group' => 'Images', 'label' => 'Image model (OpenAI)', 'type' => 'string', 'env' => 'AI_OPENAI_IMAGE_MODEL', 'default' => 'gpt-image-2.5-flare',
                'help' => 'A GPT Image model, e.g. gpt-image-2.5-flare (fast, everyday) or gpt-image-2.5-sunburst. OpenAI removed dall-e-2 / dall-e-3 from the API on 12 May 2026. Run live checks after changing it: it confirms the model is available to the key.'],
            'ai.image.size' => ['group' => 'Images', 'label' => 'Image size', 'type' => 'string', 'default' => '1536x1024',
                'help' => 'GPT Image: 1536x1024 (landscape, blog covers), 1024x1024, 1024x1536 (portrait). Other sizes are snapped to the nearest of these.'],
            'ai.image.quality' => ['group' => 'Images', 'label' => 'Image quality (GPT Image)', 'type' => 'choice', 'env' => 'AI_OPENAI_IMAGE_QUALITY', 'default' => 'medium', 'options' => ['low', 'medium', 'high', 'auto'],
                'help' => 'Price and detail rise with quality; medium suits blog covers and video stills.'],

            // ── Stream 1 — covers without a human (keep this block contiguous) ──
            'covers.auto.enabled' => ['group' => 'Images', 'label' => 'Keep the Cover Gallery stocked automatically', 'type' => 'bool', 'default' => true,
                'help' => 'Every hour Reach generates the covers the upcoming blog index is missing (GPT Image, Aicountly-branded) and adds them to the gallery. BLOG_IMAGE_GENERATION_ENABLED=false in .env also turns it off.'],
            'covers.auto.daily_limit' => ['group' => 'Images', 'label' => 'Covers generated per day (gallery stocking and refresh)', 'type' => 'int', 'default' => 10,
                'help' => 'Cost cap for the hourly gallery stocking and reach:covers-refresh. A new article that no gallery cover fits still gets one (it is counted, not blocked).'],
            'covers.brand.logo_enabled' => ['group' => 'Images', 'label' => 'Put the Aicountly logo on covers', 'type' => 'bool', 'default' => true,
                'help' => 'Corner mark on a white plate, bottom right. The unbranded original is kept.'],
            'covers.alerts.enabled' => ['group' => 'Images', 'label' => 'Email when cover generation keeps failing', 'type' => 'bool', 'default' => true,
                'help' => 'Informational, at most once a day (09:00 UTC), only after repeated failures (provider error or no API key). Daily numbers are in the daily report either way.'],
            'covers.alerts.recipients' => ['group' => 'Images', 'label' => 'Cover alert recipients', 'type' => 'email_list', 'env' => 'BLOG_GALLERY_ALERT_EMAIL', 'default' => '',
                'help' => 'Comma-separated. Blank = the daily analytics report recipients.'],
            'covers.alerts.failure_threshold' => ['group' => 'Images', 'label' => 'Failed cover generations (24 h) before an alert', 'type' => 'int', 'default' => 3],
            // ── end stream 1 ──

            // ── Automation volumes & switches ───────────────────────────────
            'automation.community.enabled' => ['group' => 'Automation', 'label' => 'Community Q&A automation', 'type' => 'bool', 'default' => true],
            'automation.community.daily_limit' => ['group' => 'Automation', 'label' => 'Community Q&A per day', 'type' => 'int', 'default' => 10],
            // ── Stream 2: Community Question Inbox handling (keep this block contiguous) ──
            'automation.community.stuck_hours' => ['group' => 'Automation', 'label' => 'Community: hand stuck drafts to the routine after (hours)', 'type' => 'int', 'default' => 6,
                'help' => 'A question whose old in-Reach draft has not moved for this long is handed to the reach-community-qa routine queue (the draft is archived; nothing public changes).'],
            'automation.community.triage' => ['group' => 'Automation', 'label' => 'Community: vetting model screens members\' questions', 'type' => 'bool', 'default' => true,
                'help' => 'The vetting model (community_question rubric) closes spam, abusive, personal-data and off-topic questions with its reason, so they never wait in the inbox.'],
            // ── end Stream 2 ──
            'automation.blog.enabled' => ['group' => 'Automation', 'label' => 'Blog automation', 'type' => 'bool', 'default' => true],
            'automation.blog.daily_limit' => ['group' => 'Automation', 'label' => 'Blogs per day', 'type' => 'int', 'default' => 6],
            'automation.knowledge_base.enabled' => ['group' => 'Automation', 'label' => 'Knowledge Base automation', 'type' => 'bool', 'default' => true],
            'automation.knowledge_base.daily_limit' => ['group' => 'Automation', 'label' => 'KB articles per day', 'type' => 'int', 'default' => 10],
            'automation.release_note.enabled' => ['group' => 'Automation', 'label' => 'Release notes automation', 'type' => 'bool', 'default' => true],
            'automation.workflow_guide.enabled' => ['group' => 'Automation', 'label' => 'Workflow guides automation', 'type' => 'bool', 'default' => true],
            // Stream D — safety caps on routine submissions (a note per product per day, ~6 guides per launch app per week).
            'automation.release_note.daily_limit' => ['group' => 'Automation', 'label' => 'Release notes per day', 'type' => 'int', 'default' => 40,
                'help' => 'Submissions accepted per day across all products; further ones get HTTP 429.'],
            'automation.workflow_guide.daily_limit' => ['group' => 'Automation', 'label' => 'Workflow guides per day', 'type' => 'int', 'default' => 40,
                'help' => 'Submissions accepted per day across all launch apps; further ones get HTTP 429.'],

            // ── Blog & Knowledge Base routines (stream C) ───────────────────
            // An explicit `false` in the server .env for the named variable is a
            // hard kill-switch that these settings cannot override (RoutineFlags).
            'automation.blog.auto_publish' => ['group' => 'Blog & KB routines', 'label' => 'Auto-publish Gemini-approved blogs', 'type' => 'bool', 'default' => true,
                'help' => 'Off = approved blogs wait for someone to press Publish. BLOG_AUTO_PUBLISH_ENABLED=false or BLOG_PUBLIC_PUBLISHER_ENABLED=false in .env also turns it off.'],
            'automation.blog.ai_cover' => ['group' => 'Blog & KB routines', 'label' => 'Generate an AI cover image for each routine blog', 'type' => 'bool', 'default' => true,
                'help' => 'Falls back to the cover gallery when generation fails. BLOG_IMAGE_GENERATION_ENABLED=false in .env also turns it off.'],
            'automation.blog.author_name' => ['group' => 'Blog & KB routines', 'label' => 'Author shown on routine blogs', 'type' => 'string', 'default' => 'Aicountly Editorial Team'],
            'automation.knowledge_base.auto_publish' => ['group' => 'Blog & KB routines', 'label' => 'Auto-publish Gemini-approved help articles', 'type' => 'bool', 'default' => true,
                'help' => 'KB_AUTO_PUBLISH_ENABLED=false in .env also turns it off.'],
            'automation.vetting.min_compliance_score' => ['group' => 'Blog & KB routines', 'label' => 'Hold for a person below this compliance score (1–10)', 'type' => 'int', 'default' => 5,
                'help' => 'Gemini scores compliance 1–10. An approved article scored below this — or one Gemini flags HIGH_RISK — is held as high risk instead of auto-publishing. 0 = never hold.'],

            // ── Stream 5: living content base + roadmap hand-off (keep this block contiguous) ──
            'automation.content_base.stale_hours' => ['group' => 'Blog & KB routines', 'label' => 'Flag a daily routine as quiet after (hours)', 'type' => 'int', 'default' => 36,
                'help' => 'Informational only: the Content Base page and the daily report say when no routine call or submission for a content type arrived for this long. Weekly routines use 8 days.'],
            'automation.content_base.propose_daily_limit' => ['group' => 'Blog & KB routines', 'label' => 'New topics a routine may propose per type per day', 'type' => 'int', 'default' => 20,
                'help' => 'Cap on POST v1/automation/content-base/propose; further proposals that day are refused with limit_reached.'],
            'automation.roadmap.routine_handoff' => ['group' => 'Blog & KB routines', 'label' => 'Roadmap-selected topics go to the blog routine', 'type' => 'bool', 'default' => true,
                'help' => 'On: a roadmap CREATE_NEW decision puts the topic first in the blog routine\'s queue (GET v1/automation/content-base/next). Off: the legacy in-Reach brief work blocks (their drafting steps are retired).'],
            // ── end Stream 5 ──

            // ── Reports ─────────────────────────────────────────────────────
            'reports.daily.recipients' => ['group' => 'Reports', 'label' => 'Daily analytics report recipients', 'type' => 'email_list', 'default' => 'pno@aicountly.com',
                'help' => 'Comma-separated. Sent every morning at 08:00 IST.'],

            // ── SEO data ────────────────────────────────────────────────────
            'seo.dataforseo.login' => ['group' => 'SEO data', 'label' => 'DataForSEO login', 'type' => 'string', 'env' => 'DATAFORSEO_LOGIN'],
            'seo.dataforseo.password' => ['group' => 'SEO data', 'label' => 'DataForSEO password', 'type' => 'secret', 'env' => 'DATAFORSEO_PASSWORD'],

            // ── Social: social-api.ai (posts + YouTube videos) — keep this block contiguous ──
            'social.socialapi.base_url' => ['group' => 'Social', 'label' => 'social-api.ai API base URL', 'type' => 'string', 'env' => 'SOCIALAPI_BASE_URL', 'default' => 'https://api.social-api.ai/v1', 'format' => 'https_url',
                'help' => 'Leave the default unless social-api.ai tells you otherwise.'],
            'social.socialapi.api_key' => ['group' => 'Social', 'label' => 'social-api.ai API key', 'type' => 'secret', 'env' => 'SOCIALAPI_API_KEY',
                'help' => 'social-api.ai dashboard → API keys (starts with sapi_key_). After saving, run the social-api.ai live check (button below, or php spark reach:integrations-check --only=social_api).'],
            'video.youtube.publisher' => ['group' => 'Social', 'label' => 'How videos reach YouTube', 'type' => 'choice', 'env' => 'VIDEO_YOUTUBE_PUBLISHER', 'default' => 'social_api', 'options' => ['social_api', 'direct'],
                'help' => 'social_api = vlogs and Shorts go to YouTube through social-api.ai (its YouTube connection is already verified by Google). direct = Reach uploads with its own Google OAuth client (Settings → Video; Connect YouTube channel on the Video page).'],
            'social.socialapi.account.youtube' => ['group' => 'Social', 'env' => 'SOCIALAPI_ACCOUNT_YOUTUBE', 'label' => 'social-api.ai account id: YouTube', 'type' => 'string', 'format' => 'account_id',
                'help' => 'Blank = the single active YouTube account connected in social-api.ai. Set it when more than one is connected (the live check lists the ids).'],
            'social.socialapi.account.instagram' => ['group' => 'Social', 'env' => 'SOCIALAPI_ACCOUNT_INSTAGRAM', 'label' => 'social-api.ai account id: Instagram', 'type' => 'string', 'format' => 'account_id', 'help' => 'Blank = the single active Instagram account.'],
            'social.socialapi.account.facebook' => ['group' => 'Social', 'env' => 'SOCIALAPI_ACCOUNT_FACEBOOK', 'label' => 'social-api.ai account id: Facebook', 'type' => 'string', 'format' => 'account_id', 'help' => 'Blank = the single active Facebook account.'],
            'social.socialapi.account.linkedin' => ['group' => 'Social', 'env' => 'SOCIALAPI_ACCOUNT_LINKEDIN', 'label' => 'social-api.ai account id: LinkedIn', 'type' => 'string', 'format' => 'account_id', 'help' => 'Blank = the single active LinkedIn account.'],
            'social.socialapi.account.x' => ['group' => 'Social', 'env' => 'SOCIALAPI_ACCOUNT_X', 'label' => 'social-api.ai account id: X / Twitter', 'type' => 'string', 'format' => 'account_id', 'help' => 'Blank = the single active X account.'],
            'social.socialapi.account.tiktok' => ['group' => 'Social', 'env' => 'SOCIALAPI_ACCOUNT_TIKTOK', 'label' => 'social-api.ai account id: TikTok', 'type' => 'string', 'format' => 'account_id', 'help' => 'Blank = the single active TikTok account. (Reach has no TikTok channel yet.)'],
            'social.socialapi.platform_data.youtube' => ['group' => 'Social', 'env' => 'SOCIALAPI_PLATFORM_DATA_YOUTUBE', 'label' => 'platform_data for YouTube posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged as-is into platform_data of every YouTube post (privacy, category, made-for-kids…). Copy the exact keys from docs.social-api.ai → Platforms → YouTube; Reach does not guess them. Blank = none.'],
            'social.socialapi.platform_data.instagram' => ['group' => 'Social', 'env' => 'SOCIALAPI_PLATFORM_DATA_INSTAGRAM', 'label' => 'platform_data for Instagram posts (JSON)', 'type' => 'json_object', 'default' => '', 'help' => 'Keys from docs.social-api.ai → Platforms → Instagram. Blank = none.'],
            'social.socialapi.platform_data.facebook' => ['group' => 'Social', 'env' => 'SOCIALAPI_PLATFORM_DATA_FACEBOOK', 'label' => 'platform_data for Facebook posts (JSON)', 'type' => 'json_object', 'default' => '', 'help' => 'Keys from docs.social-api.ai → Platforms → Facebook. Blank = none.'],
            'social.socialapi.platform_data.linkedin' => ['group' => 'Social', 'env' => 'SOCIALAPI_PLATFORM_DATA_LINKEDIN', 'label' => 'platform_data for LinkedIn posts (JSON)', 'type' => 'json_object', 'default' => '', 'help' => 'Keys from docs.social-api.ai → Platforms → LinkedIn. Blank = none.'],
            'social.socialapi.platform_data.x' => ['group' => 'Social', 'env' => 'SOCIALAPI_PLATFORM_DATA_X', 'label' => 'platform_data for X posts (JSON)', 'type' => 'json_object', 'default' => '', 'help' => 'Keys from docs.social-api.ai → Platforms → X. Blank = none.'],
            'social.socialapi.platform_data.tiktok' => ['group' => 'Social', 'env' => 'SOCIALAPI_PLATFORM_DATA_TIKTOK', 'label' => 'platform_data for TikTok posts (JSON)', 'type' => 'json_object', 'default' => '', 'help' => 'Keys from docs.social-api.ai → Platforms → TikTok. Blank = none.'],
            'social.socialapi.youtube.visibility' => ['group' => 'Social', 'label' => 'YouTube visibility', 'type' => 'choice', 'env' => 'SOCIALAPI_YOUTUBE_VISIBILITY', 'default' => 'public', 'options' => ['public', 'unlisted', 'private'],
                'help' => 'Sent with every YouTube video as targets[].visibility (the field social-api.ai\'s own dashboard sends). social-api.ai\'s default is private, so leave this set.'],
            'social.socialapi.target_options.youtube' => ['group' => 'Social', 'env' => 'SOCIALAPI_TARGET_OPTIONS_YOUTUBE', 'label' => 'Per-target options for YouTube posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged into the YouTube entry of targets[] (next to account_id). Copy field names from the request social-api.ai\'s dashboard sends (DevTools → Network → posts → Payload). Blank = none.'],
            'social.socialapi.target_options.instagram' => ['group' => 'Social', 'env' => 'SOCIALAPI_TARGET_OPTIONS_INSTAGRAM', 'label' => 'Per-target options for Instagram posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged into the Instagram entry of targets[] (next to account_id). Copy field names from the request social-api.ai\'s dashboard sends (DevTools → Network → posts → Payload). Blank = none.'],
            'social.socialapi.target_options.facebook' => ['group' => 'Social', 'env' => 'SOCIALAPI_TARGET_OPTIONS_FACEBOOK', 'label' => 'Per-target options for Facebook posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged into the Facebook entry of targets[] (next to account_id). Copy field names from the request social-api.ai\'s dashboard sends (DevTools → Network → posts → Payload). Blank = none.'],
            'social.socialapi.target_options.linkedin' => ['group' => 'Social', 'env' => 'SOCIALAPI_TARGET_OPTIONS_LINKEDIN', 'label' => 'Per-target options for LinkedIn posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged into the LinkedIn entry of targets[] (next to account_id). Copy field names from the request social-api.ai\'s dashboard sends (DevTools → Network → posts → Payload). Blank = none.'],
            'social.socialapi.target_options.x' => ['group' => 'Social', 'env' => 'SOCIALAPI_TARGET_OPTIONS_X', 'label' => 'Per-target options for X posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged into the X entry of targets[] (next to account_id). Copy field names from the request social-api.ai\'s dashboard sends (DevTools → Network → posts → Payload). Blank = none.'],
            'social.socialapi.target_options.tiktok' => ['group' => 'Social', 'env' => 'SOCIALAPI_TARGET_OPTIONS_TIKTOK', 'label' => 'Per-target options for TikTok posts (JSON)', 'type' => 'json_object', 'default' => '',
                'help' => 'A JSON object merged into the TikTok entry of targets[] (next to account_id). Copy field names from the request social-api.ai\'s dashboard sends (DevTools → Network → posts → Payload). Blank = none.'],
            // ── end social-api.ai ──

            // ── Stream a — social inbox bot (comments/DMs → reply / Flow ticket / lead). Keep this block contiguous. ──
            'social.inbox.enabled' => ['group' => 'Social inbox', 'label' => 'Read comments & messages (social inbox bot)', 'type' => 'bool', 'env' => 'SOCIAL_INBOX_ENABLED', 'default' => true,
                'help' => 'Every 10 minutes Reach reads new comments and messages on the connected social-api.ai accounts, classifies them with Gemini and answers, lodges complaints in Flow or records sales leads. docs/operations/SOCIAL_API.md → Social inbox bot.'],
            'social.inbox.auto_reply' => ['group' => 'Social inbox', 'label' => 'Post replies automatically', 'type' => 'bool', 'env' => 'SOCIAL_INBOX_AUTO_REPLY', 'default' => true,
                'help' => 'Off = messages are still read, classified, lodged in Flow and recorded as leads, but nothing is posted on the networks.'],
            'social.inbox.platforms' => ['group' => 'Social inbox', 'label' => 'Networks to read', 'type' => 'string', 'default' => 'facebook,instagram,linkedin,x,youtube',
                'help' => 'Comma-separated: facebook, instagram, linkedin, x, youtube, tiktok. A network without an active social-api.ai account is skipped.'],
            'social.inbox.daily_reply_cap' => ['group' => 'Social inbox', 'label' => 'Replies per day (all networks)', 'type' => 'int', 'default' => 40,
                'help' => 'Safety cap (IST day). Messages over the cap wait for the next day; after the age limit below they are left unanswered.'],
            'social.inbox.min_confidence' => ['group' => 'Social inbox', 'label' => 'Answer only above this classification confidence (%)', 'type' => 'int', 'default' => 70,
                'help' => 'Below it Reach does not reply (complaints are still lodged in Flow, with the confidence on the ticket).'],
            'social.inbox.reply_max_age_hours' => ['group' => 'Social inbox', 'label' => 'Do not reply to messages older than (hours)', 'type' => 'int', 'default' => 48],
            'social.inbox.lookback_hours' => ['group' => 'Social inbox', 'label' => 'First read looks back (hours)', 'type' => 'int', 'default' => 24,
                'help' => 'How far back the first poll of an account reads. Later polls start just before the newest message already stored.'],
            'social.inbox.hide_spam' => ['group' => 'Social inbox', 'label' => 'Hide spam / abusive comments', 'type' => 'bool', 'default' => false,
                'help' => 'Uses the hide endpoint below. Leave off until the first live run confirms social-api.ai supports it; spam is never answered either way.'],
            'social.inbox.alert_recipients' => ['group' => 'Social inbox', 'label' => 'Immediate alert for high-severity complaints', 'type' => 'email_list', 'default' => '',
                'help' => 'Legal threats, data/privacy issues, fraud allegations. Blank = the daily report recipients. Everything else is only in the daily report.'],
            'social.inbox.list_path' => ['group' => 'Social inbox', 'label' => 'social-api.ai inbox list paths', 'type' => 'string', 'env' => 'SOCIALAPI_INBOX_LIST_PATH', 'default' => '/inbox/comments,/inbox/conversations',
                'help' => 'Comma-separated, relative to the base URL; each is read for every account ({account_id} is replaced when present). Confirmed live: comments at /inbox/comments, direct messages at /inbox/conversations. The social-api.ai live check probes them read-only.'],
            'social.inbox.reply_path' => ['group' => 'Social inbox', 'label' => 'social-api.ai comment reply path', 'type' => 'string', 'env' => 'SOCIALAPI_INBOX_REPLY_PATH', 'default' => '/inbox/comments/{id}/reply',
                'help' => 'ASSUMPTION until the first reply: POST with {"text": …}; {id} = the comment id. A wrong path is recorded as a failed reply (Social → Inbox, daily report) — never posted twice.'],
            'social.inbox.message_reply_path' => ['group' => 'Social inbox', 'label' => 'social-api.ai direct-message reply path', 'type' => 'string', 'env' => 'SOCIALAPI_INBOX_MESSAGE_REPLY_PATH', 'default' => '/inbox/conversations/{thread_id}/messages',
                'help' => 'ASSUMPTION until the first reply: POST with {"text": …}; {thread_id} = the conversation id ({id} = the message id).'],
            'social.inbox.hide_path' => ['group' => 'Social inbox', 'label' => 'social-api.ai hide path', 'type' => 'string', 'env' => 'SOCIALAPI_INBOX_HIDE_PATH', 'default' => '/inbox/comments/{id}/hide',
                'help' => 'ASSUMPTION: POST, no body. Used only when "Hide spam" is on.'],
            'ai.vetting.model.social_inbox' => ['group' => 'Social inbox', 'label' => 'Classifier / reply-draft model (Gemini)', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.social_reply' => ['group' => 'Social inbox', 'label' => 'Reply vetting model (Gemini)', 'type' => 'model', 'help' => 'Vets every drafted reply before it is posted. Blank = default vetting model.'],
            'flow.api_base_url' => ['group' => 'Social inbox', 'label' => 'Flow API base URL', 'type' => 'string', 'env' => 'FLOW_API_BASE_URL', 'default' => 'https://flow.aicountly.org/api', 'format' => 'https_url',
                'help' => 'Complaints are lodged at {this}/internal/reach/social-tickets.'],
            'flow.reach_key' => ['group' => 'Social inbox', 'label' => 'Flow key (Reach → Flow)', 'type' => 'secret', 'env' => 'FLOW_REACH_INBOUND_KEY',
                'help' => 'The same value as FLOW_REACH_INBOUND_KEY in Flow’s server-php/.env (at least 32 characters). Without it complaints wait (“deferred”) and are lodged once it is set.'],
            // ── end Stream a ──

            // ── Distribution (campaign sends: email / SMS / WhatsApp) ───────
            'distribution.batch_size' => ['group' => 'Distribution', 'label' => 'Recipients per dispatch batch', 'type' => 'int', 'default' => 100,
                'help' => 'Each distribution job sends this many recipients, then queues the next batch.'],
            'distribution.send_cap.email' => ['group' => 'Distribution', 'label' => 'Max email recipients per send', 'type' => 'int', 'default' => 5000,
                'help' => 'Eligible recipients beyond this are left out of the snapshot (recorded as over cap).'],
            'distribution.send_cap.sms' => ['group' => 'Distribution', 'label' => 'Max SMS recipients per send', 'type' => 'int', 'default' => 1000],
            'distribution.send_cap.whatsapp' => ['group' => 'Distribution', 'label' => 'Max WhatsApp recipients per send', 'type' => 'int', 'default' => 1000],
            'distribution.email.require_opt_in' => ['group' => 'Distribution', 'label' => 'Email requires recorded opt-in', 'type' => 'bool', 'default' => false,
                'help' => 'Off: everyone not unsubscribed/suppressed/revoked is eligible. On: only leads with granted email consent (stricter DPDP posture).'],
            'distribution.email.postal_address' => ['group' => 'Distribution', 'label' => 'Postal address in email footer', 'type' => 'string', 'env' => 'DISTRIBUTION_POSTAL_ADDRESS',
                'help' => 'Required before any campaign email is sent. Printed in every campaign email footer.'],
            'distribution.email.unsubscribe_base_url' => ['group' => 'Distribution', 'label' => 'Public Reach URL for unsubscribe links', 'type' => 'string', 'env' => 'REACH_PUBLIC_BASE_URL',
                'help' => 'Base of the public one-click unsubscribe link (…/api/v1/public/unsubscribe). Blank = REACH_APP_URL.'],
            // ── Stream F: SEO tracker + daily report (keep this block contiguous) ──
            'seo.tracker.enabled' => ['group' => 'SEO data', 'label' => 'SEO Tracker daily SERP checks', 'type' => 'bool', 'default' => true,
                'help' => 'Checks each enabled tracked keyword once a day via DataForSEO. Needs the DataForSEO login/password.'],
            'seo.tracker.max_keywords_per_day' => ['group' => 'SEO data', 'label' => 'SEO Tracker: max keywords checked per day', 'type' => 'int', 'default' => 35,
                'help' => 'Cost cap — one DataForSEO Live SERP call per keyword. Keywords not reached today are checked first tomorrow.'],
            'seo.tracker.location_code' => ['group' => 'SEO data', 'label' => 'SEO Tracker: DataForSEO location code', 'type' => 'int', 'default' => 2356,
                'help' => '2356 = India.'],
            'seo.tracker.language_code' => ['group' => 'SEO data', 'label' => 'SEO Tracker: language code', 'type' => 'string', 'default' => 'en'],
            'seo.tracker.depth' => ['group' => 'SEO data', 'label' => 'SEO Tracker: SERP depth', 'type' => 'int', 'default' => 100,
                'help' => 'Results read per keyword (10–100). Deeper costs more on DataForSEO.'],
            'seo.tracker.our_domain' => ['group' => 'SEO data', 'label' => 'SEO Tracker: our domain', 'type' => 'string', 'default' => 'aicountly.com'],
            'reports.daily.enabled' => ['group' => 'Reports', 'label' => 'Send the daily analytics report', 'type' => 'bool', 'default' => true,
                'help' => 'Off = the 08:00 IST report is still generated and stored under Reports, but not emailed.'],
            // ── end Stream F ──

            // ── Stream V — automated video (routine script → vetting → TTS → Shotstack → YouTube) ──
            'automation.video.enabled' => ['group' => 'Video', 'label' => 'Video automation', 'type' => 'bool', 'default' => true,
                'help' => 'Off: the reach-video routine’s scripts are refused (HTTP 429) and nothing new is rendered.'],
            'video.daily.vlogs' => ['group' => 'Video', 'label' => 'Vlogs (16:9, 2–6 min) per day', 'type' => 'int', 'default' => 1],
            'video.daily.shorts' => ['group' => 'Video', 'label' => 'Shorts (9:16, ≤ 60 s) per day', 'type' => 'int', 'default' => 1],
            'ai.vetting.model.video_script' => ['group' => 'Video', 'label' => 'Video script vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'video.tts.model' => ['group' => 'Video', 'label' => 'Voice-over model (OpenAI TTS)', 'type' => 'string', 'env' => 'VIDEO_TTS_MODEL', 'default' => 'gpt-4o-mini-tts',
                'help' => 'Uses the OpenAI API key above. e.g. gpt-4o-mini-tts, tts-1, tts-1-hd.'],
            'video.tts.voice' => ['group' => 'Video', 'label' => 'Voice', 'type' => 'string', 'env' => 'VIDEO_TTS_VOICE', 'default' => 'coral',
                'help' => 'OpenAI voice name: alloy, ash, ballad, coral, echo, fable, nova, onyx, sage, shimmer, verse.'],
            'video.shotstack.api_key' => ['group' => 'Video', 'label' => 'Shotstack API key', 'type' => 'secret', 'env' => 'SHOTSTACK_API_KEY',
                'help' => 'The key for the environment below (Shotstack dashboard → API keys). Stage renders carry a watermark.'],
            'video.shotstack.env' => ['group' => 'Video', 'label' => 'Shotstack environment', 'type' => 'string', 'env' => 'SHOTSTACK_ENV', 'default' => 'stage',
                'help' => '"stage" (free, watermarked) or "v1" (production, billed).'],
            'video.music.url' => ['group' => 'Video', 'label' => 'Background music URL (optional)', 'type' => 'string',
                'help' => 'A royalty-free MP3 you are licensed to use, reachable over HTTPS. Blank = no music.'],
            'video.brand.logo_url' => ['group' => 'Video', 'label' => 'Logo URL for videos', 'type' => 'string', 'default' => 'https://aicountly.com/assests/images/ai-logo.png',
                'help' => 'The official Aicountly logo on aicountly.com. Used on the intro/end cards; every scene also carries the corner mark (this logo on a white plate). Blank or non-https = the official logo.'],
            'video.youtube.client_id' => ['group' => 'Video', 'label' => 'YouTube OAuth client ID', 'type' => 'string', 'env' => 'YOUTUBE_CLIENT_ID',
                'help' => 'Google Cloud → APIs & Services → Credentials → OAuth client (Web application).'],
            'video.youtube.client_secret' => ['group' => 'Video', 'label' => 'YouTube OAuth client secret', 'type' => 'secret', 'env' => 'YOUTUBE_CLIENT_SECRET'],
            'video.youtube.refresh_token' => ['group' => 'Video', 'label' => 'YouTube refresh token', 'type' => 'secret', 'env' => 'YOUTUBE_REFRESH_TOKEN',
                'help' => 'Set by Video → “Connect YouTube channel”. Clear it (or Disconnect) to stop uploads.'],
            'video.youtube.privacy' => ['group' => 'Video', 'label' => 'YouTube privacy', 'type' => 'string', 'default' => 'public',
                'help' => 'public, unlisted or private. Until Google verifies the OAuth app, YouTube locks API uploads to private.'],
            'video.youtube.category_id' => ['group' => 'Video', 'label' => 'YouTube category id', 'type' => 'string', 'default' => '28',
                'help' => '28 = Science & Technology, 27 = Education.'],

            // ── Stream IST: display timezone (keep this block contiguous) ──
            'ui.timezone' => ['group' => 'Display', 'label' => 'Time zone for times shown in Reach', 'type' => 'choice', 'default' => 'Asia/Kolkata',
                'options' => ['Asia/Kolkata', 'UTC', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York'],
                'help' => 'Every time on screen, in the daily report email, notifications, CLI output and CSV exports is shown in this zone (Asia/Kolkata = IST). Stored values and API/JSON fields stay UTC. Reload Reach after changing it.'],
            // ── end Stream IST ──

            // ── Bot Plan & Performance (stream 8) ───────────────────────────
            'bot.self_tune.enabled' => ['group' => 'Automation', 'label' => 'Bot self-tuning (Plan & Performance)', 'type' => 'bool', 'default' => true,
                'help' => 'On: after each daily review the bot lowers targets that cannot be reached (and restores them), retries stuck drafts and safe failed jobs, and steers the routine briefing towards topics that perform — within hard bounds, every change logged on Bot → Plan & Performance. Off: nothing is applied; the log shows what it would have done.'],

            // ── Stream 9: bot actions — Run now + Ask the bot (keep this block contiguous) ──
            'bot.command.routine_fire_url' => ['group' => 'Bot commands', 'label' => 'reach-command routine: API trigger URL', 'type' => 'string', 'env' => 'BOT_COMMAND_ROUTINE_FIRE_URL', 'format' => 'routine_fire_url',
                'help' => 'claude.ai → Routines → reach-command → Add another trigger → API → copy the URL (https://api.anthropic.com/v1/claude_code/routines/trig_…/fire). Bot → Ask the bot and Run now for Claude routines fire it. Setup: docs/operations/BOT_ACTIONS.md.'],
            'bot.command.routine_token' => ['group' => 'Bot commands', 'label' => 'reach-command routine: API trigger token', 'type' => 'secret', 'env' => 'BOT_COMMAND_ROUTINE_TOKEN',
                'help' => 'The token claude.ai shows once when you generate it for that API trigger (starts with sk-ant-oat01-). Stored encrypted; never shown again. Regenerating it in claude.ai invalidates the old one — paste the new one here.'],
            'automation.social.enabled' => ['group' => 'Bot commands', 'label' => 'Accept routine social posts (v1/automation/social-posts)', 'type' => 'bool', 'default' => true,
                'help' => 'Off: social posts submitted by routines are refused (HTTP 429). Posts are Gemini-vetted and published through social-api.ai.'],
            'automation.social.daily_limit' => ['group' => 'Bot commands', 'label' => 'Routine social posts per day (outside owner commands)', 'type' => 'int', 'default' => 6,
                'help' => 'Submissions per IST day not tied to an owner command. Each submission may target several networks. Posts made for an owner command count against that command (10 at most) instead.'],
            'ai.vetting.model.social_post' => ['group' => 'Bot commands', 'label' => 'Social post vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            // ── end bot actions ──
        ];
    }

    public static function isSecret(string $key): bool
    {
        return (self::registry()[$key]['type'] ?? null) === 'secret';
    }

    /** A non-secret value: saved setting → env fallback → registry default → $default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::isSecret($key)) {
            throw new RuntimeException("{$key} is a secret; read it with ReachConfig::secret().");
        }
        $stored = self::stored()[$key] ?? null;
        if ($stored !== null && $stored !== '') {
            return self::cast($key, $stored);
        }
        $meta = self::registry()[$key] ?? [];
        if (isset($meta['env'])) {
            $env = self::env((string) $meta['env']);
            if ($env !== '') {
                return self::cast($key, $env);
            }
        }
        return array_key_exists('default', $meta) ? $meta['default'] : $default;
    }

    /** A secret: decrypted saved value → env fallback → null. */
    public static function secret(string $key): ?string
    {
        $stored = self::stored()[$key] ?? null;
        if (is_array($stored) && isset($stored['__enc'])) {
            $plain = self::decrypt((string) $stored['__enc']);
            if ($plain !== null && $plain !== '') {
                return $plain;
            }
        }
        $env = (string) (self::registry()[$key]['env'] ?? '');
        $val = $env !== '' ? self::env($env) : '';
        return $val !== '' ? $val : null;
    }

    /** Where a value currently comes from: 'settings', 'env', 'default' or 'unset'. */
    public static function source(string $key): string
    {
        $stored = self::stored()[$key] ?? null;
        if ($stored !== null && $stored !== '' && ! (is_array($stored) && isset($stored['__enc']) && self::decrypt((string) $stored['__enc']) === null)) {
            return 'settings';
        }
        $meta = self::registry()[$key] ?? [];
        if (isset($meta['env']) && self::env((string) $meta['env']) !== '') {
            return 'env';
        }
        return array_key_exists('default', $meta) ? 'default' : 'unset';
    }

    /** Vetting model for a content type, falling back to the default vetting model. */
    public static function vettingModel(string $contentType): string
    {
        $specific = $contentType === 'insights' ? 'ai.insights.model' : 'ai.vetting.model.' . $contentType;
        $model    = isset(self::registry()[$specific]) ? trim((string) self::get($specific, '')) : '';
        return $model !== '' ? $model : trim((string) self::get('ai.vetting.model.default'));
    }

    /**
     * Save a value. Secrets are encrypted; an empty secret clears it (falls
     * back to env). Unknown keys are refused so a typo cannot silently create
     * a setting nothing reads.
     */
    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $meta = self::registry()[$key] ?? null;
        if ($meta === null) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }
        $model = new SettingModel();
        if ($meta['type'] === 'secret') {
            $plain = trim((string) $value);
            $model->setSetting($key, $plain === '' ? null : ['__enc' => self::encrypt($plain)], $userId);
        } else {
            $model->setSetting($key, self::validate($key, $value), $userId);
        }
        self::$cache = null;
    }

    /**
     * The registry with current values for the settings screen. Secrets are
     * never returned — only whether one is set and where it comes from.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::registry() as $key => $meta) {
            $row = [
                'key'    => $key,
                'group'  => $meta['group'],
                'label'  => $meta['label'],
                'type'   => $meta['type'],
                'help'   => $meta['help'] ?? null,
                'source' => self::source($key),
            ];
            if ($meta['type'] === 'secret') {
                $row['is_set'] = self::secret($key) !== null;
                $row['value']  = null;
            } else {
                $row['value']   = self::get($key);
                $row['default'] = $meta['default'] ?? null;
            }
            if (isset($meta['options'])) {
                $row['options'] = array_values((array) $meta['options']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * A `json_object` setting decoded: [] when blank. A stored value that is
     * somehow not a JSON object (edited in the database, or a malformed env
     * value) reads as [] and is logged rather than sent half-understood.
     *
     * @return array<string, mixed>
     */
    public static function jsonObject(string $key): array
    {
        $raw = self::get($key, '');
        if (is_array($raw)) {
            return $raw;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            if (function_exists('log_message')) {
                log_message('warning', "[ReachConfig] {$key} is not a JSON object; ignored.");
            }
            return [];
        }
        return $decoded;
    }

    /** Drop the per-request cache (tests, or after a write elsewhere). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = (new SettingModel())->all();
            } catch (\Throwable) {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    private static function cast(string $key, mixed $value): mixed
    {
        return match (self::registry()[$key]['type'] ?? 'string') {
            'int'   => (int) $value,
            'bool'  => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /** The value set() would store, or InvalidArgumentException (public for the settings API and tests). */
    public static function validate(string $key, mixed $value): mixed
    {
        $type = (self::registry()[$key] ?? throw new \InvalidArgumentException("Unknown setting: {$key}"))['type'];
        if ($type === 'int') {
            if (! is_numeric($value) || (int) $value < 0) {
                throw new \InvalidArgumentException("{$key} must be a non-negative number.");
            }
            return (int) $value;
        }
        if ($type === 'bool') {
            return self::cast($key, $value);
        }
        $str = trim((string) $value);
        if ($type === 'email_list' && $str !== '') {
            foreach (array_filter(array_map('trim', explode(',', $str))) as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("{$key}: '{$email}' is not a valid email address.");
                }
            }
        }
        if ($type === 'model' && $str !== '' && ! preg_match('/^[A-Za-z0-9._\-\/]{2,120}$/', $str)) {
            throw new \InvalidArgumentException("{$key}: not a valid model id.");
        }
        if ($type === 'choice' && $str !== '' && ! in_array($str, (array) (self::registry()[$key]['options'] ?? []), true)) {
            throw new \InvalidArgumentException("{$key} must be one of: " . implode(', ', (array) self::registry()[$key]['options']) . '.');
        }
        if ($type === 'json_object' && $str !== '') {
            return self::normalizeJsonObject($key, $str);
        }
        $format = (string) (self::registry()[$key]['format'] ?? '');
        if ($format === 'https_url' && $str !== '' && (! preg_match('#^https://[^\s/?\#]+#i', $str) || filter_var($str, FILTER_VALIDATE_URL) === false)) {
            throw new \InvalidArgumentException("{$key} must be an https:// URL.");
        }
        if ($format === 'routine_fire_url' && $str !== '' && ! preg_match(self::ROUTINE_FIRE_URL_PATTERN, $str)) {
            throw new \InvalidArgumentException("{$key} must be a Claude routine API trigger URL: https://api.anthropic.com/v1/claude_code/routines/trig_…/fire");
        }
        if ($format === 'account_id' && $str !== '' && ! preg_match('/^[A-Za-z0-9._:@\-]{1,200}$/', $str)) {
            throw new \InvalidArgumentException("{$key}: not a valid account id (letters, digits and . _ : @ - only).");
        }
        return $str;
    }

    private static function normalizeJsonObject(string $key, string $str): string
    {
        try {
            $decoded = json_decode($str, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("{$key} is not valid JSON: " . $e->getMessage() . '.');
        }
        if (! is_array($decoded) || ! str_starts_with(ltrim($str), '{')) {
            throw new \InvalidArgumentException("{$key} must be a JSON object, e.g. {\"key\": \"value\"}.");
        }
        return $decoded === [] ? '{}' : (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function env(string $name): string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (($v === false || $v === null) && function_exists('env')) {
            $v = env($name);
        }
        return is_string($v) ? trim($v) : '';
    }

    private static function key(): string
    {
        $material = self::env('REACH_SETTINGS_KEY');
        if ($material === '') {
            $material = self::env('JWT_SECRET');
        }
        if ($material === '') {
            throw new RuntimeException('Set REACH_SETTINGS_KEY (or JWT_SECRET) to store secrets in settings.');
        }
        return hash('sha256', 'reach-settings-v1|' . $material, true);
    }

    private static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::ENC_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    private static function decrypt(string $blob): ?string
    {
        if (! str_starts_with($blob, self::ENC_PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($blob, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        } catch (\Throwable) {
            return null;
        }
        return $plain === false ? null : $plain;
    }
}
