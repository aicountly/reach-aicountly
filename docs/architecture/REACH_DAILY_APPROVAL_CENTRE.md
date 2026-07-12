# Reach — Daily Approval Centre

**Phase 2 — Unified Content Studio**

## Overview

The Daily Approval Centre is the primary dashboard for reviewing and approving marketing content. It aggregates all content items pending review across types, risk levels, and due dates into eight categorized areas.

URL: `/approvals` (existing page upgraded in Phase 2)

---

## API Endpoints

| Method | Route | Description |
|---|---|---|
| GET | `/api/v1/approval-queue` | Full queue with filters |
| GET | `/api/v1/approval-queue/stats` | Counts per area |
| POST | `/api/v1/approval-queue/:id/approve` | Approve item |
| POST | `/api/v1/approval-queue/:id/reject` | Reject item (reason required) |
| POST | `/api/v1/approval-queue/:id/return` | Return for changes |
| POST | `/api/v1/approval-queue/:id/waive-validation` | Waive a blocking validation (reason required) |

---

## Dashboard Areas

| Area | Filter logic |
|---|---|
| Today | `review_due_at` = today |
| Overdue | `review_due_at` < today |
| High Risk | `risk_level` IN (`high`, `critical`) |
| Regulatory | Content types requiring compliance review |
| Changes Requested | `workflow_status = 'changes_requested'` |
| Ready for Approval | `workflow_status = 'review_pending'` |
| Scheduled | `workflow_status = 'scheduled'` |
| Recently Approved | `workflow_status = 'approved'`, approved within last 7 days |

---

## Card Fields

Each approval item card displays:

- Content type badge
- Workflow status badge
- Risk level badge
- Title, slug, content type
- Review due date (red if overdue)
- Assigned reviewers
- Validation status summary
- Outstanding approval stages
- Actions: Preview, Edit, Approve, Reject, Return for Changes, Waive Validation

---

## Bulk Action Restrictions

`ApprovalQueueController::bulkApprove()` **rejects** bulk approval when any selected item has:

- `risk_level` = `high` or `critical`
- A `product_claim` validation that is `failed` or `blocking`
- A `fact` validation that is `blocking`
- `content_type` = `product_announcement` or `release_announcement` (require individual review)

These restrictions are enforced server-side and surfaced as a 422 error with item-level detail.

---

## Required Permissions

| Action | Permission |
|---|---|
| View queue | `content.view` |
| Approve | `content.approve` |
| Reject / Return | `content.approve` |
| Waive validation | `content_validation.waive` |
| Bulk approve | `content.approve` |

---

## Backward Compatibility

The approval queue falls back to `approvalService.list()` (Phase 0/1 legacy endpoint) when the Phase 2 endpoint returns a non-200 response. This allows the page to work in environments where Phase 2 migrations have not yet been applied.
