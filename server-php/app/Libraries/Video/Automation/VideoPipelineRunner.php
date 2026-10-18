<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\AuditLogger;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Distribution\Jobs\DistributionJobTypes;
use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\ProviderNotConfiguredException;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiException;
use App\Libraries\Distribution\SocialPostStatusPoller;
use App\Libraries\Distribution\SocialPublisherService;
use App\Models\Distribution\CampaignDeliveryAttemptModel;
use App\Models\Distribution\CampaignDispatchModel;
use App\Models\Distribution\CampaignOperationalMetricsModel;
use App\Libraries\Media\VideoMediaStore;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Render\CaptionBuilder;
use App\Libraries\Video\Automation\Render\TimelineBuilder;
use App\Libraries\Video\Automation\Tts\Mp3Duration;
use App\Libraries\Video\Automation\Visuals\ImageNormalizer;
use App\Libraries\Video\Automation\YouTube\YouTubeMetadata;
use App\Libraries\Video\Automation\YouTube\YouTubePublisherMode;
use Config\Services;

/**
 * Runs one step of an approved video through the pipeline. Every step is a
 * short `reach.video_pipeline_step` job on the `video` queue (cPanel cron:
 * no ffmpeg, no long-running worker), resumable from what is stored:
 *
 *   tts            OpenAI TTS voice-over per scene → MP3 in VideoMediaStore
 *   visuals        screenshots (Worker) / DALL·E stills per scene + thumbnail
 *   render_submit  Shotstack timeline built and submitted (callback + polling)
 *   render_poll    Shotstack status until done / failed
 *   youtube_upload video.youtube.publisher = social_api (default): a `youtube`
 *                  reach_social_posts row published through social-api.ai
 *                  (MP4 uploaded to its media store, POST /posts, then polled
 *                  until social-api.ai reports it published);
 *                  = direct: Reach's own resumable upload, streamed from
 *                  Shotstack's MP4 in chunks
 *   youtube_extras direct only: thumbnails.set + captions.insert (failures are
 *                  warnings); via social-api.ai both are skipped with a warning
 *   social         Shorts: reach_social_posts for Reels / Facebook / LinkedIn
 *                  through the social publisher (social-api.ai when configured)
 *   done           status `published`, with the real YouTube id and URL
 *
 * Honest states: an item is `blocked` when an operator must act (key missing,
 * channel not connected or revoked), `failed` with the reason when a step
 * cannot succeed, `published` only once YouTube returned the video id (direct)
 * or social-api.ai reported the YouTube post published (social_api).
 */
final class VideoPipelineRunner
{
    public const JOB_TYPE      = 'reach.video_pipeline_step';
    public const QUEUE         = 'video';
    public const MAX_ATTEMPTS  = 6;

    public const STEP_BUDGET_SECS   = 50;
    public const UPLOAD_BUDGET_SECS = 80;
    public const POLL_DELAY_SECS    = 45;
    public const MAX_POLLS          = 120;
    public const QUOTA_RETRY_SECS   = 3600;
    public const MAX_RENDERS        = 2;
    /** Shotstack keeps outputs ~24 h; re-render rather than race the expiry. */
    public const OUTPUT_TTL_SECS    = 22 * 3600;

    public const STEPS = ['tts', 'visuals', 'render_submit', 'render_poll', 'youtube_upload', 'youtube_extras', 'social'];

    public const SOCIAL_CHANNELS = ['instagram', 'facebook', 'linkedin'];

    private VideoPipelineRepository $repo;
    private VideoMediaStore $media;

    public function __construct(?VideoPipelineRepository $repo = null, ?VideoMediaStore $media = null)
    {
        $this->repo  = $repo ?? new VideoPipelineRepository();
        $this->media = $media ?? new VideoMediaStore();
    }

    /** Queue a step. Continuations do not pile up: an already-pending job for the same step is reused. */
    public static function enqueue(int $itemId, string $step, int $delaySecs = 0): ?int
    {
        $db       = \Config\Database::connect();
        $existing = $db->query(
            "SELECT id FROM reach_jobs WHERE job_type = ? AND status = 'pending' AND payload_json->>'item_id' = ? AND payload_json->>'step' = ? LIMIT 1",
            [self::JOB_TYPE, (string) $itemId, $step],
        )->getRowArray();
        if ($existing) {
            return (int) $existing['id'];
        }

        return Services::jobService()->enqueueDelayed(self::JOB_TYPE, ['item_id' => $itemId, 'step' => $step], max(0, $delaySecs), [
            'queue'               => self::QUEUE,
            'max_attempts'        => self::MAX_ATTEMPTS,
            'enqueued_actor_type' => 'system',
        ]);
    }

    /** Pull a pending step forward to now (render callback), or queue it. */
    public static function expedite(int $itemId, string $step): void
    {
        $db = \Config\Database::connect();
        $db->query(
            "UPDATE reach_jobs SET available_at = NOW(), updated_at = NOW() WHERE job_type = ? AND status = 'pending' AND payload_json->>'item_id' = ? AND payload_json->>'step' = ?",
            [self::JOB_TYPE, (string) $itemId, $step],
        );
        if ($db->affectedRows() === 0) {
            self::enqueue($itemId, $step);
        }
    }

