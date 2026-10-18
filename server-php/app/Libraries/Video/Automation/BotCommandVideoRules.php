<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\Bot\BotCommandService;
use App\Libraries\Bot\SocialChannelReadiness;
use CodeIgniter\Database\BaseConnection;

/**
 * Rules for a video script submitted for an owner command (stream 9):
 *
 *   command_id       the reach_bot_commands uuid the reach-command routine was
 *                    fired with. Such a video does not use the reach-video
 *                    routine's daily quota; at most MAX_PER_COMMAND scripts
 *                    (rejected ones included) per command, and only while the
 *                    command is open (fired, not yet reported finished) and
 *                    under 48 hours old.
 *   social_channels  networks the video is offered to after YouTube
 *                    (instagram, facebook, linkedin, twitter). Omitted = the
 *                    default for Shorts; [] = YouTube only.
 *
 * Everything else — vetting, approval, rendering, publishing — is the normal
 * pipeline.
 */
final class BotCommandVideoRules
{
    public const MAX_PER_COMMAND = 3;
    public const MAX_AGE_SECS    = 48 * 3600;
    private const OPEN_STATUSES  = ['firing', 'submitted', 'outcome_unknown', 'in_progress'];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null the command row
     */
    public static function command(array $payload): ?array
    {
        $ref = $payload['command_id'] ?? null;
        if ($ref === null || $ref === '') {
            return null;
        }
        if (! is_string($ref) && ! is_int($ref)) {
            throw new \InvalidArgumentException('command_id must be the command id from the payload.');
        }
        $row = (new BotCommandService())->find((string) $ref);
        if ($row === null) {
            throw new \InvalidArgumentException('command_id does not name a Reach owner command.');
        }
        if (! in_array($row['status'], self::OPEN_STATUSES, true)) {
            throw new VideoQuotaException("Owner command {$row['uuid']} is {$row['status']}; it no longer accepts submissions.");
        }
        if (time() - (int) strtotime((string) $row['created_at']) > self::MAX_AGE_SECS) {
            throw new VideoQuotaException("Owner command {$row['uuid']} is older than 48 hours; it no longer accepts submissions.");
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>|null null = not given
     */
    public static function socialChannels(array $payload): ?array
    {
        if (! array_key_exists('social_channels', $payload) || $payload['social_channels'] === null) {
            return null;
        }
        if (! is_array($payload['social_channels'])) {
            throw new \InvalidArgumentException('social_channels must be a list of networks (' . implode(', ', VideoSocialDistributor::ALLOWED_CHANNELS) . ').');
        }
        $out = [];
        foreach ($payload['social_channels'] as $c) {
            $n = is_string($c) ? SocialChannelReadiness::normalize($c) : null;
            if ($n === 'youtube') {
                continue; // YouTube is where the video is published anyway
            }
            if ($n === null || ! in_array($n, VideoSocialDistributor::ALLOWED_CHANNELS, true)) {
                throw new \InvalidArgumentException('social_channels: unknown network ' . json_encode($c) . '. Use: ' . implode(', ', VideoSocialDistributor::ALLOWED_CHANNELS) . '.');
            }
            $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $command */
    public static function assertCommandQuota(BaseConnection $db, array $command): void
    {
        $used = $db->table('reach_video_pipeline_items')->where('bot_command_id', (int) $command['id'])->countAllResults();
        if ($used >= self::MAX_PER_COMMAND) {
            throw new VideoQuotaException(sprintf('Owner command %s already has %d video scripts (at most %d). Stop.', $command['uuid'], $used, self::MAX_PER_COMMAND));
        }
    }
}
