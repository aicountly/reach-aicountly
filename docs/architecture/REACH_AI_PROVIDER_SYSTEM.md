# Reach AI Provider System

## Provider Interface

Every provider adapter must implement `App\Libraries\Ai\AiProviderInterface`:

```php
interface AiProviderInterface {
    public function getProviderKey(): string;
    public function isConfigured(): bool;
    public function healthCheck(): AiProviderHealthResult;
    public function generate(AiGenerationInput $input): AiGenerationResult;
    public function classifyError(\Throwable $error): AiProviderError;
}
```

## Adding a New Provider

1. Create `server-php/app/Libraries/Ai/Providers/YourProvider.php` implementing `AiProviderInterface`.
2. Read the API key from an environment variable (`AI_YOURPROVIDER_API_KEY`). Never hard-code keys.
3. Register it in `AiProviderRegistry::__construct()`.
4. Add a row to `reach_ai_providers` in a database migration.
5. Redact error messages before logging — never include the raw API key or full provider response.

## Provider Registry

`AiProviderRegistry::get(string $key)`:
- Returns the provider instance.
- Throws `\RuntimeException` if the provider is not configured (except for `mock`).
- Respects `REACH_AI_MOCK=true` to always return `MockAiProvider`.

## Circuit Breaker

`AiCircuitBreaker` uses `reach_ai_provider_health` to track failure state:

- **Closed** (normal): calls pass through.
- **Open** (tripped): after `FAILURE_THRESHOLD` (5) consecutive failures, all calls are blocked for `COOLDOWN_SECONDS` (120s).
- **Half-open** (probe): after cooldown, one probe call is allowed. Success → closed, failure → open.

The circuit breaker is integrated into `AiGenerationOrchestrator`. Open circuits trigger the fallback resolver.

## Error Categories

`AiProviderError` defines categories used by the fallback resolver:

| Category | Retryable | Triggers fallback |
|----------|-----------|-------------------|
| `authentication` | No | No |
| `rate_limited` | Yes | Yes |
| `timeout` | Yes | Yes |
| `context_too_long` | No | No |
| `invalid_request` | No | No |
| `service_unavailable` | Yes | Yes |
| `quota_exceeded` | No | Yes |
| `content_policy` | No | No |
| `unknown` | Yes | Yes |

## Model Routing

`AiModelRouter` selects the best model for a task:

1. If `REACH_AI_MOCK=true`, returns `MockAiProvider` + `mock-model`.
2. Queries `reach_ai_model_routes` for task+content_type match.
3. Joins `reach_ai_models` to verify the model is enabled and approved.
4. Returns `AiRouteDecision` or throws `AiRoutingException` if no route found.

## Fallback Resolution

`AiFallbackResolver` prevents infinite loops:

1. Queries `reach_ai_model_fallbacks` for the current route.
2. Checks `allowed_error_categories` to ensure the failure type triggers this fallback.
3. Skips models already attempted in this generation cycle.
4. Returns `null` if no further fallback is available.
