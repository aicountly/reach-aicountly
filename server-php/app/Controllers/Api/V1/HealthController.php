<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\BotSettingModel;
use Config\Database;

class HealthController extends BaseApiController
{
    public function detailed()
    {
        $checks = [];

        $jwtSecret = (string) env('JWT_SECRET', '');
        $checks['jwt_secret']        = $jwtSecret !== '' && strlen($jwtSecret) >= 32;

        $checks['database']          = $this->probeDb();
        $checks['console_configured']= (string) env('CONSOLE_API_BASE_URL', '') !== ''
                                    && (string) env('CONSOLE_API_TOKEN', '') !== '';
        $checks['engage_configured'] = (string) env('ENGAGE_API_BASE_URL', '') !== ''
                                    && (string) env('ENGAGE_INBOUND_TOKEN', '') !== '';
        $checks['worker_configured'] = (string) env('WORKER_BASE_URL', '') !== ''
                                    && (string) env('WORKER_API_TOKEN', '') !== '';
        $checks['site_publisher_configured'] = (string) env('AICOUNTLY_SITE_API_BASE_URL', '') !== ''
                                    && (string) env('AICOUNTLY_SITE_API_TOKEN', '') !== '';

        $ok = $checks['jwt_secret'] && $checks['database'];

        return $this->ok([
            'ok'        => $ok,
            'status'    => $ok ? 'ready' : 'misconfigured',
            'timestamp' => gmdate('c'),
            'checks'    => $checks,
            'bot_mode'  => (new BotSettingModel())->currentMode(),
        ]);
    }

    private function probeDb(): bool
    {
        try {
            Database::connect()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
