<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Social;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Bot\BotActionException;
use App\Libraries\Bot\BotCommandService;
use App\Libraries\Bot\SocialChannelReadiness;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\SaasProductTaxonomy;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\VideoPipelineRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

/**
 * POST v1/automation/social-posts — a social post (text, optional title,
 * link, hashtags and https media URLs) from a routine, usually for an owner
 * command. Same contract as every other routine submission:
 *
 *   validate → Gemini vets (`social_post` rubric; revise is re-vetted once,
 *   only approve goes ahead; its decision is final) → approved under the
 *   Reach System Bot → one reach_social_posts row per network → dispatched
 *   through the social publisher (social-api.ai). A network whose account
 *   does not resolve gets its post in the manual queue, never "posted".
 *
 * Idempotent by external_key. Re-sending a key still `pending_vetting`
 * (Gemini was unavailable) vets it again; any other state is returned as is.
 */
class AutomationSocialPostService
{
    public const CONTENT_TYPE      = 'social_post';
    public const MAX_PER_COMMAND   = 10;
    public const MAX_MEDIA         = 4;
    private const X_LIMIT          = 280;
    private const OPEN_COMMAND     = ['firing', 'submitted', 'outcome_unknown', 'in_progress'];

    private static ?ContentVettingService $vetting = null;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @internal tests */
    public static function useVetting(?ContentVettingService $vetting): void
    {
        self::$vetting = $vetting;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws BotActionException 422 invalid, 429 switched off / quota, 409 command closed
     */
    public function ingest(array $payload): array
    {
        if (! (bool) ReachConfig::get('automation.social.enabled')) {
            throw new BotActionException('Routine social posts are switched off (Settings → AI & Automation → Bot commands). Stop.', 429, 'switched_off');
        }
        $post = self::normalize($payload);

        $existing = $this->db->table('reach_automation_social_submissions')->where('external_key', $post['external_key'])->get()->getRowArray();
        if ($existing !== null) {
            if ($existing['status'] === 'pending_vetting') {
                return $this->vet((int) $existing['id']);
            }
            return ['status' => 'already_received'] + $this->summary($existing);
        }

        $command = null;
        if ($post['command_id'] !== null) {
            $command = (new BotCommandService($this->db))->find($post['command_id']);
            if ($command === null) {
                throw new BotActionException('command_id does not name a Reach owner command.', 422, 'invalid');
            }
            if (! in_array($command['status'], self::OPEN_COMMAND, true)) {
                throw new BotActionException("Owner command {$command['uuid']} is {$command['status']}; it no longer accepts submissions.", 409, 'command_closed');
            }
            $used = $this->db->table('reach_automation_social_submissions')->where('bot_command_id', (int) $command['id'])->countAllResults();
            if ($used >= self::MAX_PER_COMMAND) {
                throw new BotActionException("Owner command {$command['uuid']} already has {$used} social submissions (at most " . self::MAX_PER_COMMAND . '). Stop.', 429, 'quota');
            }
        } else {
            [$from, $to] = VideoPipelineRepository::istDayBoundsUtc();
            $used  = $this->db->table('reach_automation_social_submissions')->where('bot_command_id IS NULL', null, false)
                ->where('status !=', 'rejected')->where('created_at >=', $from)->where('created_at <', $to)->countAllResults();
            $limit = max(0, (int) ReachConfig::get('automation.social.daily_limit'));
            if ($used >= $limit) {
                throw new BotActionException("Today's routine social post quota is used ({$used} of {$limit}). Stop for today.", 429, 'quota');
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->table('reach_automation_social_submissions')->insert([
                'external_key'   => $post['external_key'],
                'bot_command_id' => $command !== null ? (int) $command['id'] : null,
                'channels_json'  => json_encode($post['channels']),
                'submitted_json' => json_encode($post['draft'] + ['media_urls' => $post['media_urls']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'         => 'pending_vetting',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } catch (\Throwable $e) {
            $row = $this->db->table('reach_automation_social_submissions')->where('external_key', $post['external_key'])->get()->getRowArray();
            if ($row !== null) {
                return ['status' => 'already_received'] + $this->summary($row);
            }
            throw $e;
        }
        $id = (int) $this->db->insertID();
        $this->audit('social.automation.received', $id, ['external_key' => $post['external_key'], 'channels' => $post['channels'], 'bot_command' => $command['uuid'] ?? null]);

        return $this->vet($id);
    }

    /** @return array<string, mixed> */
    public function vet(int $id): array
    {
        $row = (array) $this->db->table('reach_automation_social_submissions')->where('id', $id)->get()->getRowArray();
        if ($row['status'] !== 'pending_vetting') {
            return ['status' => $row['status']] + $this->summary($row);
        }
        $submitted = self::json($row['submitted_json']);
        $media     = (array) ($submitted['media_urls'] ?? []);
        $draft     = array_intersect_key($submitted, array_flip(['text', 'title', 'link', 'hashtags', 'channels']));
        $context   = $this->vettingContext($row, $media);
        $vetting   = self::$vetting ?? new ContentVettingService();
        $ref       = 'social_submission:' . $row['uuid'];
        $runIds    = [];

        try {
            $first    = $vetting->vet(self::CONTENT_TYPE, $draft, $context, $ref);
            $runIds[] = $first->runId;
            $final    = $first;
            $reasons  = $first->reasons;
            $current  = self::applyRevision($draft, $first->final);
            if ($first->decision === VettingResult::REVISE) {
                $final    = $vetting->vet(self::CONTENT_TYPE, $current, $context, $ref);
                $runIds[] = $final->runId;
                $reasons  = array_merge($reasons, $final->reasons);
                $current  = self::applyRevision($current, $final->final);
            }
        } catch (VettingUnavailableException $e) {
            $this->db->table('reach_automation_social_submissions')->where('id', $id)->update(['last_error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $row = (array) $this->db->table('reach_automation_social_submissions')->where('id', $id)->get()->getRowArray();
            return ['status' => 'pending_vetting', 'message' => $e->getMessage() . ' Nothing was posted. Send the same external_key again later to retry the vetting.'] + $this->summary($row);
        }

        $common = [
            'vetting_decision'     => $final->decision,
            'vetting_model'        => $final->model,
            'vetting_reasons_json' => json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE),
            'vetting_run_ids_json' => json_encode(array_values(array_filter($runIds, static fn ($v) => $v !== null))),
            'last_error'           => null,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ];

        if (! $final->approved() || ($current['text'] ?? '') === '') {
            if ($final->decision === VettingResult::REVISE) {
                $reasons[] = 'Still needed changes after one revision, so it was not approved.';
                $common['vetting_reasons_json'] = json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE);
            }
            $this->db->table('reach_automation_social_submissions')->where('id', $id)->update($common + ['status' => 'rejected', 'final_json' => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $this->audit('social.automation.rejected', $id, ['reasons' => $reasons]);
            $row = (array) $this->db->table('reach_automation_social_submissions')->where('id', $id)->get()->getRowArray();
            return ['status' => 'rejected'] + $this->summary($row);
        }

        $posts = $this->createPosts($row, $current, $media, $final);
        $this->db->table('reach_automation_social_submissions')->where('id', $id)->update($common + [
            'status'               => 'approved',
            'final_json'           => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'social_post_ids_json' => json_encode(array_map(static fn ($p) => $p['post_id'], $posts)),
        ]);
        $this->audit('social.automation.approved', $id, ['posts' => $posts, 'vetting_run_id' => $final->runId, 'approver' => 'system-bot']);

        $row = (array) $this->db->table('reach_automation_social_submissions')->where('id', $id)->get()->getRowArray();
        return ['status' => 'approved'] + $this->summary($row);
    }

    /** @return array<string, mixed>|null */
    public function findSummary(string $ref): ?array
    {
        $q = $this->db->table('reach_automation_social_submissions');
        $row = BotCommandService::isUuid($ref)
            ? $q->where('uuid', strtolower($ref))->get()->getRowArray()
            : $q->where('external_key', $ref)->get()->getRowArray();
        return $row !== null ? ['status' => $row['status']] + $this->summary($row) : null;
    }

    /** @return array<string, mixed> */
    public function summary(array $row): array
    {
        $ids   = array_values(array_map('intval', self::json($row['social_post_ids_json'])));
        $posts = [];
        if ($ids !== []) {
            foreach ($this->db->table('reach_social_posts')->select('id, channel, status, remote_url, error_message, provider_error_code')->whereIn('id', $ids)->orderBy('id')->get()->getResultArray() as $p) {
                $posts[] = [
                    'post_id' => (int) $p['id'], 'channel' => $p['channel'], 'status' => $p['status'],
                    'url' => $p['remote_url'], 'error' => $p['error_message'], 'error_code' => $p['provider_error_code'],
                ];
            }
        }
        return [
            'uuid'             => $row['uuid'],
            'external_key'     => $row['external_key'],
            'channels'         => self::json($row['channels_json']),
            'vetting_decision' => $row['vetting_decision'],
            'vetting_reasons'  => self::json($row['vetting_reasons_json']),
            'final'            => $row['final_json'] !== null ? self::json($row['final_json']) : null,
            'posts'            => $posts,
            'note'             => 'A post is `publishing` once social-api.ai accepted it and `posted` only after social-api.ai reports it published; `manual_queue` means no account for that network, nothing was sent.',
        ];
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $final
     * @param list<array{url: string, type: ?string}> $media
     * @return list<array{channel: string, post_id: int, status: string, queued: bool, note: ?string}>
     */
    private function createPosts(array $row, array $final, array $media, VettingResult $result): array
    {
        $botId     = SystemApprover::userId();
        $tenantId  = (int) (env('REACH_DEFAULT_TENANT_ID') ?: 1);
        $commandId = $row['bot_command_id'] !== null ? (int) $row['bot_command_id'] : null;
        $text      = trim((string) $final['text']);
        $link      = trim((string) ($final['link'] ?? ''));
        if ($link !== '' && ! str_contains($text, $link)) {
            $text .= "\n\n" . $link;
        }
        $hashtags  = array_values(array_slice((array) ($final['hashtags'] ?? []), 0, 5));
        $refs      = array_map(static fn ($m) => array_filter(['url' => $m['url'], 'type' => $m['type'] ?? null]), $media);
        $out       = [];

        foreach (self::json($row['channels_json']) as $channel) {
            $ready = ChannelProviderFactory::socialPublisherReadyFor((string) $channel);
            $now   = gmdate('Y-m-d H:i:s');
            $this->db->table('reach_social_posts')->insert([
                'channel'         => $channel,
                'content'         => $text,
                'title'           => $channel === 'youtube' && ! empty($final['title']) ? mb_substr((string) $final['title'], 0, 255) : null,
                'media_refs'      => json_encode(array_values($refs), JSON_UNESCAPED_SLASHES),
                'hashtags'        => json_encode($hashtags, JSON_UNESCAPED_UNICODE),
                'status'          => $ready ? 'approved' : 'manual_queue',
                'approval_status' => 'approved',
                'approved_by'     => $botId,
                'approved_at'     => $now,
                'bot_generated'   => true,
                'created_by'      => $botId,
                'tenant_id'       => $tenantId,
                'bot_command_id'  => $commandId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $postId = (int) $this->db->insertID();

            $this->db->table('reach_approvals')->insert([
                'subject_type' => 'social',
                'subject_id'   => $postId,
                'summary'      => 'Social post approved automatically after vetting (' . $channel . ').',
                'requested_by' => null,
                'decision'     => 'approved',
                'decided_by'   => $botId,
                'decided_at'   => $now,
                'note'         => 'Approved by Reach System Bot on the vetting model’s decision (' . $result->model . '). No person reviewed it.',
                'metadata'     => json_encode(['vetting_run_id' => $result->runId, 'submission_uuid' => $row['uuid'], 'external_key' => $row['external_key']], JSON_UNESCAPED_SLASHES),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            if ($ready) {
                Services::jobService()->enqueue(DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL, ['post_id' => $postId, 'tenant_id' => $tenantId], ['queue' => 'distribution', 'enqueued_actor_type' => 'system']);
            }
            $out[] = [
                'channel' => (string) $channel, 'post_id' => $postId, 'status' => $ready ? 'approved' : 'manual_queue', 'queued' => $ready,
                'note'    => $ready ? null : 'No social-api.ai account resolves for this network; the post waits in the manual queue and is not marked posted.',
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{external_key: string, channels: list<string>, command_id: ?string, draft: array<string, mixed>, media_urls: list<array{url: string, type: ?string}>}
     */
    public static function normalize(array $payload): array
    {
        $key = trim((string) ($payload['external_key'] ?? ''));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{3,150}$/', $key)) {
            throw new BotActionException('external_key is required: 4–151 characters of letters, digits and . _ : - (e.g. "social-2026-10-18-soft-launch").', 422, 'invalid');
        }
        $channels = BotCommandService::normalizeChannels((array) ($payload['channels'] ?? []));
        if ($channels === []) {
            throw new BotActionException('channels is required: one or more of ' . implode(', ', SocialChannelReadiness::CHANNELS) . '.', 422, 'invalid');
        }
        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 3000) {
            throw new BotActionException('text is required (at most 3000 characters).', 422, 'invalid');
        }
        $title = trim((string) ($payload['title'] ?? ''));
        if (mb_strlen($title) > 100 || str_contains($title, '<') || str_contains($title, '>')) {
            throw new BotActionException('title must be at most 100 characters, without < or >.', 422, 'invalid');
        }
        $link = trim((string) ($payload['link'] ?? ''));
        if ($link !== '' && ! BotCommandService::isHttpsUrl($link)) {
            throw new BotActionException('link must be an https:// URL.', 422, 'invalid');
        }
        $hashtags = [];
        foreach (array_slice((array) ($payload['hashtags'] ?? []), 0, 5) as $h) {
            $h = '#' . preg_replace('/[^\p{L}\p{N}_]+/u', '', ltrim((string) $h, '#'));
            if (mb_strlen($h) > 1) {
                $hashtags[] = $h;
            }
        }
        $media = [];
        $raw   = (array) ($payload['media_urls'] ?? $payload['media'] ?? []);
        if (count($raw) > self::MAX_MEDIA) {
            throw new BotActionException('At most ' . self::MAX_MEDIA . ' media_urls.', 422, 'invalid');
        }
        foreach ($raw as $m) {
            $url  = is_array($m) ? trim((string) ($m['url'] ?? '')) : trim((string) $m);
            $type = is_array($m) && in_array($m['type'] ?? null, ['image', 'video'], true) ? (string) $m['type'] : null;
            if (! BotCommandService::isHttpsUrl($url)) {
                throw new BotActionException('media_urls must be https:// URLs of an image or video file Reach can download.', 422, 'invalid');
            }
            $media[] = ['url' => $url, 'type' => $type ?? self::guessType($url)];
        }
        $hasVideo = array_filter($media, static fn ($m) => $m['type'] === 'video') !== [];
        $hasMedia = $media !== [];
        if (in_array('youtube', $channels, true) && ! $hasVideo) {
            throw new BotActionException('YouTube needs a video: add a video URL to media_urls, or submit a script to video-scripts (Reach renders and publishes it).', 422, 'invalid');
        }
        if (in_array('instagram', $channels, true) && ! $hasMedia) {
            throw new BotActionException('Instagram needs an image or a video in media_urls.', 422, 'invalid');
        }
        if (in_array('twitter', $channels, true)) {
            $len = mb_strlen($text . ($link !== '' && ! str_contains($text, $link) ? "\n\n" . $link : '') . ($hashtags !== [] ? "\n\n" . implode(' ', $hashtags) : ''));
            if ($len > self::X_LIMIT) {
                throw new BotActionException("X allows " . self::X_LIMIT . " characters; this post is {$len} with its link and hashtags. Shorten it or post to X separately.", 422, 'invalid');
            }
        }
        $command = $payload['command_id'] ?? null;

        return [
            'external_key' => $key,
            'channels'     => $channels,
            'command_id'   => $command !== null && $command !== '' ? (string) $command : null,
            'draft'        => array_filter(['text' => $text, 'title' => $title, 'link' => $link, 'hashtags' => $hashtags, 'channels' => $channels], static fn ($v) => $v !== '' && $v !== []),
            'media_urls'   => $media,
        ];
    }

    /**
     * Text fields from the vetting model's final version; channels and links stay as submitted.
     *
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $final
     * @return array<string, mixed>
     */
    private static function applyRevision(array $draft, array $final): array
    {
        $out = $draft;
        if (isset($final['text']) && is_string($final['text']) && trim($final['text']) !== '') {
            $out['text'] = trim($final['text']);
        }
        if (isset($final['title']) && is_string($final['title'])) {
            $out['title'] = mb_substr(trim(str_replace(['<', '>'], '', $final['title'])), 0, 100);
        }
        if (isset($final['hashtags']) && is_array($final['hashtags'])) {
            $out['hashtags'] = array_values(array_filter(array_map(static fn ($h) => '#' . preg_replace('/[^\p{L}\p{N}_]+/u', '', ltrim((string) $h, '#')), array_slice($final['hashtags'], 0, 5)), static fn ($h) => mb_strlen($h) > 1));
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function vettingContext(array $row, array $media): array
    {
        $context = [
            'company'   => \App\Libraries\Settings\BrandFacts::COMPANY,
            'products'  => array_values(array_slice(SaasProductTaxonomy::products(), 0, 12)),
            'academy'   => \App\Libraries\Settings\BrandFacts::academy(),
            'published_on' => 'the official Aicountly accounts on: ' . implode(', ', self::json($row['channels_json'])),
            'media'     => array_map(static fn ($m) => ($m['type'] ?? 'file') . ': ' . $m['url'], $media),
        ];
        if (($offer = \App\Libraries\Settings\BrandOffer::text()) !== '') {
            $context['offer'] = $offer;
        }
        if ($row['bot_command_id'] !== null) {
            $cmd = $this->db->table('reach_bot_commands')->select('instruction, created_by_name')->where('id', (int) $row['bot_command_id'])->get()->getRowArray();
            if ($cmd !== null) {
                $context['owner_instruction'] = 'The company owner asked for this: ' . mb_substr((string) $cmd['instruction'], 0, 2000);
            }
        }
        return $context;
    }

    private static function guessType(string $url): ?string
    {
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['mp4', 'mov', 'm4v', 'webm'], true) => 'video',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            default => null,
        };
    }

    /** @return array<mixed> */
    private static function json(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : [];
    }

    private function audit(string $action, int $id, array $data): void
    {
        try {
            Services::auditLogger()->log(
                userId: null,
                action: $action,
                entityType: 'social_submission',
                entityId: $id,
                newValue: $data,
                actorType: 'service',
                actorService: 'reach:automation',
            );
        } catch (\Throwable) {
        }
    }
}
