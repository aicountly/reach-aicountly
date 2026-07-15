<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Models\Intelligence\CompetitorModel;
use App\Models\Intelligence\CompetitorAliasModel;
use App\Models\Intelligence\CompetitorObservationAggregateModel;
use App\Models\Intelligence\AiVisibilityObservationModel;

class CompetitorService
{
    public function __construct(
        private CompetitorModel                     $competitorModel,
        private CompetitorAliasModel                $aliasModel,
        private CompetitorObservationAggregateModel $aggregateModel,
        private AiVisibilityObservationModel        $observationModel,
        private AuditLogger                         $auditLogger,
    ) {}

    public function createCompetitor(int $tenantId, array $data): array
    {
        $data['tenant_id'] = $tenantId;
        $id = $this->competitorModel->insert($data);
        $this->auditLogger->log(null, AuditLogger::COMPETITOR_CREATED, 'competitor', (int)$id, null, $this->competitorModel->find($id), null, 'human');
        return $this->competitorModel->find($id);
    }

    public function addAlias(int $competitorId, string $aliasType, string $aliasValue): array
    {
        $crossCheck = $this->aliasModel->where('alias_value', $aliasValue)->first();
        if ($crossCheck && (int) $crossCheck['competitor_id'] !== $competitorId) {
            $this->auditLogger->log(null, AuditLogger::COMPETITOR_ALIAS_CONFLICT, 'competitor_alias', $competitorId,
                null, null, ['alias' => $aliasValue, 'conflict_with' => $crossCheck['competitor_id']], 'system');
            throw new \RuntimeException("Alias '{$aliasValue}' already used by another competitor ({$crossCheck['competitor_id']})");
        }

        $id = $this->aliasModel->insert([
            'competitor_id' => $competitorId,
            'alias_type'    => $aliasType,
            'alias_value'   => $aliasValue,
        ]);
        $this->auditLogger->log(null, AuditLogger::COMPETITOR_ALIAS_ADDED, 'competitor_alias', (int)$id, null, null, null, 'human');
        return $this->aliasModel->find($id);
    }

    public function aggregateObservations(int $competitorId, int $promptId, int $tenantId, string $periodStart, string $periodEnd): array
    {
        $competitor = $this->competitorModel->find($competitorId);
        if (!$competitor) throw new \RuntimeException("Competitor {$competitorId} not found");

        $aliases     = $this->aliasModel->where('competitor_id', $competitorId)->findAll();
        $aliasValues = array_column($aliases, 'alias_value');
        $aliasValues[] = $competitor['name'];

        $allObs   = $this->observationModel->getForPromptInPeriod($promptId, $tenantId);
        $mentions = array_filter($allObs, fn($o) => in_array($o['entity_mentioned'], $aliasValues, true)
                                                    && $o['coverage_state'] === 'mentioned');
        $total    = count($allObs);

        $existing = $this->aggregateModel->where('competitor_id', $competitorId)
                                         ->where('prompt_id', $promptId)
                                         ->where('period_start', $periodStart)
                                         ->where('period_end', $periodEnd)
                                         ->first();

        $data = [
            'competitor_id'     => $competitorId,
            'prompt_id'         => $promptId,
            'tenant_id'         => $tenantId,
            'period_start'      => $periodStart,
            'period_end'        => $periodEnd,
            'total_runs'        => $total,
            'mention_count'     => count($mentions),
            'mention_rate'      => $total > 0 ? count($mentions) / $total : 0.0,
            'sample_scope_note' => 'Sample of AI responses in monitored period. Does not represent comprehensive market data.',
            'computed_at'       => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->aggregateModel->update($existing['id'], $data);
            return $this->aggregateModel->find($existing['id']);
        }

        $id = $this->aggregateModel->insert($data);
        $this->auditLogger->log(null, AuditLogger::COMPETITOR_OBSERVATION_AGGREGATED, 'competitor_observation', (int)$id, null, null, null, 'system');
        return $this->aggregateModel->find($id);
    }
}
