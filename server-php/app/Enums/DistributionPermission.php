<?php

declare(strict_types=1);

namespace App\Enums;

enum DistributionPermission: string
{
    case Read                  = 'distribution.read';
    case Create                = 'distribution.create';
    case Update                = 'distribution.update';
    case Segment               = 'distribution.segment';
    case Preview               = 'distribution.preview';
    case TestSend              = 'distribution.test_send';
    case Submit                = 'distribution.submit';
    case Review                = 'distribution.review';
    case Approve               = 'distribution.approve';
    case Schedule              = 'distribution.schedule';
    case Dispatch              = 'distribution.dispatch';
    case Pause                 = 'distribution.pause';
    case Cancel                = 'distribution.cancel';
    case Retry                 = 'distribution.retry';
    case ConnectionsRead       = 'distribution.connections.read';
    case ConnectionsManage     = 'distribution.connections.manage';
    case TemplatesRead         = 'distribution.templates.read';
    case TemplatesManage       = 'distribution.templates.manage';
    case ConsentRead           = 'distribution.consent.read';
    case ConsentManage         = 'distribution.consent.manage';
    case SuppressionRead       = 'distribution.suppression.read';
    case SuppressionManage     = 'distribution.suppression.manage';
    case OperationsRead        = 'distribution.operations.read';
    case AuditRead             = 'distribution.audit.read';
    case SmsRead               = 'sms.read';
    case SmsCreate             = 'sms.create';
    case SmsUpdate             = 'sms.update';
    case SmsSend               = 'sms.send';
}
