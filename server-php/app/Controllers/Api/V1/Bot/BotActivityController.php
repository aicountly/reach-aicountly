<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Bot;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Bot\BotActionException;
use App\Libraries\Bot\BotActivityService;

/**
 * Bot → Activities.
 *
 *   GET  v1/bot/activities             every scheduled / automated activity:
 *                                      schedule (IST + UTC), last run, next run,
 *                                      safe-to-run-now, cost note
 *   POST v1/bot/activities/{key}/run   run it now (Idempotency-Key header or
 *                                      body idempotency_key; a replay returns
 *                                      the first run)
 */
class BotActivityController extends BaseApiController
{
    use BotActionResponses;

    public function index()
    {
        $svc = new BotActivityService();
        return $this->ok([
            'activities'    => $svc->list(),
            'server_time_utc' => gmdate('c'),
            'note'          => 'Run now queues the same job (or runs the same command / routine prompt) the schedule would. Workers pick queued jobs up within a minute.',
        ]);
    }

    public function run(string $key)
    {
        $input = $this->input();
        $svc   = new BotActivityService();
        try {
            $result = $svc->run($key, $this->actor(), $this->idempotencyKey($input));
        } catch (BotActionException $e) {
            return $this->refused($e);
        }
        return $this->ok($result + ['activity' => $svc->one($key)], $result['replayed'] ? 200 : 202);
    }
}
