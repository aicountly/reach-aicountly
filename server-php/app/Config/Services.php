<?php

namespace Config;

use App\Libraries\AicountlySitePublisher;
use App\Libraries\AuditLogger;
use App\Libraries\ConsoleAuditClient;
use App\Libraries\EngageClient;
use App\Libraries\Jwt;
use App\Libraries\MarketingBotReporter;
use App\Libraries\MarketingBotService;
use App\Libraries\WorkerPlaywrightClient;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function jwt(bool $getShared = true): Jwt
    {
        if ($getShared) {
            return static::getSharedInstance('jwt') ?? static::jwt(false);
        }
        return new Jwt();
    }

    public static function auditLogger(bool $getShared = true): AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('auditLogger') ?? static::auditLogger(false);
        }
        return new AuditLogger();
    }

    public static function consoleAudit(bool $getShared = true): ConsoleAuditClient
    {
        if ($getShared) {
            return static::getSharedInstance('consoleAudit') ?? static::consoleAudit(false);
        }
        return new ConsoleAuditClient();
    }

    public static function engageClient(bool $getShared = true): EngageClient
    {
        if ($getShared) {
            return static::getSharedInstance('engageClient') ?? static::engageClient(false);
        }
        return new EngageClient();
    }

    public static function workerClient(bool $getShared = true): WorkerPlaywrightClient
    {
        if ($getShared) {
            return static::getSharedInstance('workerClient') ?? static::workerClient(false);
        }
        return new WorkerPlaywrightClient();
    }

    public static function sitePublisher(bool $getShared = true): AicountlySitePublisher
    {
        if ($getShared) {
            return static::getSharedInstance('sitePublisher') ?? static::sitePublisher(false);
        }
        return new AicountlySitePublisher();
    }

    public static function marketingBot(bool $getShared = true): MarketingBotService
    {
        if ($getShared) {
            return static::getSharedInstance('marketingBot') ?? static::marketingBot(false);
        }
        return new MarketingBotService();
    }

    public static function marketingBotReporter(bool $getShared = true): MarketingBotReporter
    {
        if ($getShared) {
            return static::getSharedInstance('marketingBotReporter') ?? static::marketingBotReporter(false);
        }
        return new MarketingBotReporter();
    }
}
