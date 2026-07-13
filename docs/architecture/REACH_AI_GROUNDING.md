# Reach AI Grounding System

## Purpose

AI generation is grounded exclusively in approved Phase 1 knowledge. Grounding ensures that AI output reflects only verified, current product information — not hallucinated or outdated facts.

## Eligibility Rules

`GroundingEligibilityService` filters out knowledge entities that are:

- Soft-deleted (`deleted_at IS NOT NULL`)
- In disallowed statuses: `draft`, `pending_review`, `rejected`, `archived`
- Expired (`valid_until` is past)
- Marked `internal_only = true`
- Marked `is_confidential = true`
- Features with availability: `planned`, `unavailable`, `deprecated` (these must not be presented as current)

**Allowed** feature availability states: `available`, `limited`, `beta`

## Grounding Context Structure

```json
{
  "product": { "name": "...", "slug": "...", "description": "..." },
  "features": [...],
  "modules": [...],
  "personas": [...],
  "industries": [...],
  "claims": [...],
  "evidence": [...],
  "sources": [...],
  "brand_rules": [...],
  "policies": [...]
}
```

## Conflict Detection

`GroundingConflictDetector` identifies:
1. **Conflicting claims** — same `claim_type` with both positive and negative sentiments for the same product.
2. **Duplicate features** — same feature slug appearing multiple times.

Conflicts are logged to the grounding snapshot but do not block generation — the human reviewer evaluates the output.

## Size Management

`GroundingSizeLimiter` enforces a maximum character limit on the grounding context. When the context exceeds the limit, sections are trimmed in priority order (lowest priority first):

```
evidence → sources → personas → industries → features → modules 
  → claims → brand_rules → policies
```

Token estimation: `ceil(chars / 4)` (approximate GPT-4 tokenisation).

## Grounding Snapshots

Every generation run stores an immutable `reach_ai_grounding_snapshots` record:
- Captures the exact knowledge context used.
- Includes a SHA256 hash for integrity verification.
- Links to the `reach_ai_generation_run` for full provenance.
- Enables auditing: "What did the AI know when it generated this content?"

## Security

The `ConfidentialDataDetector` scans the serialised grounding context before passing it to any provider. If confidential patterns (API keys, passwords, etc.) are found, the generation is failed immediately.
