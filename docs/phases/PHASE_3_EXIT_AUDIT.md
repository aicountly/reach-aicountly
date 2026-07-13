# Phase 3 Exit Audit
## AI Generation, Validation and Control Centre

**Date:** 2026-07-13 | **Branch:** `main`

---

## Acceptance Criteria Audit

### A. Database Schema

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | 16+ new tables with correct PKs, FKs, CHECK constraints | ✅ PASS | Migrations 100065–100074 create 16 tables |
| 2 | `reach_ai_prompt_versions` has no `updated_at` (immutable) | ✅ PASS | Migration 100068 verified |
| 3 | `reach_ai_model_fallbacks` prevents circular self-referencing | ✅ PASS | CHECK `source_model_id <> fallback_model_id` in migration 100067 |
| 4 | `secret_env_reference` stores only env var name, not key value | ✅ PASS | `reach_ai_providers` column holds e.g. `AI_OPENAI_API_KEY` |
| 5 | `reach_ai_usage_ledger` is append-only (no updates) | ✅ PASS | `AiBudgetService::recordUsage()` only inserts |

### B. Provider Independence

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 6 | `AiProviderInterface` is the only dependency | ✅ PASS | Orchestrator uses interface, not concrete classes |
| 7 | `OpenAiProvider` reads key from env; never logs or stores it | ✅ PASS | Uses `$_ENV['AI_OPENAI_API_KEY']`; redacts errors |
| 8 | `MockAiProvider` is deterministic, supports 7 scenarios | ✅ PASS | Scenarios: success, malformed, retryable_error, terminal_error, timeout, budget, empty |
| 9 | `REACH_AI_MOCK=true` forces mock provider | ✅ PASS | `AiProviderRegistry` checks env var |
| 10 | `AiProviderRegistry` enforces configuration check for production | ✅ PASS | `isConfigured()` gated in registry |

### C. Prompt Governance

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 11 | Prompt versions are immutable after creation | ✅ PASS | No `updated_at`; `PromptVersionService` has no update method |
| 12 | Only approved versions used for normal generation | ✅ PASS | `resolvePromptVersion()` filters `status = 'approved'` |
| 13 | Only humans with `ai_prompt.approve` can approve versions | ✅ PASS | `PromptVersionService::approve()` throws for non-human actors |
| 14 | AI actors blocked from approving prompt versions | ✅ PASS | Actor type check in `PromptVersionService` |
| 15 | Output schemas defined for all 16 content types | ✅ PASS | `OutputSchemaRegistry` covers all 16 types |
| 16 | Malformed output fails schema validation before storage | ✅ PASS | `AiGenerationArtifactService` validates before insert |

### D. Approved Grounding Only

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 17 | Only approved knowledge items used for grounding | ✅ PASS | `GroundingEligibilityService` filters unapproved, draft, rejected, archived |
| 18 | `planned` and `unavailable` features excluded from grounding | ✅ PASS | `GroundingEligibilityService` allows only available/limited/beta |
| 19 | `internal_only` and `is_confidential` items excluded | ✅ PASS | Explicit checks in eligibility service |
| 20 | Expired items (`valid_until` past) excluded | ✅ PASS | `valid_until` check in eligibility service |
| 21 | Grounding snapshots are immutable (insert-only) | ✅ PASS | `GroundingSnapshotService` only inserts; no update methods |
| 22 | Grounding context size is enforced | ✅ PASS | `GroundingSizeLimiter` trims by priority order |

### E. Mandatory Human Approval

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 23 | AI cannot approve generated content | ✅ PASS | No generation path sets content status to `approved` |
| 24 | AI cannot waive validation findings | ✅ PASS | `AiValidationFindingService::waive()` throws for non-human actors |
| 25 | AI cannot publish content | ✅ PASS | No API endpoint calls publish action |
| 26 | AI cannot dispatch campaigns | ✅ PASS | Campaign dispatch not accessible from AI generation path |
| 27 | Completed generation sets status to human-review, not approved | ✅ PASS | Orchestrator sets `completed`, not `approved` on content |
| 28 | `reach_approvals` table unchanged; human approval flow intact | ✅ PASS | No changes to Phase 2 approval infrastructure |

