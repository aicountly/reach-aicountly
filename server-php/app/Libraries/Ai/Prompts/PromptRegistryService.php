<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Prompts;

use App\Libraries\AuditLogger;

/**
 * Phase 3 — Manages prompt template records (the parent entity).
 *
 * Templates are created in draft state. The current approved version is set
 * by PromptVersionService::approve().
 */
class PromptRegistryService
{
    private PromptVersionService $versionService;

    public function __construct()
    {
        $this->versionService = new PromptVersionService();
    }

    /**
     * Create a template and its first draft version.
     */
    public function createTemplate(
        string $name,
        string $slug,
        string $taskType,
        string $systemTemplate,
        string $userTemplate,
        array $variableSchema,
        ?string $contentType = null,
        ?string $description = null,
        ?string $changeSummary = null,
        array $generationDefaults = [],
        array $actor = [],
    ): array {
        $db = db_connect();

        // Enforce unique slug
        $existing = $db->table('reach_ai_prompt_templates')
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->countAllResults();

        if ($existing > 0) {
            throw new \InvalidArgumentException("Prompt template slug '{$slug}' already exists.");
        }

        $db->transStart();

        $db->table('reach_ai_prompt_templates')->insert([
            'name'               => $name,
            'slug'               => $slug,
            'description'        => $description,
            'task_type'          => $taskType,
            'content_type'       => $contentType,
            'status'             => 'draft',
            'created_actor_type' => $actor['type'] ?? 'human',
            'created_by_user_id' => $actor['user_id'] ?? null,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $templateId = (int) $db->insertID();
        $db->transComplete();

        $outputSchema = OutputSchemaRegistry::get($contentType ?? 'generic');

        $this->versionService->createVersion(
            $templateId,
            $systemTemplate,
            $userTemplate,
            $variableSchema,
            $outputSchema,
            $generationDefaults,
            $changeSummary,
            $actor,
        );

        AuditLogger::log('ai.prompt_template_created', [
            'template_id' => $templateId,
            'slug'        => $slug,
        ], $actor);

        return $this->findBySlug($slug);
    }

    public function findBySlug(string $slug): array
    {
        $row = db_connect()
            ->table('reach_ai_prompt_templates')
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Prompt template '{$slug}' not found.");
        }

        return $row;
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_prompt_templates')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Prompt template #{$id} not found.");
        }

        return $row;
    }

    /**
     * Returns all approved templates for a given task type.
     */
    public function approvedForTask(string $taskType, ?string $contentType = null): array
    {
        $db = db_connect();
        $builder = $db->table('reach_ai_prompt_templates')
            ->where('task_type', $taskType)
            ->where('status', 'approved')
            ->whereNull('deleted_at');

        if ($contentType !== null) {
            $builder->groupStart()
                ->where('content_type', $contentType)
                ->orWhereNull('content_type')
                ->groupEnd();
        }

        return $builder->orderBy('updated_at', 'DESC')->get()->getResultArray();
    }

    public function getVersionService(): PromptVersionService
    {
        return $this->versionService;
    }
}
