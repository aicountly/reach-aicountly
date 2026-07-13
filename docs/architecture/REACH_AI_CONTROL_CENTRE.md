# Reach AI Control Centre

## Overview

The AI Control Centre is a frontend section of the Reach Portal that provides visibility and management for the Phase 3 AI engine. It is accessible via the sidebar under **AI Control Centre** and requires appropriate permissions.

## Pages

| Page | Route | Permission | Description |
|------|-------|------------|-------------|
| Dashboard | `/ai/dashboard` | `ai.view` | Summary stats: total generations, today's cost, recent requests |
| Providers | `/ai/providers` | `ai_provider.manage` | List providers + health/config status |
| Provider Detail | `/ai/providers/:id` | `ai_provider.manage` | Provider details; API keys never shown |
| Models | `/ai/models` | `ai_provider.manage` | Model pricing, capabilities, approval status |
| Routing | `/ai/routing` | `ai_provider.manage` | Route management information |
| Prompts | `/ai/prompts` | `ai_prompt.view` | Prompt template list |
| Prompt Detail | `/ai/prompts/:id` | `ai_prompt.view` | Version history + approve button (requires `ai_prompt.approve`) |
| Generations | `/ai/generations` | `ai.view` | Paginated generation request list |
| Generation Detail | `/ai/generations/:uuid` | `ai.view` | Request + runs + artifact + cancel button |
| Usage | `/ai/usage` | `ai_provider.manage` | Usage ledger: tokens, costs, dates |
| Budgets | `/ai/budgets` | `ai_provider.manage` | Budget limits with usage bar visualisation |
| Validations | `/ai/validations` | `ai.view` | Links to Content Studio for finding review |
| Health | `/ai/health` | `ai_provider.manage` | Live provider health + circuit state |

## Layout

`AiLayout.jsx` provides the shared top bar, Phase 3 badge, and horizontal navigation. Navigation items are filtered by user permissions via `usePermission`.

## API Security

- No provider API keys are ever included in frontend responses.
- The `AiProviderController` strips `secret_env_reference` from all responses.
- `maskSecrets.js` provides secondary client-side protection.
