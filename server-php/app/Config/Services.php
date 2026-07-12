<?php

namespace Config;

use App\Libraries\AicountlySitePublisher;
use App\Libraries\ApprovalPolicy;
use App\Libraries\AuditLogger;
use App\Libraries\ConsoleAuditClient;
use App\Services\ConsoleIdentityService;
use App\Libraries\EngageClient;
use App\Libraries\HtmlSanitizer;
use App\Libraries\JobHandlerRegistry;
use App\Libraries\JobService;
use App\Libraries\Jwt;
use App\Libraries\MarketingBotReporter;
use App\Libraries\MarketingBotService;
use App\Libraries\PermissionService;
use App\Libraries\RequestValidator;
use App\Libraries\SecretRedactor;
use App\Libraries\UrlPolicy;
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

    public static function consoleIdentity(bool $getShared = true): ConsoleIdentityService
    {
        if ($getShared) {
            return static::getSharedInstance('consoleIdentity') ?? static::consoleIdentity(false);
        }
        return new ConsoleIdentityService();
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

    public static function permissionService(bool $getShared = true): PermissionService
    {
        if ($getShared) {
            return static::getSharedInstance('permissionService') ?? static::permissionService(false);
        }
        return new PermissionService();
    }

    public static function approvalPolicy(bool $getShared = true): ApprovalPolicy
    {
        if ($getShared) {
            return static::getSharedInstance('approvalPolicy') ?? static::approvalPolicy(false);
        }
        return new ApprovalPolicy();
    }

    public static function jobService(bool $getShared = true): JobService
    {
        if ($getShared) {
            return static::getSharedInstance('jobService') ?? static::jobService(false);
        }
        return new JobService();
    }

    public static function jobHandlers(bool $getShared = true): JobHandlerRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('jobHandlers') ?? static::jobHandlers(false);
        }
        return new JobHandlerRegistry();
    }

    public static function htmlSanitizer(bool $getShared = true): HtmlSanitizer
    {
        if ($getShared) {
            return static::getSharedInstance('htmlSanitizer') ?? static::htmlSanitizer(false);
        }
        return new HtmlSanitizer();
    }

    public static function urlPolicy(bool $getShared = true): UrlPolicy
    {
        if ($getShared) {
            return static::getSharedInstance('urlPolicy') ?? static::urlPolicy(false);
        }
        return new UrlPolicy();
    }

    public static function secretRedactor(bool $getShared = true): SecretRedactor
    {
        if ($getShared) {
            return static::getSharedInstance('secretRedactor') ?? static::secretRedactor(false);
        }
        return new SecretRedactor();
    }

    public static function requestValidator(bool $getShared = true): RequestValidator
    {
        if ($getShared) {
            return static::getSharedInstance('requestValidator') ?? static::requestValidator(false);
        }
        return new RequestValidator();
    }
}
