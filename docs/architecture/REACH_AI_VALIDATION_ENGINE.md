# Reach AI Validation Engine

## Overview

The validation engine runs after AI generation to check content quality, safety, and accuracy before human review. It never auto-approves. Only humans can waive findings.

## Validators (23 total)

### Deterministic Validators (19)

| Validator | Severity | Description |
|-----------|----------|-------------|
| `StructuredOutputValidator` | critical | JSON schema compliance |
| `TitleLengthValidator` | high/warning | Title length bounds |
| `MetaDescriptionLengthValidator` | warning | Meta desc 120–160 chars |
| `BodyMinimumLengthValidator` | high/warning | Body not empty or too short |
| `SlugFormatValidator` | warning | lowercase-alphanumeric-hyphens |
| `ClaimsReferencedValidator` | warning | Claims used when grounding had claims |
| `ProductClaimAccuracyValidator` | high | Only verified claim IDs in output |
| `BrandVoiceValidator` | high | No forbidden brand phrases |
| `ContentPolicyValidator` | critical | No policy-blocked keywords |
| `RiskNotesValidator` | warning | Flags risk_notes for human review |
| `SummaryLengthValidator` | high/warning | Summary presence and length |
| `HtmlSanitizationValidator` | critical | No script tags or event handlers |
| `CallToActionPresenceValidator` | warning | CTA on landing pages/emails |
| `DuplicateContentValidator` | high | SHA256 hash vs published content |
| `HashtagCountValidator` | warning | Platform-specific hashtag limits |
| `EmailSubjectLineLengthValidator` | high/warning | Email subject 30–60 chars |
| `FeatureAvailabilityValidator` | high | No claims about planned features |
| `ReadabilityScoreValidator` | warning | Flesch-Kincaid score in range |
| `WordCountValidator` | warning | Content-type specific minimums |

### AI-Assisted Validators (4)

| Validator | Uses | Description |
|-----------|------|-------------|
| `AiToneValidator` | MockAiProvider | Detects aggressive/off-brand tone |
| `AiFactualConsistencyValidator` | MockAiProvider | Checks facts vs grounding |
| `AiSeoQualityValidator` | MockAiProvider | SEO quality rating |
| `AiEngagementQualityValidator` | MockAiProvider | Engagement quality rating |

In production, AI-assisted validators call the configured provider. In test environments (`REACH_AI_MOCK=true`), they always use `MockAiProvider`.

## Finding Lifecycle

```
ValidationFinding (status: failed / warning / passed / not_applicable)
  → human reviewer sees findings in Content Studio
  → human with ai_validation.waive can waive a finding
  → waived findings are tracked with reason + waiver user
  → AI cannot waive findings (enforced in AiValidationFindingService)
```

## Integration with Content Studio

The Content Studio shows validation findings via `ValidationFindings.jsx`:
- Critical and high findings are highlighted prominently.
- Waive button only appears for users with `ai_validation.waive`.
- Content cannot proceed to approval if blocking findings are unresolved.
