<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Prompts;

use App\Libraries\AuditLogger;

/**
 * Phase 3 — Manages immutable prompt versions.
 *
 * Prompt versions cannot be mutated after creation.
 * Approval can only be granted by a human actor.
 * AI must NEVER approve its own prompts.
 */
class PromptVersionService
{
    /**
     * Create a new version (always draft).
     * Returns the new version row.
     */
    public function createVersion(
        int $templateId,
        string $systemTemplate,
        string $userTemplate,
        array $variableSchema,
        array $outputSchema,
        array $generationDefaults,
        ?string $changeSummary,
        array $actor,
    ): array {
        $db = db_connect();

        $db->transStart();

        $nextNum = $this->nextVersionNumber($templateId);

        $db->table('reach_ai_prompt_versions')->insert([
            'prompt_template_id'       => $templateId,
            'version_number'           => $nextNum,
            'system_template'          => $systemTemplate,
            'user_template'            => $userTemplate,
            'variable_schema_json'     => json_encode($variableSchema),
            'output_schema_json'       => json_encode($outputSchema),
            'generation_defaults_json' => json_encode($generationDefaults),
            'change_summary'           => $changeSummary,
            'status'                   => 'draft',
            'created_actor_type'       => $actor['type'] ?? 'human',
            'created_by_user_id'       => $actor['user_id'] ?? null,
            'created_at'               => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $db->insertID();

        $db->table('reach_ai_prompt_templates')->update([
            'updated_by_user_id' => $actor['user_id'] ?? null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ], ['id' => $templateId]);

        $db->transComplete();

        AuditLogger::log('ai.prompt_version_created', [
            'template_id' => $templateId,
            'version_id'  => $id,
            'version_num' => $nextNum,
        ], $actor);

        return $this->findById($id);
    }

    /**
     * Approve a prompt version. Only a human actor may approve.
     * Sets the template's current_version_id to this version.
     *
     * @throws \RuntimeException if already approved or not in approvable state
     */
    public function approve(int $versionId, array $actor): array
    {
        if (($actor['type'] ?? 'human') === 'ai') {
            throw new \RuntimeException('AI must not approve prompt versions.');
        }

        $version = $this->findById($versionId);

        if ($version['status'] === 'approved') {
            throw new \RuntimeException('Prompt version is already approved.');
        }

        if (! in_array($version['status'], ['draft', 'needs_review'], true)) {
            throw new \RuntimeException("Cannot approve version in status '{$version['status']}'.");
        }

        $db = db_connect();
        $db->transStart();

        $db->table('reach_ai_prompt_versions')->update([
            'status'      => 'approved',
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $actor['user_id'] ?? null,
        ], ['id' => $versionId]);

        $db->table('reach_ai_prompt_templates')->update([
            'status'             => 'approved',
            'current_version_id' => $versionId,
            'approved_at'        => date('Y-m-d H:i:s'),
            'approved_by'        => $actor['user_id'] ?? null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ], ['id' => $version['prompt_template_id']]);

        $db->transComplete();

        AuditLogger::log('ai.prompt_version_approved', [
            'template_id' => $version['prompt_template_id'],
            'version_id'  => $versionId,
        ], $actor);

        return $this->findById($versionId);
    }

    /**
     * Returns the currently approved version for a template or null.
     */
    public function currentApproved(int $templateId): ?array
    {
        $db = db_connect();

        $row = $db->table('reach_ai_prompt_versions pv')
            ->join('reach_ai_prompt_templates pt', 'pt.current_version_id = pv.id')
            ->where('pt.id', $templateId)
            ->where('pv.status', 'approved')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_prompt_versions')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Prompt version #{$id} not found.");
        }

        return $row;
    }

    private function nextVersionNumber(int $templateId): int
    {
        $max = (int) db_connect()
            ->table('reach_ai_prompt_versions')
            ->where('prompt_template_id', $templateId)
            ->selectMax('version_number', 'max_num')
            ->get()
            ->getRowArray()['max_num'] ?? 0;

        return $max + 1;
    }
}
