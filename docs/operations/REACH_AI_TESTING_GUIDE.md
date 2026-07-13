# Reach AI Testing Guide

## Principle: Zero Production API Calls

All automated tests (unit, feature, frontend) must use `MockAiProvider`. Production AI APIs must never be called in any test suite.

### Enforcing Mock Mode

Set `REACH_AI_MOCK=true` in the test environment. This forces `AiProviderRegistry::get()` to always return `MockAiProvider`.

Alternatively, instantiate `MockAiProvider` directly in tests:

```php
$provider = new MockAiProvider('success');
// or
$provider = new MockAiProvider('retryable_error');
```

## Mock Scenarios

| Scenario | Behaviour |
|----------|-----------|
| `success` | Returns structured JSON output with `title`, `body`, etc. |
| `malformed` | Returns `{{{invalid json` — tests schema validation rejection |
| `retryable_error` | Throws `AiProviderException` with `CATEGORY_RATE_LIMITED` (retryable) |
| `terminal_error` | Throws `AiProviderException` with `CATEGORY_AUTHENTICATION` (not retryable) |
| `timeout` | Throws `AiProviderException` with `CATEGORY_TIMEOUT` (retryable) |
| `budget` | Throws `AiProviderException` with `CATEGORY_QUOTA_EXCEEDED` (not retryable) |
| `empty` | Returns empty `rawContent` — tests empty output handling |

## PHP Unit Tests

```bash
cd server-php
php vendor/bin/phpunit --testsuite Unit
```

Key test areas:
- `tests/Unit/Ai/Security/` — injection, PII, confidential data
- `tests/Unit/Ai/Prompts/` — rendering, validation, schema registry
- `tests/Unit/Ai/Grounding/` — eligibility, conflict, size limiting
- `tests/Unit/Ai/Validation/` — validators, finding lifecycle
- `tests/Unit/Ai/Generation/` — artifact storage, budget results

## PHP Feature Tests

Feature tests require database access and are skipped in CI without a database. Run locally:

```bash
php vendor/bin/phpunit --testsuite Feature
```

## Frontend Tests

```bash
cd web
npx vitest run
```

Key test areas:
- `src/components/ai/__tests__/` — badges, panels, validation display
- `src/pages/ai/__tests__/` — all AI Control Centre pages
- `src/utils/__tests__/maskSecrets.test.js` — secret masking utility

All frontend tests mock `aiService.js` — no real HTTP calls to the backend.

## What to Verify Manually

These scenarios should be verified with a running instance before production deployment:

1. OpenAI provider health check returns `healthy`.
2. A generation request flows through the full pipeline (grounding → queued → processing → completed).
3. A malformed AI response results in `schema_validation_status: failed` and the request remains in `failed`.
4. A budget hard limit correctly blocks a generation with status `blocked`.
5. Attempting to approve a prompt version as an AI actor is rejected.
6. Attempting to waive a validation finding as an AI actor is rejected.
