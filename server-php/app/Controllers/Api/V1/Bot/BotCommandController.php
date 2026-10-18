<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Bot;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Bot\BotActionException;
use App\Libraries\Bot\BotCommandService;
use App\Libraries\Bot\SocialChannelReadiness;

/**
 * Bot → Ask the bot.
 *
 *   GET  v1/bot/commands   configuration state, channel readiness, examples, history
 *   POST v1/bot/commands   {instruction, channels?, attachments? (https URLs), idempotency_key?}
 *                          → fires the reach-command routine once; stores the command
 */
class BotCommandController extends BaseApiController
{
    use BotActionResponses;

    public const EXAMPLES = [
        'Post a 30-second soft-launch reel announcing AICOUNTLY on all connected social platforms today.',
        'Write a LinkedIn and Facebook post about this month\'s GST return due dates, linking to our GST blog.',
        'Run today\'s blog routine again now and tell me what it published.',
        'Answer the open community questions about TDS now.',
    ];

    public function index()
    {
        $svc    = new BotCommandService();
        $config = $svc->configuration();
        try {
            $channels = SocialChannelReadiness::all();
        } catch (\Throwable $e) {
            $channels = [];
        }
        [, $limit] = $this->pagination(50, 200);

        return $this->ok([
            'configured'    => $config['configured'],
            'configuration' => $config,
            'setup'         => $config['configured'] ? [] : BotCommandService::setupSteps(),
            'channels'      => $channels,
            'examples'      => self::EXAMPLES,
            'limits'        => ['instruction_chars' => BotCommandService::MAX_INSTRUCTION, 'attachments' => BotCommandService::MAX_ATTACHMENTS, 'per_hour' => BotCommandService::HOURLY_FIRE_LIMIT],
            'commands'      => $svc->history($limit),
        ]);
    }

    public function store()
    {
        $input = $this->input();
        try {
            $result = (new BotCommandService())->submit(
                (string) ($input['instruction'] ?? ''),
                is_array($input['channels'] ?? null) ? $input['channels'] : [],
                is_array($input['attachments'] ?? null) ? $input['attachments'] : [],
                $this->actor(),
                $this->idempotencyKey($input),
            );
        } catch (BotActionException $e) {
            return $this->refused($e);
        }

        if (! $result['replayed'] && $result['command']['status'] === 'failed') {
            // The fire was refused or never sent: the command is stored with the reason.
            $res = json_fail((string) ($result['command']['fire_error'] ?? 'The routine was not fired.'), 502, ['reason' => 'fire_failed'] + $result);
            if ($result['command']['retry_after_secs'] !== null) {
                $res->setHeader('Retry-After', (string) $result['command']['retry_after_secs']);
            }
            return $res;
        }
        return $this->ok($result, $result['replayed'] ? 200 : ($result['command']['status'] === 'submitted' ? 201 : 202));
    }
}