    /**
     * @return array<string, mixed> job result
     * @throws \Throwable transient failures, so the job queue retries with backoff
     */
    public function run(int $itemId, string $step, int $attempt = 1): array
    {
        $item = $this->repo->find($itemId);
        if ($item === null) {
            return ['status' => 'missing', 'item_id' => $itemId];
        }
        if ($item['status'] !== 'in_progress' || $item['step'] !== $step) {
            return ['status' => 'skipped', 'reason' => "item is {$item['status']} at {$item['step']}", 'item_id' => $itemId];
        }

        try {
            $out = match ($step) {
                'tts'            => $this->stepTts($item),
                'visuals'        => $this->stepVisuals($item),
                'render_submit'  => $this->stepRenderSubmit($item),
                'render_poll'    => $this->stepRenderPoll($item),
                'youtube_upload' => $this->stepYouTubeUpload($item),
                'youtube_extras' => $this->stepYouTubeExtras($item),
                'social'         => $this->stepSocial($item),
                default          => throw new VideoProviderException("Unknown pipeline step {$step}.", VideoProviderException::PERMANENT),
            };
        } catch (VideoProviderException $e) {
            return $this->providerFailure($item, $step, $e, $attempt);
        } catch (\Throwable $e) {
            $this->repo->update($itemId, ['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            if ($attempt >= self::MAX_ATTEMPTS) {
                $this->fail($item, $step, $e->getMessage());
                return ['status' => 'failed', 'step' => $step, 'error' => $e->getMessage()];
            }
            throw $e;
        }

        return $this->advance($item, $step, $out);
    }

    // ── steps ───────────────────────────────────────────────────────────────

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepTts(array $item): array
    {
        $id       = (int) $item['id'];
        $deadline = time() + self::STEP_BUDGET_SECS;
        $tts      = VideoProviders::tts();
        $voiced   = 0;
        $ready    = 0;

        foreach ($item['script_json']['scenes'] as $scene) {
            $order = (int) $scene['order'];
            $text  = trim((string) $scene['voice_over_text']);
            if ($text === '') {
                $this->repo->ensureAsset($id, 'voiceover', $order, 'none', 'skipped', 'No voice-over in this scene.');
                continue;
            }
            $voiced++;
            $asset = $this->repo->ensureAsset($id, 'voiceover', $order, 'tts');
            if ($asset['status'] === 'ready') {
                $ready++;
                continue;
            }
            if (time() >= $deadline) {
                break;
            }
            $this->repo->updateAsset((int) $asset['id'], ['attempts' => (int) $asset['attempts'] + 1]);
            $bytes = $tts->synthesize($text);
            $ms    = Mp3Duration::milliseconds($bytes) ?? (int) round(str_word_count($text) / VideoScriptValidator::WORDS_PER_SECOND * 1000);
            $media = $this->media->store($bytes, 'audio/mpeg', ['item_id' => $id, 'duration_ms' => $ms]);
            $this->repo->updateAsset((int) $asset['id'], ['status' => 'ready', 'media_id' => (int) $media['id'], 'duration_ms' => $ms, 'detail' => $tts->name()]);
            $ready++;
        }

        return $ready >= $voiced
            ? ['next' => 'visuals', 'detail' => "Voice-overs ready ({$ready}); preparing visuals."]
            : ['next' => 'tts', 'detail' => "Voice-overs {$ready}/{$voiced}."];
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepVisuals(array $item): array
    {
        $id       = (int) $item['id'];
        $script   = $item['script_json'];
        $short    = $script['format'] === 'short';
        [$w, $h]  = $short ? [1080, 1920] : [1920, 1080];
        $deadline = time() + self::STEP_BUDGET_SECS;
        $pending  = 0;

        foreach ($script['scenes'] as $scene) {
            $order  = (int) $scene['order'];
            $type   = (string) $scene['visual']['type'];
            $source = match ($type) { 'screenshot' => 'screenshot', 'title_card' => 'title_card', default => 'dalle' };
            $asset  = $this->repo->ensureAsset($id, 'visual', $order, $source);
            if (in_array($asset['status'], ['ready', 'skipped'], true)) {
                continue;
            }
            if ($source === 'title_card') {
                $this->repo->updateAsset((int) $asset['id'], ['status' => 'ready', 'detail' => 'Title card (rendered by the template).']);
                continue;
            }
            if (time() >= $deadline) {
                $pending++;
                continue;
            }

            $ref = (string) $scene['visual']['url_or_prompt'];
            try {
                $raw = $source === 'screenshot'
                    ? VideoProviders::screenshots()->capture($ref, $w, $h)
                    : VideoProviders::stills()->generate($ref, $short ? 'portrait' : 'landscape');
                $img   = ImageNormalizer::toJpeg($raw, $w, $h);
                $media = $this->media->store($img['bytes'], $img['mime'], ['item_id' => $id, 'width' => $img['width'], 'height' => $img['height']]);
                $this->repo->updateAsset((int) $asset['id'], ['status' => 'ready', 'media_id' => (int) $media['id'], 'attempts' => (int) $asset['attempts'] + 1, 'detail' => $source === 'screenshot' ? $ref : 'DALL·E still']);
            } catch (VideoProviderException|\RuntimeException $e) {
                $transient = $e instanceof VideoProviderException && $e->kind === VideoProviderException::TRANSIENT;
                $attempts  = (int) $asset['attempts'] + 1;
                if ($transient && $attempts < 3) {
                    $this->repo->updateAsset((int) $asset['id'], ['attempts' => $attempts, 'detail' => mb_substr($e->getMessage(), 0, 500)]);
                    throw $e;
                }
                // A missing visual must not stop the day's video: fall back to
                // a branded title card and say so.
                $label = $source === 'screenshot' ? 'Screenshot' : 'Still';
                $this->repo->updateAsset((int) $asset['id'], ['status' => 'ready', 'source' => 'title_card', 'attempts' => $attempts, 'detail' => "{$label} unavailable — title card used: " . mb_substr($e->getMessage(), 0, 400)]);
                $this->repo->addWarning($id, "Scene {$order}: {$label} unavailable, title card used (" . mb_substr($e->getMessage(), 0, 200) . ').');
            }
        }

        // YouTube thumbnail (optional): a DALL·E still cropped to 1280×720 JPEG ≤ 2 MB.
        $prompt = trim((string) ($script['thumbnail_prompt'] ?? ''));
        $thumb  = $this->repo->ensureAsset($id, 'thumbnail', 0, $prompt !== '' ? 'dalle' : 'none', $prompt !== '' ? 'pending' : 'skipped', $prompt !== '' ? null : 'No thumbnail prompt; YouTube picks a frame.');
        if ($thumb['status'] === 'pending') {
            if (time() >= $deadline) {
                $pending++;
            } else {
                try {
                    $img   = ImageNormalizer::toJpeg(VideoProviders::stills()->generate($prompt, 'landscape'), 1280, 720, ImageNormalizer::THUMBNAIL_MAX_BYTES);
                    $media = $this->media->store($img['bytes'], $img['mime'], ['item_id' => $id, 'width' => 1280, 'height' => 720]);
                    $this->repo->updateAsset((int) $thumb['id'], ['status' => 'ready', 'media_id' => (int) $media['id'], 'attempts' => (int) $thumb['attempts'] + 1, 'detail' => 'DALL·E thumbnail']);
                } catch (VideoProviderException|\RuntimeException $e) {
                    $attempts = (int) $thumb['attempts'] + 1;
                    if ($e instanceof VideoProviderException && $e->kind === VideoProviderException::TRANSIENT && $attempts < 3) {
                        $this->repo->updateAsset((int) $thumb['id'], ['attempts' => $attempts, 'detail' => mb_substr($e->getMessage(), 0, 500)]);
                        throw $e;
                    }
                    $this->repo->updateAsset((int) $thumb['id'], ['status' => 'failed', 'attempts' => $attempts, 'detail' => mb_substr($e->getMessage(), 0, 500)]);
                    $this->repo->addWarning($id, 'Thumbnail could not be generated; YouTube will pick a frame (' . mb_substr($e->getMessage(), 0, 200) . ').');
                }
            }
        }

        return $pending > 0
            ? ['next' => 'visuals', 'detail' => "Visuals: {$pending} still to prepare."]
            : ['next' => 'render_submit', 'detail' => 'Visuals ready; submitting the render.'];
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepRenderSubmit(array $item): array
    {
        $id     = (int) $item['id'];
        $script = $item['script_json'];
        if ((int) $item['render_attempts'] >= self::MAX_RENDERS) {
            throw new VideoProviderException('Rendered ' . self::MAX_RENDERS . ' times without a successful upload; giving up.', VideoProviderException::PERMANENT);
        }

        $scenes = $this->resolvedScenes($item);
        $build  = TimelineBuilder::build($script, $scenes, [
            'logo_url'     => (string) ReachConfig::get('video.brand.logo_url'),
            'music_url'    => (string) ReachConfig::get('video.music.url'),
            'callback_url' => self::callbackUrl((string) $item['uuid']),
        ]);

        $duration = $build['duration'];
        if ($script['format'] === 'short' && $duration > VideoScriptValidator::SHORT_MAX_SECS) {
            throw new VideoProviderException(sprintf('The voice-over runs %.1f s; a Short must be at most 60 s. Nothing was rendered.', $duration), VideoProviderException::PERMANENT);
        }
        if ($script['format'] === 'vlog' && ($duration < 90 || $duration > 400)) {
            throw new VideoProviderException(sprintf('The video would run %.0f s; a vlog must be 2–6 minutes. Nothing was rendered.', $duration), VideoProviderException::PERMANENT);
        }

        $renderer = VideoProviders::renderer();
        $renderId = $renderer->submit($build['edit']);
        $this->repo->update($id, [
            'render_id'           => $renderId,
            'render_status'       => 'queued',
            'render_url'          => null,
            'render_poster_url'   => null,
            'render_thumbnail_url' => null,
            'render_submitted_at' => gmdate('Y-m-d H:i:s'),
            'rendered_at'         => null,
            'render_polls'        => 0,
            'render_attempts'     => (int) $item['render_attempts'] + 1,
            'duration_secs'       => $duration,
            'render_plan_json'    => ['duration' => $duration, 'cues' => $build['cues'], 'scene_starts' => $build['scene_starts'], 'renderer' => $renderer->name()],
        ]);

        return ['next' => 'render_poll', 'delay' => $script['format'] === 'short' ? 30 : 60, 'detail' => sprintf('Rendering on %s (%d s video).', $renderer->name(), (int) round($duration))];
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepRenderPoll(array $item): array
    {
        $id     = (int) $item['id'];
        $polls  = (int) $item['render_polls'] + 1;
        $result = VideoProviders::renderer()->status((string) $item['render_id']);

        if ($result->failed()) {
            $this->repo->update($id, ['render_status' => 'failed', 'render_polls' => $polls]);
            throw new VideoProviderException('Shotstack render failed: ' . ($result->error ?? 'no reason given'), VideoProviderException::PERMANENT);
        }
        if ($result->done()) {
            $this->repo->update($id, [
                'render_status'        => 'done',
                'render_polls'         => $polls,
                'render_url'           => $result->url,
                'render_poster_url'    => $result->posterUrl,
                'render_thumbnail_url' => $result->thumbnailUrl,
                'rendered_at'          => gmdate('Y-m-d H:i:s'),
                'duration_secs'        => $result->durationSecs ?? $item['duration_secs'],
            ]);
            return ['next' => 'youtube_upload', 'detail' => 'Rendered; uploading to YouTube.'];
        }
        if ($polls >= self::MAX_POLLS) {
            throw new VideoProviderException('Shotstack did not finish the render in time (still ' . $result->providerStatus . ').', VideoProviderException::PERMANENT);
        }
        $this->repo->update($id, ['render_status' => $result->providerStatus, 'render_polls' => $polls]);

        return ['next' => 'render_poll', 'delay' => self::POLL_DELAY_SECS, 'detail' => 'Rendering (' . $result->providerStatus . ').'];
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepYouTubeUpload(array $item): array
    {
        $id   = (int) $item['id'];
        $mode = YouTubePublisherMode::forItem($item);
        if (($item['youtube_publisher'] ?? null) !== $mode) {
            $this->repo->update($id, ['youtube_publisher' => $mode]);
            $item['youtube_publisher'] = $mode;
        }
        if ($mode === YouTubePublisherMode::SOCIAL_API) {
            return $this->stepYouTubeViaSocialApi($item);
        }

        $url = (string) ($item['render_url'] ?? '');
        if ($url === '') {
            throw new VideoProviderException('No rendered video to upload.', VideoProviderException::SOURCE_EXPIRED);
        }
        $renderedAt = strtotime((string) $item['rendered_at']);
        if ($renderedAt !== false && time() - $renderedAt > self::OUTPUT_TTL_SECS && empty($item['youtube_upload_url'])) {
            throw new VideoProviderException('The rendered video is close to expiring at Shotstack; rendering again.', VideoProviderException::SOURCE_EXPIRED);
        }

        $yt      = VideoProviders::youtube();
        $session = (string) ($item['youtube_upload_url'] ?? '');
        $total   = (int) ($item['youtube_upload_bytes'] ?? 0);
        if ($session === '') {
            $total   = $yt->sourceSize($url);
            $session = $yt->startUpload(YouTubeMetadata::resource($item, (array) $item['render_plan_json']), $total, 'video/mp4');
            $this->repo->update($id, ['youtube_upload_url' => $session, 'youtube_upload_bytes' => $total, 'youtube_upload_offset' => 0]);
        }

        $progress = $yt->continueUpload($session, $url, $total, (int) $item['youtube_upload_offset'], time() + self::UPLOAD_BUDGET_SECS);
        if (! $progress->complete) {
            $this->repo->update($id, ['youtube_upload_offset' => $progress->offset]);
            $pct = $total > 0 ? (int) floor($progress->offset * 100 / $total) : 0;
            return ['next' => 'youtube_upload', 'detail' => "Uploading to YouTube ({$pct}%)."];
        }

        $short     = $item['format'] === 'short';
        $requested = (string) ReachConfig::get('video.youtube.privacy');
        $privacy   = (string) ($progress->video['status']['privacyStatus'] ?? $requested);
        $this->repo->update($id, [
            'youtube_video_id'      => $progress->videoId,
            'youtube_url'           => $yt->name() === 'youtube' ? YouTubeMetadata::watchUrl((string) $progress->videoId, $short) : 'mock://youtube/' . $progress->videoId,
            'youtube_privacy'       => $privacy,
            'youtube_upload_url'    => null,
            'youtube_upload_offset' => $total,
        ]);
        if ($privacy !== $requested) {
            $this->repo->addWarning($id, "YouTube set the video to {$privacy} (asked for {$requested}). Uploads from an OAuth app Google has not verified are locked to private until the app passes Google's audit.");
        }
        AuditLogger::record('video.youtube.uploaded', ['item_id' => $id, 'video_id' => $progress->videoId, 'privacy' => $privacy], null);

        return ['next' => 'youtube_extras', 'detail' => 'Uploaded; setting thumbnail and captions.'];
    }

    /**
     * YouTube through social-api.ai: one `youtube` reach_social_posts row per
     * item carries the MP4 (title + description from YouTubeMetadata). The
     * step submits it, then re-runs every minute and reads the post's state
     * (SocialPostStatusPoller) until it is posted or failed.
     *
     * @return array{next: string, delay?: int, detail: string}
     */
    private function stepYouTubeViaSocialApi(array $item): array
    {
        $id     = (int) $item['id'];
        $db     = $this->repo->db();
        $postId = (int) ($item['youtube_social_post_id'] ?? 0);
        $post   = $postId > 0 ? $db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray() : null;

        if ($post === null || in_array($post['status'], ['approved', 'failed'], true)) {
            if ($post !== null && $post['status'] === 'failed' && ! empty($post['external_post_id'])) {
                // social-api.ai took the post and then could not publish it (or not in time).
                throw new VideoProviderException('YouTube via social-api.ai: ' . ($post['error_message'] ?: 'the post failed') . (! empty($post['provider_error_code']) ? ' [' . $post['provider_error_code'] . ']' : ''), VideoProviderException::PERMANENT);
            }
            $url = (string) ($item['render_url'] ?? '');
            if ($url === '') {
                throw new VideoProviderException('No rendered video to upload.', VideoProviderException::SOURCE_EXPIRED);
            }
            $renderedAt = strtotime((string) $item['rendered_at']);
            if ($renderedAt !== false && time() - $renderedAt > self::OUTPUT_TTL_SECS) {
                throw new VideoProviderException('The rendered video is close to expiring at Shotstack; rendering again.', VideoProviderException::SOURCE_EXPIRED);
            }
            $postId = $this->youtubePostRow($item, $post);

            try {
                $result = $this->socialPublisher()->dispatch($postId, (int) $db->table('reach_social_posts')->select('tenant_id')->where('id', $postId)->get()->getRowArray()['tenant_id'], SystemApprover::userId());
            } catch (ProviderNotConfiguredException $e) {
                throw VideoProviderException::notConfigured('YouTube via social-api.ai: ' . $e->getMessage());
            } catch (SocialApiException $e) {
                throw new VideoProviderException('YouTube via social-api.ai: ' . $e->getMessage(), match ($e->kind) {
                    SocialApiException::NOT_CONFIGURED => VideoProviderException::NOT_CONFIGURED,
                    SocialApiException::AUTH           => VideoProviderException::AUTH,
                    SocialApiException::RATE_LIMIT     => VideoProviderException::QUOTA,
                    SocialApiException::TRANSIENT      => VideoProviderException::TRANSIENT,
                    SocialApiException::MEDIA_SOURCE   => VideoProviderException::SOURCE_EXPIRED,
                    default                            => VideoProviderException::PERMANENT,
                });
            }
            $post = $db->table('reach_social_posts')->where('id', $postId)->get()->getRowArray();
            if (($post['status'] ?? '') === 'posted') {
                return $this->youtubePublishedViaSocialApi($item, $post);
            }
            $detail = ($result['status'] ?? '') === 'already_dispatched'
                ? 'A YouTube submission to social-api.ai is already in progress; waiting for it.'
                : 'Submitted to YouTube through social-api.ai (post ' . ($post['external_post_id'] ?? '?') . '); waiting for it to publish.';
            return ['next' => 'youtube_upload', 'delay' => self::POLL_DELAY_SECS, 'detail' => $detail];
        }

        if ($post['status'] === 'publishing') {
            $poll = (new SocialPostStatusPoller())->pollOne((int) $post['id']);
            $post = $db->table('reach_social_posts')->where('id', (int) $post['id'])->get()->getRowArray();
            if ($post['status'] === 'publishing') {
                return ['next' => 'youtube_upload', 'delay' => self::POLL_DELAY_SECS, 'detail' => 'social-api.ai is publishing to YouTube (' . ($post['provider_status'] ?: 'pending') . ').' . (! empty($poll['poll_error']) ? ' Last status check failed; retrying.' : '')];
            }
        }
        if ($post['status'] === 'posted') {
            return $this->youtubePublishedViaSocialApi($item, $post);
        }
        throw new VideoProviderException('YouTube via social-api.ai: ' . ($post['error_message'] ?: 'the post is ' . $post['status']) . (! empty($post['provider_error_code']) ? ' [' . $post['provider_error_code'] . ']' : ''), VideoProviderException::PERMANENT);
    }

    /** Create (or refresh, for a retry / re-render) the `youtube` social post that carries this video. */
    private function youtubePostRow(array $item, ?array $post): int
    {
        $db       = $this->repo->db();
        $resource = YouTubeMetadata::resource($item, (array) $item['render_plan_json']);
        $now      = gmdate('Y-m-d H:i:s');
        $fields   = [
            'title'      => mb_substr((string) $resource['snippet']['title'], 0, 255),
            'content'    => (string) $resource['snippet']['description'],
            'media_refs' => json_encode([[
                'type' => 'video', 'kind' => 'mp4', 'mime' => 'video/mp4', 'url' => $item['render_url'],
                'note' => 'Rendered MP4 (Shotstack, valid ~24 h after rendering).', 'rendered_at' => $item['rendered_at'],
            ]], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        if ($post !== null) {
            $db->table('reach_social_posts')->where('id', (int) $post['id'])->update($fields);
            return (int) $post['id'];
        }

        $botId = SystemApprover::userId();
        $db->table('reach_social_posts')->insert($fields + [
            'channel'         => 'youtube',
            'hashtags'        => json_encode([], JSON_UNESCAPED_UNICODE),
            'status'          => 'approved',
            'approval_status' => 'approved',
            'approved_by'     => $botId,
            'approved_at'     => $now,
            'bot_generated'   => true,
            'created_by'      => $botId,
            'tenant_id'       => (int) (env('REACH_DEFAULT_TENANT_ID') ?: 1),
            'created_at'      => $now,
        ]);
        $postId = (int) $db->insertID();
        $this->repo->update((int) $item['id'], ['youtube_social_post_id' => $postId]);

        return $postId;
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function youtubePublishedViaSocialApi(array $item, array $post): array
    {
        $id    = (int) $item['id'];
        $short = $item['format'] === 'short';
        if (($post['provider'] ?? '') !== \App\Libraries\Distribution\Providers\SocialApiAiPublisher::PROVIDER) {
            // Development only (the mock social publisher refuses in production): never claim a YouTube link.
            $this->repo->update($id, ['youtube_video_id' => null, 'youtube_url' => 'mock://social/' . ($post['external_post_id'] ?? $post['id']), 'youtube_privacy' => null]);
            $this->repo->addWarning($id, 'Published by the development mock social publisher (' . ($post['provider'] ?? '?') . '): nothing is on YouTube.');
            return ['next' => 'youtube_extras', 'detail' => 'Mock social publisher: nothing was published on YouTube.'];
        }
        $url   = (string) ($post['remote_url'] ?? '');
        $video = self::youtubeIdFromUrl($url);
        if ($video === null && preg_match('/^[A-Za-z0-9_\-]{6,32}$/', (string) ($post['remote_post_id'] ?? ''))) {
            $video = (string) $post['remote_post_id'];
        }
        if ($url === '' && $video !== null) {
            $url = YouTubeMetadata::watchUrl($video, $short);
        }
        $this->repo->update($id, [
            'youtube_video_id'      => $video,
            'youtube_url'           => $url !== '' ? $url : null,
            'youtube_privacy'       => null,
            'youtube_upload_url'    => null,
        ]);
        if ($url === '') {
            $this->repo->addWarning($id, 'social-api.ai reported the video published on YouTube but returned no link or video id; find it in YouTube Studio.');
        }
        AuditLogger::record('video.youtube.uploaded', ['item_id' => $id, 'video_id' => $video, 'via' => 'social_api', 'social_post_id' => (int) $post['id']], null);

        return ['next' => 'youtube_extras', 'detail' => 'Published on YouTube through social-api.ai.'];
    }

    public static function youtubeIdFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_\-]{6,32})#i', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function socialPublisher(): SocialPublisherService
    {
        return new SocialPublisherService(new CampaignDispatchModel(), new CampaignDeliveryAttemptModel(), new CampaignOperationalMetricsModel(), new AuditLogger());
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepYouTubeExtras(array $item): array
    {
        $id      = (int) $item['id'];
        if (YouTubePublisherMode::forItem($item) === YouTubePublisherMode::SOCIAL_API) {
            if ($item['thumbnail_status'] === null || $item['captions_status'] === null) {
                $this->repo->update($id, ['thumbnail_status' => 'skipped', 'captions_status' => 'skipped']);
                $this->repo->addWarning($id, 'Published through social-api.ai: Reach did not set a custom thumbnail or a separate caption track (no documented social-api.ai field; captions are burned into the video). Add them in YouTube Studio if needed.');
            }
            return $item['format'] === 'short'
                ? ['next' => 'social', 'detail' => 'Offering the Short to social channels.']
                : ['next' => 'done', 'detail' => 'Published on YouTube (social-api.ai).'];
        }
        $videoId = (string) $item['youtube_video_id'];
        $yt      = VideoProviders::youtube();

        if ($item['thumbnail_status'] === null) {
            $asset = $this->repo->asset($id, 'thumbnail', 0);
            $media = $asset !== null && $asset['status'] === 'ready' && $asset['media_id'] ? $this->media->find((int) $asset['media_id']) : null;
            $bytes = $media !== null ? $this->media->bytes($media) : null;
            if ($bytes === null) {
                $this->repo->update($id, ['thumbnail_status' => 'skipped']);
            } else {
                $this->extra($id, 'thumbnail_status', 'Custom thumbnail', static fn () => $yt->setThumbnail($videoId, $bytes, (string) $media['mime']));
            }
        }

        if ($item['captions_status'] === null) {
            $cues = (array) ($item['render_plan_json']['cues'] ?? []);
            if ($cues === []) {
                $this->repo->update($id, ['captions_status' => 'skipped']);
            } else {
                $srt = CaptionBuilder::srt($cues);
                $this->extra($id, 'captions_status', 'Caption track', static fn () => $yt->insertCaption($videoId, 'en', 'English', $srt));
            }
        }

        return $item['format'] === 'short'
            ? ['next' => 'social', 'detail' => 'Offering the Short to social channels.']
            : ['next' => 'done', 'detail' => 'Published on YouTube.'];
    }

    /** @return array{next: string, delay?: int, detail: string} */
    private function stepSocial(array $item): array
    {
        $id = (int) $item['id'];
        if ((array) $item['social_post_ids_json'] !== []) {
            return ['next' => 'done', 'detail' => 'Published on YouTube; social posts already created.'];
        }

        $script   = $item['script_json'];
        $db       = $this->repo->db();
        $botId    = SystemApprover::userId();
        $tenantId = (int) (env('REACH_DEFAULT_TENANT_ID') ?: 1);
        $hashtags = array_slice(array_values(array_filter(array_map(static fn ($t) => '#' . preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $t), (array) $script['tags']), static fn ($t) => mb_strlen($t) > 1)), 0, 5);
        $watch    = (string) ($item['youtube_url'] ?? '');
        $text     = trim($script['title']) . "\n\n" . mb_substr(trim((string) $script['description']), 0, 280)
            . "\n\n" . trim((string) $script['cta_text']) . ($watch !== '' ? "\nWatch: " . $watch : '');
        $media    = array_values(array_filter([
            $watch !== '' ? ['type' => 'video', 'kind' => 'youtube_short', 'url' => $watch] : null,
            $item['render_url'] ? ['type' => 'video', 'kind' => 'mp4', 'url' => $item['render_url'], 'note' => 'Rendered 9:16 MP4 — hosted by Shotstack for about 24 hours after rendering.', 'rendered_at' => $item['rendered_at']] : null,
        ]));

        $ids = [];
        $queued = [];
        foreach (self::SOCIAL_CHANNELS as $channel) {
            $ready = ChannelProviderFactory::socialPublisherReadyFor($channel);
            $now   = gmdate('Y-m-d H:i:s');
            $db->table('reach_social_posts')->insert([
                'channel'         => $channel,
                'content'         => $text,
                'media_refs'      => json_encode($media, JSON_UNESCAPED_SLASHES),
                'hashtags'        => json_encode($hashtags, JSON_UNESCAPED_UNICODE),
                // Without a publisher that can post on this channel they wait in the manual queue.
                'status'          => $ready ? 'approved' : 'manual_queue',
                'approval_status' => 'approved',
                'approved_by'     => $botId,
                'approved_at'     => $now,
                'bot_generated'   => true,
                'created_by'      => $botId,
                'tenant_id'       => $tenantId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $postId = (int) $db->insertID();
            $ids[]  = $postId;
            if ($ready) {
                Services::jobService()->enqueue(DistributionJobTypes::CAMPAIGN_DISPATCH_SOCIAL, ['post_id' => $postId, 'tenant_id' => $tenantId], ['queue' => 'distribution', 'enqueued_actor_type' => 'system']);
                $queued[] = $channel;
            }
        }
        $this->repo->update($id, ['social_post_ids_json' => $ids]);

        $detail = $queued === []
            ? 'Published on YouTube; social posts are in the manual queue (no social publisher is connected).'
            : 'Published on YouTube; social posts queued for ' . implode(', ', $queued) . '.';
        return ['next' => 'done', 'detail' => $detail];
    }

    // ── transitions ─────────────────────────────────────────────────────────

    /** @param array{next: string, delay?: int, detail: string} $out */
    private function advance(array $item, string $step, array $out): array
    {
        $id = (int) $item['id'];
        if ($out['next'] === 'done') {
            $this->repo->update($id, ['status' => 'published', 'step' => 'done', 'step_detail' => $out['detail'], 'last_error' => null, 'published_at' => gmdate('Y-m-d H:i:s')]);
            $thumb = $this->repo->asset($id, 'thumbnail', 0);
            $this->media->purgeForItem($id, $thumb && $thumb['media_id'] ? [(int) $thumb['media_id']] : []);
            AuditLogger::record('video.published', ['item_id' => $id, 'youtube_video_id' => $item['youtube_video_id'] ?? null], null);
            return ['status' => 'published', 'item_id' => $id];
        }

        $this->repo->update($id, ['step' => $out['next'], 'step_detail' => $out['detail'], 'last_error' => null]);
        self::enqueue($id, $out['next'], (int) ($out['delay'] ?? 0));
        return ['status' => 'advanced', 'item_id' => $id, 'from' => $step, 'to' => $out['next']];
    }

    private function providerFailure(array $item, string $step, VideoProviderException $e, int $attempt): array
    {
        $id  = (int) $item['id'];
        $msg = $e->getMessage();

        switch ($e->kind) {
            case VideoProviderException::NOT_CONFIGURED:
            case VideoProviderException::AUTH:
                if ($e->kind === VideoProviderException::AUTH && str_starts_with($step, 'youtube') && YouTubePublisherMode::forItem($item) === YouTubePublisherMode::DIRECT) {
                    (new YouTubeChannelService($this->repo->db()))->markError($msg);
                }
                $this->repo->update($id, ['status' => 'blocked', 'last_error' => mb_substr($msg, 0, 1000), 'step_detail' => 'Blocked: ' . mb_substr($msg, 0, 300)]);
                AuditLogger::record('video.pipeline.blocked', ['item_id' => $id, 'step' => $step, 'reason' => $msg], null);
                return ['status' => 'blocked', 'step' => $step, 'error' => $msg];

            case VideoProviderException::QUOTA:
                $this->repo->update($id, ['last_error' => mb_substr($msg, 0, 1000), 'step_detail' => 'Provider quota reached; retrying in an hour.']);
                self::enqueue($id, $step, self::QUOTA_RETRY_SECS);
                return ['status' => 'deferred', 'step' => $step, 'error' => $msg];

            case VideoProviderException::SESSION_EXPIRED:
                $this->repo->update($id, ['youtube_upload_url' => null, 'youtube_upload_offset' => 0, 'last_error' => mb_substr($msg, 0, 1000)]);
                self::enqueue($id, 'youtube_upload');
                return ['status' => 'restarting_upload', 'step' => $step];

            case VideoProviderException::SOURCE_EXPIRED:
                if ((int) $item['render_attempts'] >= self::MAX_RENDERS) {
                    $this->fail($item, $step, $msg);
                    return ['status' => 'failed', 'step' => $step, 'error' => $msg];
                }
                $this->repo->update($id, ['step' => 'render_submit', 'step_detail' => 'Rendering again: ' . mb_substr($msg, 0, 200), 'youtube_upload_url' => null, 'youtube_upload_offset' => 0, 'render_url' => null, 'last_error' => mb_substr($msg, 0, 1000)]);
                self::enqueue($id, 'render_submit');
                return ['status' => 'rerendering', 'step' => $step];

            case VideoProviderException::PERMANENT:
                $this->fail($item, $step, $msg);
                return ['status' => 'failed', 'step' => $step, 'error' => $msg];

            default: // transient
                $this->repo->update($id, ['last_error' => mb_substr($msg, 0, 1000)]);
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->fail($item, $step, $msg);
                    return ['status' => 'failed', 'step' => $step, 'error' => $msg];
                }
                throw $e;
        }
    }

    private function fail(array $item, string $step, string $message): void
    {
        $this->repo->update((int) $item['id'], ['status' => 'failed', 'step' => $step, 'last_error' => mb_substr($message, 0, 1000), 'step_detail' => 'Failed at ' . str_replace('_', ' ', $step) . ': ' . mb_substr($message, 0, 300)]);
        AuditLogger::record('video.pipeline.failed', ['item_id' => (int) $item['id'], 'step' => $step, 'error' => $message], null);
    }

    /**
     * A thumbnail / caption call: transient failures retry the step, anything
     * else is recorded as a warning — the video itself is already on YouTube.
     */
    private function extra(int $id, string $column, string $label, callable $call): void
    {
        try {
            $call();
            $this->repo->update($id, [$column => 'set']);
        } catch (VideoProviderException $e) {
            if ($e->kind === VideoProviderException::TRANSIENT) {
                throw $e;
            }
            $this->repo->update($id, [$column => 'failed']);
            $this->repo->addWarning($id, "{$label} not set on YouTube: " . mb_substr($e->getMessage(), 0, 300)
                . ($column === 'thumbnail_status' && $e->kind === VideoProviderException::AUTH ? ' (custom thumbnails need a phone-verified channel)' : ''));
        }
    }

    /**
     * Scenes with signed URLs for their stored voice-over and visual.
     *
     * @return list<array<string, mixed>>
     */
    private function resolvedScenes(array $item): array
    {
        $id     = (int) $item['id'];
        $voice  = [];
        $visual = [];
        foreach ($this->repo->assets($id) as $a) {
            if ($a['kind'] === 'voiceover') {
                $voice[(int) $a['scene_order']] = $a;
            } elseif ($a['kind'] === 'visual') {
                $visual[(int) $a['scene_order']] = $a;
            }
        }

        $out = [];
        foreach ($item['script_json']['scenes'] as $scene) {
            $order    = (int) $scene['order'];
            $audioUrl = null;
            $audioSec = null;
            $va       = $voice[$order] ?? null;
            if (trim((string) $scene['voice_over_text']) !== '') {
                $media = $va !== null && $va['status'] === 'ready' && $va['media_id'] ? $this->media->find((int) $va['media_id']) : null;
                if ($media === null) {
                    throw new VideoProviderException("Scene {$order} has no stored voice-over.", VideoProviderException::PERMANENT);
                }
                $audioUrl = $this->media->signedUrl($media);
                $audioSec = ((int) ($va['duration_ms'] ?? $media['duration_ms'] ?? 0)) / 1000;
            }

            $kind     = 'title_card';
            $imageUrl = null;
            $source   = null;
            $vis      = $visual[$order] ?? null;
            if ($vis !== null && $vis['status'] === 'ready' && in_array($vis['source'], ['screenshot', 'dalle'], true) && $vis['media_id']) {
                $media = $this->media->find((int) $vis['media_id']);
                if ($media !== null) {
                    $kind     = 'image';
                    $imageUrl = $this->media->signedUrl($media);
                    $source   = (string) $vis['source'];
                }
            }

            $out[] = [
                'order'              => $order,
                'voice_over_text'    => (string) $scene['voice_over_text'],
                'on_screen_text'     => (string) $scene['on_screen_text'],
                'duration_hint_secs' => (float) $scene['duration_hint_secs'],
                'visual_kind'        => $kind,
                'image_url'          => $imageUrl,
                'image_source'       => $source,
                'audio_url'          => $audioUrl,
                'audio_secs'         => $audioSec,
            ];
        }
        return $out;
    }

    // ── render callback URL ─────────────────────────────────────────────────

    public static function callbackUrl(string $itemUuid): ?string
    {
        $sig = self::callbackSignature($itemUuid);
        if ($sig === null) {
            return null; // polling still finishes the render
        }
        $base = rtrim(trim((string) (env('REACH_APP_URL') ?: 'https://reach.aicountly.org')), '/');
        return $base . '/api/v1/public/video/shotstack/callback/' . $itemUuid . '?sig=' . $sig;
    }

    public static function callbackSignature(string $itemUuid): ?string
    {
        $key = (string) (env('REACH_SETTINGS_KEY') ?: env('JWT_SECRET') ?: '');
        return $key === '' ? null : hash_hmac('sha256', 'shotstack-callback|' . $itemUuid, $key);
    }

    public static function verifyCallback(string $itemUuid, string $sig): bool
    {
        $expected = self::callbackSignature($itemUuid);
        return $expected !== null && $sig !== '' && hash_equals($expected, $sig);
    }
}
