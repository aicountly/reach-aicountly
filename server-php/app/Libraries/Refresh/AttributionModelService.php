<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Models\Intelligence\AttributionConversionLinkModel;
use App\Models\Intelligence\AttributionTouchpointModel;
use App\Models\Refresh\AttributionAllocationFactModel;
use App\Models\Refresh\AttributionJourneyCalculationModel;
use App\Models\Refresh\AttributionModelModel;
use App\Models\Refresh\AttributionModelVersionModel;
use RuntimeException;

/**
 * Implements three transparent attribution models:
 *   - equal_weight: 1/N per touchpoint
 *   - position_based: 40% first, 40% last, 20% shared equally across middle
 *   - time_decay: exponential decay with configurable λ, normalised to sum 1.0
 *
 * Governance: every allocation result includes mandatory limitations note.
 * No causal claims. No revenue attribution. Observational data only.
 */
class AttributionModelService
{
    private const DEFAULT_LAMBDA = 0.1;

    public function __construct(
        private AttributionModelModel             $modelModel,
        private AttributionModelVersionModel      $versionModel,
        private AttributionTouchpointModel        $touchpointModel,
        private AttributionConversionLinkModel    $conversionModel,
        private AttributionJourneyCalculationModel $journeyModel,
        private AttributionAllocationFactModel    $allocationModel,
        private AuditLogger                       $auditLogger,
    ) {}

    public function createModel(int $tenantId, string $modelName, int $lookbackDays, ?int $createdBy = null): array
    {
        $definitions = $this->modelDefinitions();
        if (! isset($definitions[$modelName])) {
            throw new RuntimeException("Unknown attribution model: {$modelName}");
        }

        $def = $definitions[$modelName];
        $id = $this->modelModel->insert([
            'tenant_id'           => $tenantId,
            'model_name'          => $modelName,
            'description'         => $def['description'],
            'formula'             => $def['formula'],
            'lookback_window_days'=> $lookbackDays,
            'limitations'         => $def['limitations'],
            'is_active'           => false,
        ]);

        $this->auditLogger->log(
            userId:     $createdBy,
            action:     AuditLogger::ATTRIBUTION_MODEL_CREATED,
            entityType: 'attribution_model',
            entityId:   $id,
            extra:      ['model_name' => $modelName],
        );

        return $this->modelModel->find($id);
    }

