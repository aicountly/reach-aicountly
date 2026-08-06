<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Let reach_ai_generation_runs record the model_retired category.
 *
 * error_category is constrained to an enumerated list, so adding a category in
 * PHP without widening the constraint would make the first withdrawn-model
 * failure throw on INSERT — converting a failure the orchestrator now handles
 * (fall back to another model) into an unhandled database exception. The
 * category and the constraint have to move together.
 */
class AllowModelRetiredErrorCategory extends Migration
{
    private const CATEGORIES = [
        'configuration_error', 'authentication_error', 'rate_limited', 'timeout',
        'provider_unavailable', 'network_error', 'invalid_request', 'context_limit',
        'malformed_output', 'schema_validation_error', 'content_blocked',
        'budget_blocked', 'cancelled', 'model_retired', 'unknown',
    ];

    public function up(): void
    {
        $this->replaceConstraint(self::CATEGORIES);
    }

    public function down(): void
    {
        // Any row already carrying the category would fail the narrower check.
        $this->db->query(
            "UPDATE reach_ai_generation_runs SET error_category = 'unknown' WHERE error_category = 'model_retired'"
        );

        $this->replaceConstraint(array_values(array_diff(self::CATEGORIES, ['model_retired'])));
    }

    /** @param list<string> $categories */
    private function replaceConstraint(array $categories): void
    {
        $list = implode(',', array_map(fn ($c) => $this->db->escape($c), $categories));

        $this->db->query('ALTER TABLE reach_ai_generation_runs DROP CONSTRAINT IF EXISTS reach_ai_gen_runs_error_cat_chk');
        $this->db->query(
            'ALTER TABLE reach_ai_generation_runs ADD CONSTRAINT reach_ai_gen_runs_error_cat_chk '
            . 'CHECK (error_category IS NULL OR error_category IN (' . $list . '))'
        );
    }
}
