<?php

namespace Tests\Feature\Ai;

use App\Libraries\Ai\Generation\AiGenerationRequestService;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * A failed AI request must say why on its own row.
 *
 * failRequest() wrote the category and provider message to the app log and
 * the audit log only, so reach_ai_generation_requests recorded `status =
 * failed` and nothing else. Diagnosis then had to join the last run — and for
 * every failure raised *after* the provider answered (schema validation, stub
 * output) that run is marked "completed" with a null error. Two dozen
 * production requests read as "failed, last run completed, no error", which
 * is a dead end rather than a diagnosis.
 */
final class AiRequestFailureReasonTest extends DatabaseTestCase
{
    private function seedRequest(): int
    {
        $db = Database::connect();
        $db->table('reach_ai_generation_requests')->insert([
            'task_type'    => 'draft_generation',
            'content_type' => 'blog_post',
            'status'       => 'processing',
        ]);

        return (int) $db->insertID();
    }

    private function row(int $id): array
    {
        return Database::connect()->table('reach_ai_generation_requests')
            ->where('id', $id)->get()->getRowArray() ?? [];
    }

    public function testTheRowCanCarryAFailureReason(): void
    {
        $db = Database::connect();
        $db->dataCache = [];

        foreach (['error_category', 'redacted_error', 'failed_at'] as $column) {
            $this->assertTrue(
                $db->fieldExists($column, 'reach_ai_generation_requests'),
                "reach_ai_generation_requests.{$column} is missing",
            );
        }
    }

    public function testMarkFailedPersistsCategoryAndMessage(): void
    {
        $id = $this->seedRequest();

        (new AiGenerationRequestService())->markFailed(
            $id,
            'schema_validation_failed',
            'AI output did not pass schema validation: $.summary must be at most 1024 characters',
        );

        $row = $this->row($id);

        $this->assertSame('failed', $row['status']);
        $this->assertSame('schema_validation_failed', $row['error_category']);
        $this->assertStringContainsString('$.summary must be at most 1024 characters', $row['redacted_error']);
        $this->assertNotNull($row['failed_at'], 'A failure needs a timestamp of its own');
    }

    public function testTheReasonSurvivesAnOverlongProviderMessage(): void
    {
        $id = $this->seedRequest();

        (new AiGenerationRequestService())->markFailed(
            $id,
            str_repeat('x', 200),
            str_repeat('y', 5000),
        );

        $row = $this->row($id);

        // Truncated to fit rather than throwing and losing the reason entirely.
        $this->assertSame(64, mb_strlen($row['error_category']));
        $this->assertSame(2000, mb_strlen($row['redacted_error']));
    }

    public function testAFailedRequestIsQueryableByCategory(): void
    {
        $service = new AiGenerationRequestService();
        $service->markFailed($this->seedRequest(), 'model_retired', 'gemini-2.0-flash is no longer available');
        $service->markFailed($this->seedRequest(), 'model_retired', 'gemini-2.5-pro is no longer available');
        $service->markFailed($this->seedRequest(), 'budget_blocked', 'quota exceeded');

        $count = Database::connect()->table('reach_ai_generation_requests')
            ->where('error_category', 'model_retired')
            ->countAllResults();

        $this->assertSame(2, $count, 'Grouping failures by cause is the point of the column');
    }

    public function testASuccessfulRequestRecordsNoFailureReason(): void
    {
        $id = $this->seedRequest();

        (new AiGenerationRequestService())->updateStatus($id, 'completed', ['completed_at' => date('Y-m-d H:i:s')]);

        $row = $this->row($id);

        $this->assertSame('completed', $row['status']);
        $this->assertNull($row['error_category']);
        $this->assertNull($row['failed_at']);
    }
}
