<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Libraries\AuditLogger;
use App\Libraries\Distribution\AudienceSegmentService;
use App\Models\Distribution\AudienceSegmentModel;
use App\Models\Distribution\AudienceSegmentRuleModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AudienceSegmentServiceTest extends CIUnitTestCase
{
    public function testCreateThrowsIfNameMissing(): void
    {
        $service = $this->makeService();
        $this->expectException(\InvalidArgumentException::class);
        $service->create(1, [], null);
    }

    public function testDeleteReturnsFalseForWrongTenant(): void
    {
        $model = $this->createMock(AudienceSegmentModel::class);
        $model->method('find')->willReturn(['id' => 99, 'tenant_id' => 2]);
        $service = new AudienceSegmentService($model, new AudienceSegmentRuleModel(), new AuditLogger());
        $this->assertFalse($service->delete(99, 999, null));
    }

    public function testPreviewThrowsForWrongTenant(): void
    {
        $model = $this->createMock(AudienceSegmentModel::class);
        $model->method('find')->willReturn(['id' => 99, 'tenant_id' => 2]);
        $service = new AudienceSegmentService($model, new AudienceSegmentRuleModel(), new AuditLogger());
        $this->expectException(\RuntimeException::class);
        $service->preview(99, 999);
    }

    private function makeService(): AudienceSegmentService
    {
        return new AudienceSegmentService(
            new AudienceSegmentModel(),
            new AudienceSegmentRuleModel(),
            new AuditLogger(),
        );
    }
}
