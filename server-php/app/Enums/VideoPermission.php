<?php

declare(strict_types=1);

namespace App\Enums;

enum VideoPermission: string
{
    case Read               = 'video.read';
    case Create             = 'video.create';
    case Update             = 'video.update';
    case Generate           = 'video.generate';
    case Submit             = 'video.submit';
    case Review             = 'video.review';
    case Approve            = 'video.approve';
    case Render             = 'video.render';
    case Publish            = 'video.publish';
    case Cancel             = 'video.cancel';
    case Retry              = 'video.retry';
    case ConnectionsRead    = 'video_connections.read';
    case ConnectionsManage  = 'video_connections.manage';
    case OperationsRead     = 'video_operations.read';
    case AuditRead          = 'video_audit.read';
}
