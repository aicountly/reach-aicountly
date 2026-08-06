<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * reach_ai_generation_requests recorded *that* a request failed and never why.
 *
 * AiGenerationOrchestrator::failRequest() wrote the category and the provider
 * message to the app log and the audit log only, so the row itself carried
 * nothing. Diagnosis then had to join the last run — which, for every failure
 * raised after a provider call succeeded, reports "completed" with a null
 * error. The result reads as a contradiction and tells an operator nothing.
 */
class AddFailureReasonToAiGenerationRequests extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE reach_ai_generation_requests ADD COLUMN IF NOT EXISTS error_category VARCHAR(64) NULL'
        );
        $this->db->query(
            'ALTER TABLE reach_ai_generation_requests ADD COLUMN IF NOT EXISTS redacted_error TEXT NULL'
        );
        $this->db->query(
            'ALTER TABLE reach_ai_generation_requests ADD COLUMN IF NOT EXISTS failed_at TIMESTAMPTZ NULL'
        );
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS idx_ai_requests_error_category '
            . 'ON reach_ai_generation_requests(error_category) WHERE error_category IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_ai_requests_error_category');
        $this->db->query('ALTER TABLE reach_ai_generation_requests DROP COLUMN IF EXISTS error_category');
        $this->db->query('ALTER TABLE reach_ai_generation_requests DROP COLUMN IF EXISTS redacted_error');
        $this->db->query('ALTER TABLE reach_ai_generation_requests DROP COLUMN IF EXISTS failed_at');
    }
}
