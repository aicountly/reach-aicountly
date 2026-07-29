<?php

declare(strict_types=1);

namespace Tests\Unit\Refresh;

use App\Libraries\AuditLogger;
use App\Libraries\Refresh\ReleaseAcceptanceService;
use App\Models\Refresh\DisasterRecoveryTestModel;
use App\Models\Refresh\OperationalReadinessCheckModel;
use App\Models\Refresh\ReadinessFindingModel;
use App\Models\Refresh\ReleaseAcceptanceRecordModel;
use App\Models\Refresh\TechnicalDebtRecordModel;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class ReleaseAcceptanceServiceTest extends CIUnitTestCase
{
    public function testEvaluatePrerequisitesPassesWhenAllGatesClear(): void
    {
        $service = $this->makeService(
            findings: [],
            ops: [
                ['check_category' => 'deployment', 'check_name' => 'a', 'status' => 'passed'],
                ['check_category' => 'backup', 'check_name' => 'b', 'status' => 'not_applicable'],
                ['check_category' => 'rollback', 'check_name' => 'c', 'status' => 'passed'],
            ],
            drPassedTypes: ReleaseAcceptanceService::DR_TYPES,
            debt: [],
        );

        $result = $service->evaluatePrerequisites();
        $this->assertTrue($result['ready']);
        $this->assertCount(4, $result['checks']);
        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['passed'], $check['key'] . ' should pass');
        }
    }

    public function testEvaluatePrerequisitesFailsOnOpenFindings(): void
    {
        $service = $this->makeService(
            findings: [['id' => 1, 'severity' => 'critical', 'resolution_status' => 'open']],
            ops: [
                ['check_category' => 'deployment', 'check_name' => 'a', 'status' => 'passed'],
                ['check_category' => 'backup', 'check_name' => 'b', 'status' => 'passed'],
                ['check_category' => 'rollback', 'check_name' => 'c', 'status' => 'passed'],
            ],
            drPassedTypes: ReleaseAcceptanceService::DR_TYPES,
            debt: [],
        );

        $result = $service->evaluatePrerequisites();
        $this->assertFalse($result['ready']);
        $this->assertSame(1, $result['open_finding_count']);
    }

    public function testCreateReadyRecommendationBlockedWhenGatesFail(): void
    {
        $service = $this->makeService(
            findings: [['id' => 1, 'severity' => 'high', 'resolution_status' => 'open']],
            ops: [],
            drPassedTypes: [],
            debt: [],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue a ready recommendation');
        $service->create([
            'release_name'     => 'Phase 9',
            'recommendation'   => 'ready_controlled',
            'evidence_summary' => 'Attempt',
        ], 7);
    }

    public function testCreateNotReadyAllowedWhenGatesFail(): void
    {
        $acceptance = $this->createMock(ReleaseAcceptanceRecordModel::class);
        $acceptance->method('getLatest')->willReturn(null);
        $acceptance->method('insert')->willReturn(99);
        $acceptance->method('find')->willReturn([
            'id'                   => 99,
            'release_name'         => 'Phase 9',
            'recommendation'       => 'not_ready',
            'evidence_summary'     => 'Still blocked',
            'blockers_resolved'    => false,
            'limitations_accepted' => '[]',
            'accepted_risks'       => '[]',
            'prerequisite_checks'  => '{}',
            'accepted_by'          => 7,
        ]);

        $service = $this->makeService(
            findings: [['id' => 1, 'severity' => 'critical', 'resolution_status' => 'open']],
            ops: [],
            drPassedTypes: [],
            debt: [],
            acceptance: $acceptance,
        );

        $row = $service->create([
            'release_name'      => 'Phase 9',
            'recommendation'    => 'not_ready',
            'evidence_summary'  => 'Still blocked',
            'blockers_resolved' => false,
        ], 7);

        $this->assertSame(99, $row['id']);
        $this->assertSame('not_ready', $row['recommendation']);
    }

    public function testCreateRejectsDuplicateAcceptance(): void
    {
        $acceptance = $this->createMock(ReleaseAcceptanceRecordModel::class);
        $acceptance->method('getLatest')->willReturn(['id' => 12, 'release_name' => 'Existing']);

        $service = $this->makeService(
            findings: [],
            ops: [
                ['check_category' => 'deployment', 'check_name' => 'a', 'status' => 'passed'],
                ['check_category' => 'backup', 'check_name' => 'b', 'status' => 'passed'],
                ['check_category' => 'rollback', 'check_name' => 'c', 'status' => 'passed'],
            ],
            drPassedTypes: ReleaseAcceptanceService::DR_TYPES,
            debt: [],
            acceptance: $acceptance,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $service->create([
            'release_name'      => 'Phase 9b',
            'recommendation'    => 'ready_controlled',
            'evidence_summary'  => 'Second try',
            'blockers_resolved' => true,
        ], 7);
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param list<array<string,mixed>> $ops
     * @param list<string> $drPassedTypes
     * @param list<array<string,mixed>> $debt
     */
    private function makeService(
        array $findings,
        array $ops,
        array $drPassedTypes,
        array $debt,
        ?ReleaseAcceptanceRecordModel $acceptance = null,
    ): ReleaseAcceptanceService {
        $findingModel = $this->createMock(ReadinessFindingModel::class);
        $findingModel->method('getOpenBlockers')->willReturn($findings);

        $opsModel = $this->createMock(OperationalReadinessCheckModel::class);
        $opsModel->method('forCategories')->willReturnCallback(
            static function (array $categories) use ($ops): array {
                return array_values(array_filter(
                    $ops,
                    static fn ($r) => in_array($r['check_category'], $categories, true),
                ));
            }
        );

        $passedLookup = array_fill_keys($drPassedTypes, true);
        $drModel = $this->createMock(DisasterRecoveryTestModel::class);
        $drModel->method('hasPassedType')->willReturnCallback(
            static fn (string $type): bool => ! empty($passedLookup[$type])
        );

        $debtModel = $this->createMock(TechnicalDebtRecordModel::class);
        $debtModel->method('getOpenBlockers')->willReturn($debt);

        $acceptanceModel = $acceptance ?? $this->createMock(ReleaseAcceptanceRecordModel::class);
        if ($acceptance === null) {
            $acceptanceModel->method('getLatest')->willReturn(null);
            $acceptanceModel->method('insert')->willReturn(1);
            $acceptanceModel->method('find')->willReturn(['id' => 1]);
        }

        $audit = $this->createMock(AuditLogger::class);

        return new ReleaseAcceptanceService(
            $acceptanceModel,
            $findingModel,
            $opsModel,
            $drModel,
            $debtModel,
            $audit,
        );
    }
}
