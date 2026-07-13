<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

use App\Libraries\Ai\AiGenerationResult;
use App\Libraries\Ai\Prompts\StructuredOutputValidator;

/**
 * Phase 3 — Stores schema-validated generation artifacts.
 *
 * Malformed output (parsedJson = null) must NOT create artifacts with content.
 * AI must NEVER create content versions directly — that is done by human approval.
 */
class AiGenerationArtifactService
{
    private StructuredOutputValidator $schemaValidator;

    public function __construct()
    {
        $this->schemaValidator = new StructuredOutputValidator();
    }

    /**
     * Validate and store an artifact from a generation result.
     * Returns the inserted artifact row, including schema_validation_status.
     */
    public function store(
        int $requestId,
        int $runId,
        AiGenerationResult $result,
        array $outputSchema,
        ?string $rawRef = null,
    ): array {
        $validationStatus = 'not_run';
        $validationErrors = null;
        $sanitisedJson    = null;

        if ($result->parsedJson !== null && ! empty($outputSchema)) {
            $errors = $this->schemaValidator->validate($result->parsedJson, $outputSchema);
            if (empty($errors)) {
                $validationStatus = 'passed';
                $sanitisedJson    = json_encode($this->sanitise($result->parsedJson));
            } else {
                $validationStatus = 'failed';
                $validationErrors = json_encode($errors);
            }
        } elseif ($result->parsedJson === null) {
            $validationStatus = 'failed';
            $validationErrors = json_encode(['Structured output could not be parsed from provider response.']);
        }

        $db = db_connect();
        $db->table('reach_ai_generation_artifacts')->insert([
            'generation_request_id'    => $requestId,
            'generation_run_id'        => $runId,
            'artifact_type'            => 'content',
            'structured_output_json'   => $result->parsedJson !== null ? json_encode($result->parsedJson) : null,
            'sanitised_output_json'    => $sanitisedJson,
            'raw_response_reference'   => $rawRef,
            'schema_validation_status' => $validationStatus,
            'schema_validation_errors' => $validationErrors,
            'created_at'               => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $db->insertID();
        return $this->findById($id);
    }

    public function findById(int $id): array
    {
        $row = db_connect()
            ->table('reach_ai_generation_artifacts')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if (! $row) {
            throw new \RuntimeException("Generation artifact #{$id} not found.");
        }

        return $row;
    }

    public function findByRunId(int $runId): ?array
    {
        return db_connect()
            ->table('reach_ai_generation_artifacts')
            ->where('generation_run_id', $runId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray() ?: null;
    }

    /**
     * Basic sanitisation: remove null values and truncate oversized strings.
     */
    private function sanitise(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = mb_substr($value, 0, 100000);
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitise($value);
            } elseif ($value !== null) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
