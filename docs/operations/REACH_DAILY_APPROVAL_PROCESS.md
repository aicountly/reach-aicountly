# Reach — Daily Approval Process

**Phase 2 — Unified Content Studio**

## Purpose

This document describes the step-by-step daily approval process for content reviewers and compliance officers using the Reach Daily Approval Centre.

---

## Step 1: Open the Approval Centre

Go to `/approvals`. The centre loads stats for each area across the top.

If the Phase 2 API is unavailable (pre-migration environment), the page falls back to the legacy Phase 0 approval list automatically.

---

## Step 2: Triage by area

Work through areas in priority order:

1. **Overdue** — items past their `review_due_at` date
2. **Today** — items due today
3. **High Risk** — `risk_level = high` or `critical`
4. **Regulatory** — items requiring compliance sign-off
5. **Changes Requested** — items returned by reviewers
6. **Ready for Approval** — items in `review_pending` state

---

## Step 3: Review an item

Click an item card to expand it. Available information:

- Full title, slug, content type
- Current workflow status
- Risk level
- Assigned writers and reviewers
- Validation results (SEO, brand, fact-check, product claims, etc.)
- Approval stage progress (which stages have passed)
- Review due date

Actions:
- **Preview** — opens the content detail page in a new tab
- **Edit** — opens the content editor
- **Approve** — advances the current approval stage
- **Reject** — rejects at this stage (reason required)
- **Return for Changes** — sets `changes_requested` (reason required)
- **Waive Validation** — overrides a blocking validation result (reason required, `content_validation.waive` permission required)

---

## Step 4: Bulk approval (low risk only)

Select multiple low/medium risk items using the checkbox. Click **Approve Selected**.

**Bulk approval is blocked when any selected item has:**
- `risk_level = high` or `critical`
- A `blocking` or `failed` validation of type `product_claim` or `fact`
- Content type `product_announcement` or `release_announcement`

Items that fail the bulk-approval check remain unprocessed and are listed in the error response.

---

## Step 5: High-risk items

High and critical risk items must be approved individually. For critical risk:

1. `editorial_review` stage — content reviewer
2. `subject_matter_review` stage — SME
3. `compliance_review` stage — compliance officer
4. `final_approval` stage — marketing manager

Each stage appears as a separate approval record in `reach_approvals`. The content item does not reach `approved` workflow_status until all required stages complete.

---

## Multi-stage approval state

If a stage is not yet approved, the item shows "Stage X of Y" with the current stage highlighted. Approving at an intermediate stage advances to the next stage; only the actor assigned to the current stage can approve.

The `ApprovalPolicy` (Phase 0) enforces self-approval rules and overrides per stage.

---

## End-of-day checklist

- [ ] Overdue area is empty or escalated
- [ ] All "Today" items are actioned
- [ ] No critical risk items remain in `review_pending` more than 24 h
- [ ] Daily pack for tomorrow is generated and slots are filled
- [ ] Any waived validations have documented reasons
