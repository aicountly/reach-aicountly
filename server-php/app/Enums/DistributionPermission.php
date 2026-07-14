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
    case ConnectionsRead       = 'distribution.read_connections';
    case ConnectionsManage     = 'distribution.manage_connections';
    case TemplatesRead         = 'distribution.read_templates';
    case TemplatesManage       = 'distribution.manage_templates';
    case ConsentRead           = 'distribution.read_consent';
    case ConsentManage         = 'distribution.manage_consent';
    case SuppressionRead       = 'distribution.read_suppression';
    case SuppressionManage     = 'distribution.manage_suppression';
    case OperationsRead        = 'distribution.read_operations';
    case AuditRead             = 'distribution.read_audit';
    case SmsRead               = 'sms.read';
    case SmsCreate             = 'sms.create';
    case SmsUpdate             = 'sms.update';
    case SmsSend               = 'sms.send';
    case SmsDispatch           = 'sms.dispatch';
}
