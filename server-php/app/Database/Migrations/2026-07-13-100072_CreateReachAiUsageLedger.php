<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — AI Generation Engine
 *
 * Creates reach_ai_usage_ledger (immutable cost records) and reach_ai_budgets (configurable limits).
 */
class CreateReachAiUsageLedger extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_usage_ledger (
                id                        BIGSERIAL PRIMARY KEY,
                generation_request_id     BIGINT REFERENCES reach_ai_generation_requests(id),
                generation_run_id         BIGINT REFERENCES reach_ai_generation_runs(id),
                provider_id               BIGINT NOT NULL REFERENCES reach_ai_providers(id),
                model_id                  BIGINT NOT NULL REFERENCES reach_ai_models(id),
                prompt_version_id         BIGINT REFERENCES reach_ai_prompt_versions(id),
                content_item_id           BIGINT,
                product_id                BIGINT,
                content_type              VARCHAR(64),
                task_type                 VARCHAR(64) NOT NULL,
                actor_type                VARCHAR(32) NOT NULL DEFAULT 'human',
                user_id                   BIGINT,
                input_tokens              INT NOT NULL DEFAULT 0,
                output_tokens             INT NOT NULL DEFAULT 0,
                total_tokens              INT NOT NULL DEFAULT 0,
                estimated_cost            NUMERIC(18,8) NOT NULL DEFAULT 0,
                currency                  VARCHAR(8) NOT NULL DEFAULT 'USD',
                usage_date                DATE NOT NULL,
                billing_month             VARCHAR(7) NOT NULL,
                created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("CREATE INDEX idx_ai_usage_ledger_date ON reach_ai_usage_ledger (usage_date)");
        $this->db->query("CREATE INDEX idx_ai_usage_ledger_provider ON reach_ai_usage_ledger (provider_id, usage_date)");
        $this->db->query("CREATE INDEX idx_ai_usage_ledger_model ON reach_ai_usage_ledger (model_id, usage_date)");
        $this->db->query("CREATE INDEX idx_ai_usage_ledger_billing ON reach_ai_usage_ledger (billing_month)");
        $this->db->query("CREATE INDEX idx_ai_usage_ledger_user ON reach_ai_usage_ledger (user_id, usage_date) WHERE user_id IS NOT NULL");
        $this->db->query("CREATE INDEX idx_ai_usage_ledger_request ON reach_ai_usage_ledger (generation_request_id) WHERE generation_request_id IS NOT NULL");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_ai_budgets (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
                scope_type          VARCHAR(32) NOT NULL,
                scope_reference     VARCHAR(128) NOT NULL DEFAULT 'global',
                period_type         VARCHAR(16) NOT NULL DEFAULT 'daily',
                currency            VARCHAR(8) NOT NULL DEFAULT 'USD',
                warning_limit       NUMERIC(18,8) NOT NULL DEFAULT 0,
                hard_limit          NUMERIC(18,8) NOT NULL DEFAULT 0,
                used_amount         NUMERIC(18,8) NOT NULL DEFAULT 0,
                reset_at            TIMESTAMPTZ,
                enabled             BOOLEAN NOT NULL DEFAULT TRUE,
                override_permission VARCHAR(128),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $this->db->query("ALTER TABLE reach_ai_budgets
            ADD CONSTRAINT reach_ai_budgets_scope_chk
            CHECK (scope_type IN ('global','provider','model','product','content_type','user','task_type'))
        ");

        $this->db->query("ALTER TABLE reach_ai_budgets
            ADD CONSTRAINT reach_ai_budgets_period_chk
            CHECK (period_type IN ('daily','weekly','monthly','custom'))
        ");

        $this->db->query("CREATE UNIQUE INDEX uq_ai_budgets_scope ON reach_ai_budgets (scope_type, scope_reference, period_type, currency)");
        $this->db->query("CREATE UNIQUE INDEX uq_ai_budgets_uuid ON reach_ai_budgets (uuid)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_ai_budgets");
        $this->db->query("DROP TABLE IF EXISTS reach_ai_usage_ledger");
    }
}
