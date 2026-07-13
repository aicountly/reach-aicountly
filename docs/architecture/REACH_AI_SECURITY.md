# Reach AI Security Controls

## Overview

Phase 3 implements defence-in-depth security controls at every layer of the AI pipeline. These controls complement (and never replace) the existing Phase 0–2 access control, audit, and secret management systems.

## Prompt Injection Detection

`PromptInjectionDetector` scans user-supplied text before rendering it into any prompt.

**Detects:**
- "ignore/disregard/forget all previous instructions"
- Role reassignment: "you are now a...", "act as..."
- Jailbreak vocabulary: DAN, jailbreak, developer mode
- System prompt exposure requests: "print your system prompt", "reveal your instructions"
- Token-smuggling patterns: base64 decode instructions, delimiter injection

**Policy:** If injection is detected, the generation request is failed with status `failed` and reason `prompt_injection_detected`. No provider call is made.

## PII Scrubbing

`PiiScrubber` replaces PII patterns in user prompts before they reach the provider.

**Scrubs:** email addresses, phone numbers, credit card numbers, national IDs, NI numbers, passport-style IDs, IPv4 addresses, dates of birth.

PII scrubbing is applied to the rendered `userPrompt` before every provider call.

## Confidential Data Detection

`ConfidentialDataDetector` scans the serialised grounding context for inadvertent secrets.

**Detects:** AWS keys, OpenAI keys, Bearer tokens, JWTs, database connection strings, passwords, private key headers, Stripe keys, Slack tokens, internal markers.

If detected, generation fails immediately before any provider call.

## API Key Security

- Provider API keys are **never** stored in the database.
- Keys are read exclusively from environment variables (`AI_OPENAI_API_KEY`, etc.).
- The `OpenAiProvider` uses `$_ENV` to retrieve the key; it is never logged, returned, or stored.
- The `AiProviderController` omits `secret_env_reference` from API responses.
- The frontend `maskSecrets.js` utility provides a secondary defence against accidental exposure.

## Frontend Secret Masking

`maskSecrets.js` / `maskSecretsDeep()` scan string values in API responses for known secret patterns and replace them with placeholders. Applied proactively when displaying provider or generation data.

## Prompt Size Controls

`AiGenerationOrchestrator` enforces hard caps:
- `MAX_PROMPT_CHARS = 32,000` per prompt part (system/user)
- Prompts exceeding this are truncated before the provider call.

`GroundingSizeLimiter` controls the grounding context size before prompt construction.

## Retry and Fallback Depth

`AiGenerationOrchestrator::MAX_ATTEMPTS = 3` limits total provider attempts per request. The fallback resolver also prevents circular fallbacks via the `source_model_id <> fallback_model_id` constraint in migrations.

## Circuit Breaker

`AiCircuitBreaker` prevents cascading failures:
- 5 consecutive failures → circuit opens.
- 120s cooldown before half-open probe.
- Success on probe → circuit closes.
- Failure on probe → circuit remains open.

## Audit Trail

All significant AI events are logged via `AuditLogger` with 32+ AI-specific event constants (`ai.generation_requested`, `ai.budget_hard_limit_blocked`, `ai.prompt_injection_detected`, etc.). All are fanned out to Console audit endpoints.
