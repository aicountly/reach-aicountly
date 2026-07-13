# Reach AI Operations Guide

## Environment Variables

Add these to `.env` for Phase 3:

```ini
# AI Generation — Provider Keys
AI_OPENAI_API_KEY=sk-...         # OpenAI production key (never commit)

# AI Generation — Mode
REACH_AI_MOCK=false              # Set true in local/CI to use MockAiProvider

# AI Generation — Limits (optional overrides)
AI_MAX_PROMPT_CHARS=32000        # Hard cap per prompt part
AI_MAX_ATTEMPTS=3                # Max fallback attempts per request
```

## Starting the Job Worker

The Phase 0 job worker handles AI generation jobs automatically:

```bash
php spark jobs:work              # Process all job types including reach.ai_generation
php spark jobs:work --type=reach.ai_generation  # Process AI jobs only
```

## Managing Providers

Providers are seeded via the `AiProviderRegistry`. To enable a new provider:
1. Create a new migration to insert a row into `reach_ai_providers`.
2. Implement `AiProviderInterface` in `server-php/app/Libraries/Ai/Providers/`.
3. Register in `AiProviderRegistry`.
4. Set the `secret_env_reference` column to the environment variable name (not the key value).

## Managing Budgets

Budget rows in `reach_ai_budgets` define cost limits:

```sql
INSERT INTO reach_ai_budgets (scope_type, scope_reference, period_type, warning_limit, hard_limit, currency, enabled)
VALUES ('global', NULL, 'daily', 10.00, 50.00, 'USD', true);
```

Budgets are checked before every generation run. `hard_limit` blocks generation; `warning_limit` logs a warning audit event.

## Approving Prompt Versions

1. Navigate to **AI Control Centre → Prompts**.
2. Open a prompt template.
3. Find the version to approve.
4. Click **Approve** (requires `ai_prompt.approve` permission).
5. Only this approved version will be used for normal generation.

**Important:** Prompt versions are immutable after creation. If changes are needed, create a new version.

## Monitoring Health

- **AI Control Centre → Health** shows live provider health.
- Circuit breaker state is shown per provider (closed/open/half-open).
- Worker logs include `[AI-GEN]` prefix for all generation-related events.
- Audit logs filter: `action LIKE 'ai.%'` for all AI events.

## Resetting Circuit Breakers

Directly update the database if a circuit needs manual reset:

```sql
UPDATE reach_ai_provider_health
SET is_circuit_open = FALSE, consecutive_failures = 0, circuit_opened_at = NULL
WHERE provider_id = (SELECT id FROM reach_ai_providers WHERE provider_key = 'openai');
```

## Cancelling Stuck Generations

```sql
-- Cancel all requests stuck in grounding/queued for over 30 minutes
UPDATE reach_ai_generation_requests
SET status = 'cancelled', updated_at = NOW()
WHERE status IN ('pending', 'grounding', 'queued')
  AND created_at < NOW() - INTERVAL '30 minutes';
```

Or via API: `POST /api/v1/ai/generations/:uuid/cancel` (requires `ai.cancel`).
