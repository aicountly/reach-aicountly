<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;
use App\Libraries\Ai\Prompts\PromptRegistryService;
use App\Libraries\Ai\Prompts\PromptVersionService;
use App\Libraries\Ai\Prompts\OutputSchemaRegistry;

/**
 * Phase 3 — Prompt Template and Version management API.
 *
 * Endpoints:
 *   GET    /api/v1/ai/prompts                       — list templates
 *   POST   /api/v1/ai/prompts                       — create template (ai_prompt.manage)
 *   GET    /api/v1/ai/prompts/:id                   — show template
 *   GET    /api/v1/ai/prompts/:id/versions          — list versions for template
 *   POST   /api/v1/ai/prompts/:id/versions          — create new version (ai_prompt.manage)
 *   POST   /api/v1/ai/prompts/:id/versions/:vid/approve — approve version (ai_prompt.approve)
 *   GET    /api/v1/ai/prompts/schema-types           — list all schema types
 */
class PromptController extends BaseApiController
{
    private PromptRegistryService $registry;
    private PromptVersionService $versions;

    public function __construct()
    {
        $this->registry = new PromptRegistryService();
        $this->versions = new PromptVersionService();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db      = db_connect();
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = 20;

        $templates = $db->table('reach_ai_prompt_templates')
            ->whereNull('deleted_at')
            ->orderBy('updated_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $total = $db->table('reach_ai_prompt_templates')
            ->whereNull('deleted_at')
            ->countAllResults();

        return $this->ok(['templates' => $templates, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    public function create(): \CodeIgniter\HTTP\ResponseInterface
    {
        $data = $this->request->getJSON(true) ?? [];

        $required = ['name', 'slug', 'task_type', 'system_template', 'user_template'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->fail("{$field} is required", 422);
            }
        }

        try {
            $template = $this->registry->createTemplate(
                name:               $data['name'],
                slug:               $data['slug'],
                taskType:           $data['task_type'],
                systemTemplate:     $data['system_template'],
                userTemplate:       $data['user_template'],
                variableSchema:     $data['variable_schema'] ?? [],
                contentType:        $data['content_type'] ?? null,
                description:        $data['description'] ?? null,
                changeSummary:      $data['change_summary'] ?? null,
                generationDefaults: $data['generation_defaults'] ?? [],
                actor:              $this->actor(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(['template' => $template], 201);
    }

    public function show(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $template = is_numeric($id)
                ? $this->registry->findById((int) $id)
                : $this->registry->findBySlug($id);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }

        return $this->ok(['template' => $template]);
    }

    public function listVersions(string $templateId): \CodeIgniter\HTTP\ResponseInterface
    {
        $versions = db_connect()
            ->table('reach_ai_prompt_versions')
            ->where('prompt_template_id', $templateId)
            ->orderBy('version_number', 'DESC')
            ->get()
            ->getResultArray();

        return $this->ok(['versions' => $versions]);
    }

    public function createVersion(string $templateId): \CodeIgniter\HTTP\ResponseInterface
    {
        $data = $this->request->getJSON(true) ?? [];

        $required = ['system_template', 'user_template'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->fail("{$field} is required", 422);
            }
        }

        try {
            $template = $this->registry->findById((int) $templateId);
            $version  = $this->versions->createVersion(
                templateId:         (int) $templateId,
                systemTemplate:     $data['system_template'],
                userTemplate:       $data['user_template'],
                variableSchema:     $data['variable_schema'] ?? [],
                outputSchema:       $data['output_schema'] ?? OutputSchemaRegistry::get($template['content_type'] ?? 'generic'),
                generationDefaults: $data['generation_defaults'] ?? [],
                changeSummary:      $data['change_summary'] ?? null,
                actor:              $this->actor(),
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 404);
        }

        return $this->ok(['version' => $version], 201);
    }

    public function approveVersion(string $templateId, string $versionId): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $version = $this->versions->approve((int) $versionId, $this->actor());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(['version' => $version]);
    }

    public function schemaTypes(): \CodeIgniter\HTTP\ResponseInterface
    {
        $types = array_map(
            fn($t) => ['type' => $t, 'schema' => OutputSchemaRegistry::get($t)],
            OutputSchemaRegistry::allTypes()
        );

        return $this->ok(['schema_types' => $types]);
    }
}
