<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Community answers join the strict OpenAI ⇄ Gemini alternation.
 *
 * ProviderRotationService::preferredNext() self-seeds a missing scope, so this
 * row is not strictly required for correctness — it is created up front so the
 * scope is visible to operators inspecting rotation state before the first
 * community answer is ever generated.
 */
class SeedCommunityAnswerProviderRotation extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "INSERT INTO reach_ai_provider_rotation (scope, created_at, updated_at)
             VALUES ('community_answer', NOW(), NOW())
             ON CONFLICT (scope) DO NOTHING"
        );
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM reach_ai_provider_rotation WHERE scope = 'community_answer'");
    }
}