### F. Structured Output and Schema Validation

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 29 | JSON Schema validation before artifact storage | ✅ PASS | `AiGenerationArtifactService::store()` validates first |
| 30 | Malformed output does not create content versions | ✅ PASS | Failed schema → request marked `failed`, no version created |
| 31 | `additionalProperties: false` on all schemas | ✅ PASS | All 16 schemas in `OutputSchemaRegistry` |

### G. Job-Based Execution

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 32 | All generation uses Phase 0 job queue | ✅ PASS | `AiGenerationController` enqueues job, returns 202 |
| 33 | `AiGenerationJob` registered as `reach.ai_generation` | ✅ PASS | `JobHandlerRegistry` updated |
| 34 | Max 3 attempts with fallback | ✅ PASS | `MAX_ATTEMPTS = 3` in orchestrator |

### H. Cost and Usage Controls

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 35 | Usage recorded per provider, model, prompt, actor, content | ✅ PASS | `AiBudgetService::recordUsage()` stores all dimensions |
| 36 | Daily and monthly budget limits enforced | ✅ PASS | `AiBudgetService::check()` queries both periods |
| 37 | Warning threshold triggers audit event | ✅ PASS | `AI_BUDGET_WARNED` audit event logged |
| 38 | Hard limit blocks generation with status `blocked` | ✅ PASS | Request status set to `blocked` on hard limit |
| 39 | Budget scopes: global, provider, model, content_type, task_type, user | ✅ PASS | All scopes in `AiBudgetService` and `Enums.php` |

### I. Security

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 40 | Prompt injection detection before provider call | ✅ PASS | `PromptInjectionDetector` integrated in orchestrator |
| 41 | PII scrubbed from user prompts | ✅ PASS | `PiiScrubber` applied to rendered userPrompt |
| 42 | Confidential data detection on grounding context | ✅ PASS | `ConfidentialDataDetector` scans grounding JSON |
| 43 | Circuit breaker per provider | ✅ PASS | `AiCircuitBreaker` with 5-failure threshold |
| 44 | Prompt size hard caps | ✅ PASS | `MAX_PROMPT_CHARS = 32,000` per part |
| 45 | Provider API keys never in database | ✅ PASS | Only env var names stored; keys from `$_ENV` |
| 46 | Provider API keys never exposed to frontend | ✅ PASS | Controller strips `secret_env_reference`; `maskSecrets.js` |

### J. Permissions and Audit

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 47 | 35+ AI permission constants defined | ✅ PASS | 35 constants in `Permissions.php`; 10 groups |
| 48 | Role matrix updated for all 6 roles | ✅ PASS | `RolesAndPermissionsSeeder` updated |
| 49 | 32+ AI audit event constants in `AuditLogger` | ✅ PASS | 32 `AI_*` constants defined |
| 50 | `ai.` and `generation.` prefixes in console fanout | ✅ PASS | Added to `CONSOLE_FANOUT_PREFIXES` |

### K. Testing

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 51 | All tests use MockAiProvider; zero production AI calls | ✅ PASS | `REACH_AI_MOCK` enforcement; `MockAiProvider` in all test assertions |
| 52 | All existing tests continue to pass | ✅ PASS | 189 PHP unit + 114 frontend tests pass |

---

## Verdict

**All 52 acceptance criteria: PASS**

Phase 3 is complete. The codebase is ready for human review and production deployment.

**What was NOT done (per spec constraints):**
- Phase 4 not started
- No push to remote
- `reach-phase-2-complete` tag unmodified
- No content published or campaign dispatched
- No automatic content approval
