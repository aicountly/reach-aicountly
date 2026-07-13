# Reach AI Prompt Governance

## Principles

1. **Immutability**: Prompt versions cannot be modified after creation. A new version must be created for every change.
2. **Human approval only**: Only users with `ai_prompt.approve` permission can approve a version. AI actors are blocked.
3. **Approved-only generation**: Only `approved` prompt versions are used for normal generation.
4. **Schema enforcement**: Every prompt version is linked to an output schema. AI output must validate before an artifact is stored.

## Data Model

```
reach_ai_prompt_templates (metadata + pointer to current_version_id)
  ↓
reach_ai_prompt_versions (immutable; no updated_at column)
  ├── system_template (Handlebars-style {{variable}} syntax)
  ├── user_template
  ├── variable_schema_json (expected variables)
  ├── output_schema_json (JSON Schema for output validation)
  └── generation_defaults_json (temperature, max_tokens, etc.)
```

## Lifecycle

```
create (status: draft)
  → submit for review
  → human approves (status: approved)
  → template.current_version_id updated
  → used in generation
  → can be deprecated (new version takes over)
```

## Prompt Rendering

`PromptRenderer` substitutes `{{variable}}` placeholders using simple string replacement. No `eval()` or template engines that execute code.

`PromptVariableValidator::findMissing()` checks that all `{{variables}}` in the template are provided in the context before rendering.

## Output Schema Registry

`OutputSchemaRegistry` defines JSON Schema (draft-07 subset) for 16 content types:

`blog_post`, `landing_page`, `email_campaign`, `social_post`, `whatsapp_campaign`, `sms_campaign`, `push_notification`, `product_description`, `case_study`, `whitepaper`, `press_release`, `video_script`, `podcast_script`, `ad_copy`, `knowledge_article`, `generic`

All schemas enforce `additionalProperties: false` to prevent unexpected fields.

## API Endpoints

| Method | Endpoint | Permission |
|--------|----------|------------|
| GET | `/api/v1/ai/prompts` | `ai_prompt.view` |
| POST | `/api/v1/ai/prompts` | `ai_prompt.manage` |
| GET | `/api/v1/ai/prompts/:id` | `ai_prompt.view` |
| GET | `/api/v1/ai/prompts/:id/versions` | `ai_prompt.view` |
| POST | `/api/v1/ai/prompts/:id/versions` | `ai_prompt.manage` |
| POST | `/api/v1/ai/prompts/:id/versions/:vid/approve` | `ai_prompt.approve` |
| GET | `/api/v1/ai/prompts/schema-types` | `ai_prompt.view` |
