# Reach — Editorial Workflow

**Phase 2 — Unified Content Studio**

## Workflow States

```
idea → brief → draft → validation_pending → review_pending
                                             ↓               ↓
                              changes_requested → draft    approved → scheduled → ready_for_publication
                              review_pending → rejected
                              approved → archived
                              published → refresh_due → draft
```

All 13 states are enforced by `ContentWorkflowService::transition()`.

| State | Description |
|---|---|
| `idea` | Initial seed, no content yet |
| `brief` | Brief filled, not yet drafted |
| `draft` | Content being written |
| `validation_pending` | Automated validation checks running |
| `review_pending` | Waiting for editorial review |
| `changes_requested` | Reviewer asked for changes |
| `approved` | All required approval stages passed |
| `scheduled` | Publication date set |
| `ready_for_publication` | All pre-pub checks passed |
| `published` | Live (publication_status = published) |
| `refresh_due` | Content past refresh_due_at |
| `archived` | Withdrawn from use |
| `rejected` | Declined at review |

---

## State Machine Rules

`ContentWorkflowService` enforces:
- Only allowed forward/backward edges (see diagram above)
- Reject, archive: `reason` parameter required
- Editing an `approved` item creates a new version and resets to `draft`
- `archived` items cannot transition to any state (terminal state with override only)
- High/critical risk items require `compliance_review` stage before `final_approval`

---

## Multi-Stage Approval

Approval decisions are stored in `reach_approvals` (extended with `stage` column).

Required stages per content item are computed by `ContentWorkflowService::requiredStages()`:

| Content type | Risk | Required stages |
|---|---|---|
| Any | low/medium | `editorial_review`, `final_approval` |
| Any | high | + `subject_matter_review` |
| Any | critical | + `subject_matter_review`, `compliance_review` |
| pricing claim present | any | + `compliance_review` |

### Stage identifiers

`editorial_review`, `subject_matter_review`, `compliance_review`, `final_approval`

Each stage creates an `reach_approvals` row with `subject_type = 'content_item'` and the `stage` column set. The existing `ApprovalPolicy` handles self-approval and override logic per stage.

---

## Controller Actions

`ContentItemController` exposes workflow actions as POST routes:

| Route | Action |
|---|---|
| `POST /v1/content/items/:id/submit` | `draft → validation_pending` or `changes_requested → review_pending` |
| `POST /v1/content/items/:id/approve` | Advances approval stage; sets `approved` when final stage clears |
| `POST /v1/content/items/:id/reject` | Sets `rejected`, requires reason |
| `POST /v1/content/items/:id/request-changes` | Sets `changes_requested`, requires reason |
| `POST /v1/content/items/:id/archive` | Sets `archived`, requires reason |

---

## Audit Events

Every state transition fires `AuditLogger` with one of:

`CONTENT_SUBMITTED`, `CONTENT_APPROVED`, `CONTENT_REJECTED`, `CONTENT_CHANGES_REQUESTED`, `CONTENT_STATUS_CHANGED`, `CONTENT_ARCHIVED`

Actor metadata (user ID, IP, user-agent) is captured from the JWT-authenticated request.
