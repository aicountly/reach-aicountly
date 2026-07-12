# Reach — Editorial Operations Guide

**Phase 2 — Unified Content Studio**

## Daily workflow for content teams

### 1. Morning: Review the Approval Centre

Navigate to `/approvals`. The dashboard shows:
- **Today** — items due for review today
- **Overdue** — items past their review date
- **High Risk** — content requiring extra scrutiny

Actions available per item: Approve, Reject, Request Changes, Waive Validation.

Bulk approval is available for low/medium risk items only. High/critical risk and product claims always require individual review.

### 2. Generate or review the Daily Pack

Navigate to `/content/daily-pack`. Click **Generate Pack** for today's date. Review each slot:
- Green badge = content approved and ready
- Yellow badge = in review
- Red badge = changes requested or rejected
- Grey card = placeholder (no content assigned)

For placeholder slots, click **Assign** and enter the content item ID to fill the slot.

### 3. Creating new content

Navigate to `/content` → **New Content**. Fill in:
- Content type
- Title
- Risk level
- Phase 1 knowledge anchors (product, persona, market, topic cluster)

Save → this creates `workflow_status = idea`. Progress through the workflow:
`idea → brief → draft → validation_pending → review_pending → approved`

---

## Role responsibilities

| Role | Primary actions |
|---|---|
| Marketing Manager | Create, submit, schedule, manage packs |
| Content Reviewer | Review, approve, reject, waive validations |
| Subject Matter Reviewer | Review high-risk claims |
| Compliance Reviewer | Final sign-off on critical risk items |
| Analyst / Viewer | Read-only access to all content |

---

## Notification types

Users receive in-app notifications (bell icon in the header) for:

| Event | Recipient |
|---|---|
| `assignment.created` | Assigned user |
| `review.requested` | All reviewers on item |
| `changes.requested` | Owner / writer |
| `approval.completed` | Owner |
| `content.rejected` | Owner |
| `due_date.approaching` | Assigned reviewers |
| `content.overdue` | Owner and managers |
| `refresh.due` | Content owner |

Notifications are in-app only in Phase 2. Email delivery infrastructure is scaffolded but disabled by default in `reach_notification_preferences`.

---

## Job schedule

Automated jobs run via `ReachSchedule` (CLI command `reach:schedule`):

| Time | Job | Purpose |
|---|---|---|
| 06:00 | `reach.content_schedule_readiness` | Mark scheduled content as executing |
| 07:00 | `reach.daily_marketing_pack` | Generate tomorrow's marketing pack |
| 07:30 | `reach.content_due_date_reminder` | Send reminders for items due in 24 h |
| 08:00 | `reach.daily_approval_digest` | Send daily digest of pending reviews |
| 09:00 | `reach.content_overdue_escalation` | Escalate overdue items to managers |
| 02:00 | `reach.content_refresh_detection` | Flag content past refresh_due_at |

---

## Escalation path

If content is overdue:
1. Owner receives in-app `content.overdue` notification
2. `ContentOverdueEscalationJob` (day +2): sends additional notification to marketing manager
3. Manual intervention required via approval centre

---

## Audit trail

Every material action is logged to `reach_audit_logs`. To view audit history for a content item, use the admin panel audit viewer filtered by `subject_type = 'content_item'` and `subject_id = <item_id>`.
