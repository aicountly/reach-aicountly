<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use App\Enums\SuppressionReason;
use App\Libraries\AuditLogger;
use App\Libraries\Distribution\SuppressionService;
use App\Models\Distribution\ChannelSuppressionModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SuppressionServiceTest extends CIUnitTestCase
{
    public function testHashAddressIsDeterministic(): void
    {
        $hash1 = ChannelSuppressionModel::hashAddress(1, 'email', 'user@example.com');
        $hash2 = ChannelSuppressionModel::hashAddress(1, 'email', 'user@example.com');
        $this->assertSame($hash1, $hash2);
    }

    public function testHashAddressDifferentForDifferentTenants(): void
    {
        $hash1 = ChannelSuppressionModel::hashAddress(1, 'email', 'user@example.com');
        $hash2 = ChannelSuppressionModel::hashAddress(2, 'email', 'user@example.com');
        $this->assertNotSame($hash1, $hash2);
    }

    public function testMaskAddressHidesEmail(): void
    {
        $masked = ChannelSuppressionModel::maskAddress('john.doe@example.com');
        $this->assertStringContainsString('@', $masked);
        $this->assertStringContainsString('***', $masked);
    }

    public function testMaskAddressHidesPhone(): void
    {
        $masked = ChannelSuppressionModel::maskAddress('+919876543210');
        $this->assertStringContainsString('****', $masked);
        $this->assertStringNotContainsString('3210', $masked);
    }

    public function testRemoveReturnsFalseForWrongTenant(): void
    {
        $model = $this->createMock(ChannelSuppressionModel::class);
        $model->method('find')->willReturn(['id' => 1, 'tenant_id' => 99]);
        $service = new SuppressionService($model, new AuditLogger());
        $this->assertFalse($service->remove(1, 999, null));
    }

    public function testSuppressionReasonIsPermanentForComplaint(): void
    {
        $this->assertTrue(SuppressionReason::Complaint->isPermanent());
    }

    public function testSuppressionReasonIsNotPermanentForManual(): void
    {
        $this->assertFalse(SuppressionReason::Manual->isPermanent());
    }
}
