# Reach AI Generation Architecture — Phase 3

## Overview

Phase 3 introduces a production-ready, provider-independent AI generation and validation engine. It is built entirely on top of the existing Phase 0–2 infrastructure (job queue, permissions, audit, knowledge graph, content studio) and adds no parallel systems.

**Core constraint**: AI can generate, suggest, and validate. It cannot approve content, waive findings, publish, or send campaigns.

---

## Layered Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Frontend (React 19 + Vite)                                       │
│  ContentStudio: GenerationPanel, ValidationFindings,             │
│                 GroundingPreview, AiGenerationBadge              │
│  AI Control Centre: AiLayout + 12 pages                         │
│  aiService.js → /api/v1/ai/*                                     │
└──────────────────────────────────────────────────────────────────┘
        ↕  HTTP (JWT-authed)
┌──────────────────────────────────────────────────────────────────┐
│ API Layer (CodeIgniter 4.7)                                      │
│  AiGenerationController   /api/v1/ai/generate                    │
│  PromptController          /api/v1/ai/prompts/*                  │
│  AiProviderController      /api/v1/ai/providers/*                │
│  AiModelController         /api/v1/ai/models                     │
│  AiUsageController         /api/v1/ai/usage, /budgets            │
│  AiDashboardController     /api/v1/ai/dashboard, /health         │
└──────────────────────────────────────────────────────────────────┘
        ↕  Internal service calls
┌──────────────────────────────────────────────────────────────────┐
│ Orchestration Layer                                              │
│  AiGenerationOrchestrator (central coordinator)                  │
│  AiGenerationRequestService  ← AiGenerationRunService            │
│  AiGenerationArtifactService ← GroundingSnapshotService          │
│  AiBudgetService              AiCancellationService              │
└──────────────────────────────────────────────────────────────────┘
        ↕                             ↕
┌──────────────────┐     ┌────────────────────────────────────────┐
│ Provider Layer   │     │ Grounding Layer                        │
│ AiProviderReg.   │     │ AiGroundingContextBuilder              │
│ AiModelRouter    │     │ GroundingEligibilityService            │
│ AiFallbackRsolvr │     │ GroundingConflictDetector              │
│ AiCircuitBreaker │     │ GroundingSizeLimiter                   │
│ OpenAiProvider   │     │ ← KnowledgeGroundingService (Ph1)      │
│ MockAiProvider   │     └────────────────────────────────────────┘
└──────────────────┘
        ↕
┌──────────────────────────────────────────────────────────────────┐
│ Prompt Governance Layer                                          │
│  PromptRegistryService   PromptVersionService                    │
│  PromptRenderer          PromptVariableValidator                 │
│  OutputSchemaRegistry (16 content types)                        │
│  StructuredOutputValidator                                       │
└──────────────────────────────────────────────────────────────────┘
        ↕
┌──────────────────────────────────────────────────────────────────┐
│ Validation Layer                                                 │
│  AiValidationPipelineService                                     │
│  19 deterministic validators + 4 AI-assisted validators         │
│  AiValidationRunService  AiValidationFindingService              │
└──────────────────────────────────────────────────────────────────┘
        ↕
┌──────────────────────────────────────────────────────────────────┐
│ Security Layer                                                   │
│  PromptInjectionDetector  PiiScrubber                           │
│  ConfidentialDataDetector maskSecrets.js (frontend)             │
└──────────────────────────────────────────────────────────────────┘
        ↕
┌──────────────────────────────────────────────────────────────────┐
│ Infrastructure (Phase 0–2)                                      │
│  reach_jobs (PostgreSQL job queue)   AuditLogger                │
│  PermissionService                   SecretRedactor             │
│  reach_approvals (human approval)                               │
└──────────────────────────────────────────────────────────────────┘
```

---

## Provider Independence

All AI providers implement `AiProviderInterface`:
- `getProviderKey(): string`
- `isConfigured(): bool`
- `healthCheck(): AiProviderHealthResult`
- `generate(AiGenerationInput $input): AiGenerationResult`
- `classifyError(\Throwable $e): AiProviderError`

### Production Providers
- **OpenAiProvider** — reads `AI_OPENAI_API_KEY` from environment; never stores or logs the key.

### Testing Providers
- **MockAiProvider** — deterministic, scenario-based (`success`, `malformed`, `retryable_error`, `terminal_error`, `timeout`, `budget`, `empty`). Zero external calls. Used in all automated tests.

### `REACH_AI_MOCK=true` env var forces the mock provider for local/CI.

---

## Job-Based Execution

All long-running AI operations use the Phase 0 PostgreSQL job queue:

```
POST /api/v1/ai/generate
  → AiGenerationRequestService::create()   (status: pending)
  → JobService::enqueue('reach.ai_generation', {request_id: N})
  → returns 202 Accepted

Worker picks up job:
  → AiGenerationJob::handle()
  → AiGenerationOrchestrator::execute(requestId)
    → status: grounding → processing → completed/failed/blocked
```

---

## Prompt Governance

- Prompt versions are **immutable after creation** (no `updated_at` column).
- Only **approved** versions can be used for normal generation.
- Only humans with `ai_prompt.approve` permission can approve versions.
- AI actors are explicitly blocked from calling the approval endpoint.
- `OutputSchemaRegistry` defines JSON schemas for 16 content types; output must validate before an artifact is stored.

---

## Budget Enforcement

`AiBudgetService` enforces limits by scope and period:

| Scope | Period | Action on warning | Action on hard limit |
|-------|--------|-------------------|----------------------|
| global | daily/monthly | warn in audit log | block generation |
| provider | daily/monthly | warn | block |
| model | daily/monthly | warn | block |
| content_type | daily/monthly | warn | block |

Costs are recorded in `reach_ai_usage_ledger` (immutable, append-only).

---

## Human Approval Invariants

These invariants are enforced in code, not just policy:

1. `AiGenerationOrchestrator` never sets content workflow status to `approved`.
2. `PromptVersionService::approve()` throws if the actor is not a human user.
3. `AiValidationFindingService::waive()` throws if the actor is not a human user.
4. No API endpoint publishes content or dispatches campaigns.
5. Generation status `completed` means the draft is ready for **human review**, not published.

---

## Database Tables (Phase 3)

| Table | Purpose |
|-------|---------|
| `reach_ai_providers` | Provider metadata |
| `reach_ai_provider_health` | Runtime health + circuit breaker state |
| `reach_ai_models` | Model metadata + pricing |
| `reach_ai_model_routes` | Task/content-type routing rules |
| `reach_ai_model_fallbacks` | Fallback chain per route |
| `reach_ai_prompt_templates` | Prompt template metadata |
| `reach_ai_prompt_versions` | Immutable prompt versions |
| `reach_ai_generation_requests` | High-level generation requests |
| `reach_ai_generation_runs` | Individual provider attempts |
| `reach_ai_grounding_snapshots` | Immutable grounding context records |
| `reach_ai_generation_artifacts` | Schema-validated AI output |
| `reach_ai_usage_ledger` | Immutable cost records |
| `reach_ai_budgets` | Configurable cost limits |
| `reach_ai_validation_runs` | Validation pipeline executions |
| `reach_ai_validation_findings` | Individual validator results |
| `reach_content_similarity_records` | Duplicate content detection |
