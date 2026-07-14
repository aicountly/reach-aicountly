<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\CampaignChannelVariantService;
use App\Models\Distribution\CampaignChannelVariantModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CampaignChannelVariantServiceTest extends CIUnitTestCase
{
    public function testValidateEmailDetectsEmptyBody(): void
    {
        $captured = [];
        $model = $this->createMock(CampaignChannelVariantModel::class);
        $model->method('find')
            ->willReturn(['id' => 1, 'channel' => 'email', 'content_json' => '{"subject":"Test"}']);
        $model->method('update')->willReturnCallback(function($id, $data) use (&$captured) {
            $captured = $data;
            return true;
        });
        $service = new CampaignChannelVariantService($model, new AuditLogger());
        $service->validate(1, null);
        $this->assertSame('invalid', $captured['validation_status']);
    }

    public function testValidateEmailDetectsMissingSubject(): void
    {
        $captured = [];
        $model = $this->createMock(CampaignChannelVariantModel::class);
        $model->method('find')
            ->willReturn(['id' => 1, 'channel' => 'email', 'content_json' => '{"body":"Hello World"}']);
        $model->method('update')->willReturnCallback(function($id, $data) use (&$captured) {
            $captured = $data;
            return true;
        });
        $service = new CampaignChannelVariantService($model, new AuditLogger());
        $service->validate(1, null);
        $findings = json_decode($captured['validation_findings'], true);
        $this->assertContains('SUBJECT_MISSING', array_column($findings, 'code'));
    }

    public function testValidateSmsDetectsBodyTooLong(): void
    {
        $longBody = str_repeat('A', 200);
        $captured = [];
        $model    = $this->createMock(CampaignChannelVariantModel::class);
        $model->method('find')
            ->willReturn(['id' => 1, 'channel' => 'sms', 'content_json' => json_encode(['body' => $longBody])]);
        $model->method('update')->willReturnCallback(function($id, $data) use (&$captured) {
            $captured = $data;
            return true;
        });
        $service = new CampaignChannelVariantService($model, new AuditLogger());
        $service->validate(1, null);
        $findings = json_decode($captured['validation_findings'], true);
        $this->assertContains('BODY_TOO_LONG', array_column($findings, 'code'));
    }

    public function testValidateWhatsAppRequiresTemplate(): void
    {
        $captured = [];
        $model = $this->createMock(CampaignChannelVariantModel::class);
        $model->method('find')
            ->willReturn(['id' => 1, 'channel' => 'whatsapp', 'content_json' => '{"message":"Hello"}']);
        $model->method('update')->willReturnCallback(function($id, $data) use (&$captured) {
            $captured = $data;
            return true;
        });
        $service = new CampaignChannelVariantService($model, new AuditLogger());
        $service->validate(1, null);
        $findings = json_decode($captured['validation_findings'], true);
        $this->assertContains('TEMPLATE_REQUIRED', array_column($findings, 'code'));
    }

    public function testValidatePassesForValidEmailVariant(): void
    {
        $captured = [];
        $model = $this->createMock(CampaignChannelVariantModel::class);
        $model->method('find')
            ->willReturn(['id' => 1, 'channel' => 'email', 'content_json' => '{"subject":"Hello","body":"Welcome to our newsletter!"}']);
        $model->method('update')->willReturnCallback(function($id, $data) use (&$captured) {
            $captured = $data;
            return true;
        });
        $service = new CampaignChannelVariantService($model, new AuditLogger());
        $service->validate(1, null);
        $this->assertSame('valid', $captured['validation_status']);
    }

    public function testValidateThrowsIfVariantNotFound(): void
    {
        $model = $this->createMock(CampaignChannelVariantModel::class);
        $model->method('find')->willReturn(null);
        $service = new CampaignChannelVariantService($model, new AuditLogger());
        $this->expectException(\RuntimeException::class);
        $service->validate(999, null);
    }
}
