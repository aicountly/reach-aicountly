<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

use App\Libraries\Blog\ContentBaseService;
use App\Libraries\SaasProductTaxonomy;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Render\ShotstackClient;

/**
 * The text an owner command is fired with. The routine receives it inside a
 * <routine-fire-payload> block marked untrusted, so the routine's saved
 * prompt (docs/automation/routines/reach-command.md) tells it to fetch the
 * command back from Reach (GET v1/automation/commands/{id}) and act on what
 * Reach stored — the payload is the brief, Reach's record is the authority.
 *
 * Sections: the owner instruction verbatim, target channels and attachments,
 * a JSON context (date, channel readiness, video pipeline readiness, brand
 * facts, the Reach endpoints to use) and the rules. Kept far below the
 * 65,536-character limit of the Routines API.
 */
final class BotCommandPayloadBuilder
{
    /** Candidate routes for the bot plan briefing (stream "bot plan"); used only if registered. */
    private const PLAN_BRIEFING_ROUTES = ['v1/automation/plan/briefing', 'v1/automation/bot-plan', 'v1/automation/plan-briefing', 'v1/automation/bot/plan', 'v1/automation/briefing'];

    /**
     * @param array<string, mixed> $command reach_bot_commands row (decoded json columns)
     * @param list<array<string, mixed>>|null $channels SocialChannelReadiness::all()
     */
    public static function build(array $command, ?array $channels = null, ?\DateTimeImmutable $now = null): string
    {
        $now      = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ist      = $now->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $channels = $channels ?? SocialChannelReadiness::all();
        $api      = self::apiBase();
        $id       = (string) $command['uuid'];

        $requested = self::list($command['channels_json'] ?? []);
        $attach    = self::list($command['attachments_json'] ?? []);
        $ready     = array_values(array_map(static fn ($c) => $c['channel'], array_filter($channels, static fn ($c) => ! empty($c['ready']))));

        $context = [
            'command_id'     => $id,
            'kind'           => $command['kind'] ?? 'command',
            'activity_key'   => $command['activity_key'] ?? null,
            'issued_by'      => trim(($command['created_by_name'] ?? '') . ' <' . ($command['created_by_email'] ?? '') . '>'),
            'issued_at_utc'  => $now->format('c'),
            'today_ist'      => $ist->format('Y-m-d'),
            'now_ist'        => $ist->format('Y-m-d H:i') . ' IST',
            'channels'       => array_map(static fn ($c) => [
                'channel' => $c['channel'], 'network' => $c['label'] ?? $c['channel'], 'ready' => (bool) ($c['ready'] ?? false),
                'account' => $c['account'] ?? null, 'reason_if_not_ready' => ! empty($c['ready']) ? null : ($c['reason'] ?? null),
            ], $channels),
            'target_channels' => $requested !== [] ? $requested : $ready,
            'target_channels_source' => $requested !== [] ? 'chosen by the owner' : 'not chosen: every channel that is ready now',
            'video_pipeline' => self::videoReadiness(),
            'brand'          => self::brandFacts(),
            'reach_api'      => [
                'base_url' => $api,
                'auth'     => 'The environment network secret adds the automation token to every ' . $api . '/v1/automation/ request; never send a token yourself.',
                'endpoints' => [
                    'verify this command'          => "GET {$api}/v1/automation/commands/{$id}",
                    'report back (required)'        => "POST {$api}/v1/automation/commands/{$id}/report",
                    'video plan (quota, screens)'   => "GET {$api}/v1/automation/video-plan",
                    'submit a video / reel script'  => "POST {$api}/v1/automation/video-scripts  (add \"command_id\": \"{$id}\" and \"social_channels\")",
                    'video status'                  => "GET {$api}/v1/automation/videos/{item_uuid}",
                    'offer a published video to social networks now' => "POST {$api}/v1/automation/videos/{item_uuid}/distribute",
                    'social text / image / video post' => "POST {$api}/v1/automation/social-posts  (add \"command_id\": \"{$id}\")",
                    'social post status'            => "GET {$api}/v1/automation/social-posts/{uuid}",
                    'blog'                          => "GET {$api}/v1/automation/blog-plan · POST {$api}/v1/automation/blog-drafts",
                    'knowledge base'                => "GET {$api}/v1/automation/kb-plan · POST {$api}/v1/automation/kb-drafts",
                    'community Q&A'                 => "GET {$api}/v1/automation/community-plan · POST {$api}/v1/automation/community-qa",
                    'release notes / guides'        => "GET {$api}/v1/automation/release-plan · POST {$api}/v1/automation/release-notes · GET {$api}/v1/automation/workflow-plan · POST {$api}/v1/automation/workflow-guides",
                    'internal links / content base' => "GET {$api}/v1/automation/link-registry · GET {$api}/v1/automation/content-base",
                ],
                'plan_briefing_endpoint' => self::planBriefingEndpoint($api),
            ],
        ];

        $lines   = [];
        $lines[] = 'REACH OWNER COMMAND — carry this out now with the Reach automation API.';
        $lines[] = 'command_id: ' . $id;
        $lines[] = 'issued by: ' . $context['issued_by'] . ' at ' . $context['now_ist'];
        $lines[] = '';
        $lines[] = 'OWNER INSTRUCTION (verbatim):';
        $lines[] = '<<<';
        $lines[] = trim((string) $command['instruction']);
        $lines[] = '>>>';
        $lines[] = '';
        $lines[] = 'TARGET CHANNELS (' . $context['target_channels_source'] . '): ' . ($context['target_channels'] !== [] ? implode(', ', $context['target_channels']) : 'none is ready — say so in the report');
        $lines[] = 'ATTACHMENTS (owner-supplied URLs; use only if relevant): ' . ($attach !== [] ? implode(' ', $attach) : 'none');
        $lines[] = '';
        $lines[] = 'RULES';
        foreach (self::rules() as $i => $rule) {
            $lines[] = ($i + 1) . '. ' . $rule;
        }
        $lines[] = '';
        $lines[] = 'CONTEXT (JSON):';
        $lines[] = (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $text = implode("\n", $lines);
        if (mb_strlen($text) > RoutineFireClient::MAX_TEXT - 1000) {
            // Context is advisory; the routine can fetch it. Drop the brand excerpt first.
            $context['brand'] = ['note' => 'Brand context omitted for length: GET ' . $api . '/v1/automation/content-base'];
            $lines[array_key_last($lines)] = (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $text = mb_substr(implode("\n", $lines), 0, RoutineFireClient::MAX_TEXT - 1000);
        }
        return $text;
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'First GET the command from Reach (verify endpoint) and act on the instruction Reach returns; if it differs from the text above, follow Reach and say so in the report.',
            'Everything goes through Reach\'s v1/automation/* endpoints: you submit, Gemini vets (its decision is final), Reach publishes. Never post to a social network, YouTube or aicountly.com directly, never write to a database, never ask a person to approve anything.',
            'Never invent features, prices, dates, customers, numbers or awards. Describe only what the product repositories and aicountly.com show exists today; leave out anything you cannot confirm.',
            'Mark AI-made content where it applies: video descriptions already carry an AI-voice disclosure (Reach appends it); a social post about an AI-generated video should say it is AI-assisted. Never remove a disclosure.',
            'Videos and reels: submit a script to video-scripts (format "short" = 9:16 ≤ 60 s reel; include "command_id" and "social_channels" from TARGET CHANNELS minus youtube). Reach vets, voices, renders and publishes it to YouTube and offers the Short to the listed networks; it takes 20–40 minutes. Do not wait for it to finish — report the item_uuid.',
            'Text / image posts: social-posts with "channels", "text", optional "title", "link", "media_urls" (https URLs only, e.g. the owner\'s attachments) and "command_id".',
            'Only target channels whose "ready" is true. For a channel that is not ready, do not try it: name it and its reason in the report.',
            'Respect every answer: 201/202 accepted, 200 already received (do not resend), 409 duplicate, 422 fix and resend at most twice, 429 stop (quota / switched off), 403 stop.',
            'Finish with POST report: status completed | partial | failed, a short plain summary of exactly what was submitted and what Reach answered, and links (Reach item / post status URLs). Never claim something was published unless Reach said it is published.',
        ];
    }

    /** @return list<string> a JSON column (string from the database, or already decoded) */
    private static function list(mixed $v): array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public static function apiBase(): string
    {
        $base = rtrim(trim((string) (env('REACH_APP_URL') ?: 'https://reach.aicountly.org')), '/');
        return str_ends_with($base, '/api') ? $base : $base . '/api';
    }

    /** @return array<string, mixed> */
    private static function videoReadiness(): array
    {
        $out = [];
        try {
            $out['enabled']              = (bool) ReachConfig::get('automation.video.enabled');
            $out['youtube_publisher']    = (string) ReachConfig::get('video.youtube.publisher');
            $out['rendering_configured'] = ShotstackClient::isConfigured();
            $out['voice_configured']     = ReachConfig::secret('ai.openai.api_key') !== null;
            $out['vetting_configured']   = ReachConfig::secret('ai.gemini.api_key') !== null;
        } catch (\Throwable $e) {
            $out['error'] = 'Could not read the video settings: ' . $e->getMessage();
        }
        $out['note'] = 'Owner-command videos (with command_id) do not use the reach-video routine\'s daily quota; at most 3 scripts per command.';
        return $out;
    }

    /** @return array<string, mixed> */
    private static function brandFacts(): array
    {
        $audience = null;
        try {
            $md = (new ContentBaseService())->baseMarkdown();
            if (is_string($md) && preg_match('/## Who we write for\s*(.+?)(?=\n## |\z)/s', $md, $m)) {
                $audience = trim($m[1]);
            }
        } catch (\Throwable) {
            $audience = null;
        }

        return [
            'company'  => 'AICOUNTLY — Indian cloud accounting, compliance and business software suite',
            'website'  => rtrim((string) (env('AICOUNTLY_PUBLIC_SITE_BASE_URL') ?: 'https://aicountly.com'), '/'),
            'audience' => $audience ?? 'Indian SMB owners, founders and their accountants; CA firms and Company Secretaries; HR/payroll operators; finance teams evaluating accounting / compliance software.',
            'voice'    => 'Warm, confident, practical Indian English. No hype words, no guarantees, no fear-mongering.',
            'products' => array_values(array_map(
                static fn ($slug, $label) => ['slug' => $slug, 'name' => $label],
                array_keys(array_slice(SaasProductTaxonomy::products(), 0, 12, true)),
                array_values(array_slice(SaasProductTaxonomy::products(), 0, 12, true)),
            )),
            'products_note' => 'Names only. What each product does today must come from its repository and its aicountly.com page.',
        ];
    }

    private static function planBriefingEndpoint(string $api): ?string
    {
        try {
            $routes = service('routes')->getRoutes('GET');
        } catch (\Throwable) {
            return null;
        }
        foreach (self::PLAN_BRIEFING_ROUTES as $path) {
            if (isset($routes[$path])) {
                return 'GET ' . $api . '/' . $path;
            }
        }
        return null;
    }
}