    public function createModelVersion(int $modelId, array $weightRules, ?int $approvedBy = null): array
    {
        $latest = $this->versionModel->getLatest($modelId);
        $nextVersion = ($latest['version_number'] ?? 0) + 1;

        $model = $this->modelModel->find($modelId);
        $id = $this->versionModel->insert([
            'model_id'       => $modelId,
            'version_number' => $nextVersion,
            'formula'        => $model['formula'],
            'weight_rules'   => json_encode($weightRules),
            'approved_by'    => $approvedBy,
            'approved_at'    => $approvedBy ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->versionModel->find($id);
    }

    /**
     * Calculate attribution for a given conversion and model version.
     * Stores the journey calculation and per-touchpoint allocation facts (immutable).
     */
    public function calculate(int $tenantId, int $conversionLinkId, int $modelVersionId): array
    {
        $modelVersion = $this->versionModel->find($modelVersionId);
        if (! $modelVersion) throw new RuntimeException("Model version {$modelVersionId} not found");

        $model = $this->modelModel->find($modelVersion['model_id']);
        $conversion = $this->conversionModel->find($conversionLinkId);
        if (! $conversion) throw new RuntimeException("Conversion link {$conversionLinkId} not found");

        // Retrieve ordered touchpoints for this conversion
        $touchpoints = $this->touchpointModel->getForConversion($tenantId, $conversionLinkId);

        if (empty($touchpoints)) {
            return ['status' => 'no_touchpoints', 'conversion_link_id' => $conversionLinkId];
        }

        $weights = $this->computeWeights($model['model_name'], $touchpoints, $modelVersion);
        $n = count($touchpoints);

        $limitationsNote = $this->buildLimitationsNote($model, $n);

        $journeyId = $this->journeyModel->insert([
            'tenant_id'              => $tenantId,
            'conversion_link_id'     => $conversionLinkId,
            'model_version_id'       => $modelVersionId,
            'ordered_touchpoint_ids' => json_encode(array_column($touchpoints, 'id')),
            'total_touchpoints'      => $n,
            'identity_confidence'    => 'medium',
            'completeness_score'     => round($n / max($n, 5), 3),
            'limitations_note'       => $limitationsNote,
            'calculated_at'          => date('Y-m-d H:i:s'),
        ]);

        foreach ($touchpoints as $i => $tp) {
            $this->allocationModel->insert([
                'journey_calculation_id' => $journeyId,
                'touchpoint_id'          => $tp['id'],
                'touch_position'         => $i + 1,
                'allocation_weight'      => round($weights[$i], 6),
                'model_name'             => $model['model_name'],
                'model_version'          => $modelVersion['version_number'],
            ]);
        }

        $this->auditLogger->log(
            userId:     null,
            action:     AuditLogger::ATTRIBUTION_JOURNEY_CALCULATED,
            entityType: 'attribution_journey_calculation',
            entityId:   $journeyId,
            extra:      [
                'model'        => $model['model_name'],
                'touchpoints'  => $n,
                'conversion'   => $conversionLinkId,
            ],
            actorType:    'system',
            actorService: 'reach:attribution',
        );

        return [
            'journey_calculation_id' => $journeyId,
            'model_name'             => $model['model_name'],
            'total_touchpoints'      => $n,
            'limitations_note'       => $limitationsNote,
        ];
    }

    private function computeWeights(string $modelName, array $touchpoints, array $modelVersion): array
    {
        $n = count($touchpoints);
        if ($n === 0) return [];

        $rules = json_decode($modelVersion['weight_rules'], true) ?? [];

        return match ($modelName) {
            'equal_weight'   => array_fill(0, $n, 1.0 / $n),
            'position_based' => $this->positionWeights($n, $rules),
            'time_decay'     => $this->timeDecayWeights($touchpoints, $rules),
            default          => array_fill(0, $n, 1.0 / $n),
        };
    }

    private function positionWeights(int $n, array $rules): array
    {
        if ($n === 1) return [1.0];
        if ($n === 2) return [0.5, 0.5];

        $firstPct  = (float) ($rules['first_weight'] ?? 0.4);
        $lastPct   = (float) ($rules['last_weight']  ?? 0.4);
        $midTotal  = 1.0 - $firstPct - $lastPct;
        $midEach   = $midTotal / ($n - 2);

        $weights = array_fill(0, $n, $midEach);
        $weights[0]      = $firstPct;
        $weights[$n - 1] = $lastPct;
        return $weights;
    }

    private function timeDecayWeights(array $touchpoints, array $rules): array
    {
        $lambda = (float) ($rules['lambda'] ?? self::DEFAULT_LAMBDA);
        $conversionTime = strtotime($touchpoints[count($touchpoints) - 1]['touched_at'] ?? 'now');
        $raw = [];
        foreach ($touchpoints as $tp) {
            $daysAgo = max(0, ($conversionTime - strtotime($tp['touched_at'] ?? 'now')) / 86400);
            $raw[] = exp(-$lambda * $daysAgo);
        }
        $sum = array_sum($raw);
        return $sum > 0 ? array_map(fn($w) => $w / $sum, $raw) : array_fill(0, count($touchpoints), 1.0 / count($touchpoints));
    }

    private function buildLimitationsNote(array $model, int $n): string
    {
        return sprintf(
            'Model: %s. Formula: %s. Touchpoints: %d. '
            . '%s '
            . 'This is a modelled allocation, not factual causation. '
            . 'No revenue is attributed. Observational data only.',
            $model['model_name'],
            $model['formula'],
            $n,
            $model['limitations'],
        );
    }

    private function modelDefinitions(): array
    {
        return [
            'equal_weight' => [
                'description' => 'Each touchpoint receives equal credit',
                'formula'     => 'allocation = 1 / total_touchpoints',
                'limitations' => 'Treats all touchpoints equally regardless of position or recency.',
            ],
            'position_based' => [
                'description' => 'First and last touchpoints receive elevated credit; middle touchpoints share the remainder',
                'formula'     => 'first=40%, last=40%, middle=20% shared equally',
                'limitations' => 'Middle touchpoints may be underweighted in short journeys.',
            ],
            'time_decay' => [
                'description' => 'More recent touchpoints receive greater credit, decaying exponentially backwards',
                'formula'     => 'weight_i = e^(-lambda * days_before_conversion), then normalised',
                'limitations' => 'May undervalue early brand-awareness content in long journeys.',
            ],
        ];
    }
}
