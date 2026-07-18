# AICOUNTLY Reach — Editorial SMS Templates (DLT)

Transactional SMS for Reach editorial workflow notifications via **Digimiles** (AOC Portal API at `https://api.aoc-portal.com/v1/sms`).

| Item | Value |
|------|--------|
| Sender ID | `AICTLY` |
| Category | Transactional |
| Brand prefix | `AICOUNTLY Reach:` |
| Gateway config | **Console** `server-php/.env` (not Reach) |
| Template storage | Console DB `product_notification_templates` (`product_slug = reach`) |
| Admin UI | **Console → Communications → Notification Triggers** (select **AICOUNTLY Reach**) |

Reach sends events to Console; Console resolves templates and sends SMS/email using shared gateway credentials.

---

## Environment variables (Console server)

Configure on **Console** `server-php/.env` (same as Flow):

```env
EMAIL_API_KEY=hidden
SENDER_EMAIL=support@aicountly.com
SENDER_NAME=AICOUNTLY Reach
EMAIL_API_FORMAT=multipart

SMS_PROVIDER=aoc-portal
SMS_API_URL=https://api.aoc-portal.com/v1/sms
SMS_API_KEY=hidden
SMS_SENDER_ID=AICTLY
SMS_TYPE=TRANS
SMS_COUNTRY_CODE=91
SMS_SKIP_SEND=false

WHATSAPP_API_KEY=
WHATSAPP_WABA_ID=
```

Reach `server-php/.env` only needs:

```env
CONSOLE_API_BASE_URL=https://console.aicountly.org/api
CONSOLE_API_TOKEN=<Console CONSOLE_SERVICE_KEY>
REACH_APP_URL=https://reach.aicountly.org
```

---

## Go-live checklist

1. Register all SMS templates below in **DLT** (India) and obtain template IDs.
2. Run Console migration `021_reach_notification_templates.sql`.
3. In **Console → Communications → Notification Triggers → AICOUNTLY Reach**, set each SMS **DLT template ID** and verify message body matches DLT registration.
4. Set template status to **Active** after DLT approval.
5. Set `SMS_SKIP_SEND=false` on Console only after DLT approval.
6. Ensure Reach users have **mobile numbers** on their Console identity record (SMS resolves phone via Console user lookup by email).
7. Set `CONSOLE_API_TOKEN` on Reach to match Console inbound service key.

---

## SMS template catalog (for DLT registration)

Use `{#var#}` placeholders when registering in DLT. Variable names in Reach templates use `{{content_title}}` style in Console UI — map to DLT `{#var#}` slots in order.

### 1. Content assigned

| Field | Value |
|-------|--------|
| Trigger key | `assignment.created` |
| When sent | User assigned to content item |
| Message | `AICOUNTLY Reach: Content "{#var#}" has been assigned to you.` |
| Variables | content title |

### 2. Review requested

| Trigger key | `review.requested` |
| Message | `AICOUNTLY Reach: Review requested for "{#var#}".` |
| Variables | content title |

### 3. Review due reminder

| Trigger key | `review.due` |
| Message | `AICOUNTLY Reach: "{#var#}" is due on {#var#}.` |
| Variables | content title, due date |

### 4. Review overdue

| Trigger key | `review.overdue` |
| Message | `AICOUNTLY Reach: "{#var#}" is overdue (due {#var#}).` |
| Variables | content title, due date |

### 5. Content approved

| Trigger key | `content.approved` |
| Message | `AICOUNTLY Reach: "{#var#}" has been approved.` |
| Variables | content title |

### 6. Content rejected

| Trigger key | `content.rejected` |
| Message | `AICOUNTLY Reach: "{#var#}" was rejected.` |
| Variables | content title |

### 7. Changes requested

| Trigger key | `content.changes_requested` |
| Message | `AICOUNTLY Reach: Changes requested on "{#var#}".` |
| Variables | content title |

### 8. Schedule confirmed

| Trigger key | `schedule.confirmed` |
| Message | `AICOUNTLY Reach: "{#var#}" scheduled for {#var#}.` |
| Variables | content title, schedule date |

### 9. Content refresh due

| Trigger key | `content.refresh_due` |
| Message | `AICOUNTLY Reach: Content refresh due for "{#var#}".` |
| Variables | content title |

### 10. Daily approval digest

| Trigger key | `daily_pack.approval_digest` |
| Message | `AICOUNTLY Reach: You have {#var#} item(s) awaiting review.` |
| Variables | pending count |

---

## DLT registration — copy/paste block

```
1. AICOUNTLY Reach: Content "{#var#}" has been assigned to you.
2. AICOUNTLY Reach: Review requested for "{#var#}".
3. AICOUNTLY Reach: "{#var#}" is due on {#var#}.
4. AICOUNTLY Reach: "{#var#}" is overdue (due {#var#}).
5. AICOUNTLY Reach: "{#var#}" has been approved.
6. AICOUNTLY Reach: "{#var#}" was rejected.
7. AICOUNTLY Reach: Changes requested on "{#var#}".
8. AICOUNTLY Reach: "{#var#}" scheduled for {#var#}.
9. AICOUNTLY Reach: Content refresh due for "{#var#}".
10. AICOUNTLY Reach: You have {#var#} item(s) awaiting review.
```

---

## Email templates (Console Notification Triggers)

All editorial triggers also have **email** channel templates (subject + body with `{{variable}}` placeholders). Email preview is available in Console UI when editing each trigger.

| Trigger key | Email subject (default) |
|-------------|-------------------------|
| `assignment.created` | AICOUNTLY Reach — assigned: {{content_title}} |
| `review.requested` | Review requested: {{content_title}} |
| `review.due` | Due soon: {{content_title}} |
| `review.overdue` | Overdue: {{content_title}} |
| `approval.required` | Approval required: {{content_title}} |
| `content.approved` | Approved: {{content_title}} |
| `content.rejected` | Rejected: {{content_title}} |
| `content.changes_requested` | Changes requested: {{content_title}} |
| `validation.failed` | Validation failed: {{content_title}} |
| `validation.waived` | Validation waived: {{content_title}} |
| `schedule.confirmed` | Scheduled: {{content_title}} |
| `schedule.cancelled` | Schedule cancelled: {{content_title}} |
| `content.refresh_due` | Refresh due: {{content_title}} |
| `daily_pack.generated` | Daily pack ready |
| `daily_pack.approval_digest` | AICOUNTLY Reach — {{count}} item(s) awaiting review |

Email variables: `recipientName`, `content_title`, `status`, `due_date`, `message`, `count`, `date`, `action_url`.

---

## Code mapping

| Component | Path |
|-----------|------|
| Reach → Console client | `server-php/app/Libraries/ConsoleNotificationClient.php` |
| Channel dispatch | `server-php/app/Libraries/ReachNotifier.php` |
| In-app + external dispatch | `server-php/app/Libraries/NotificationService.php` |
| Console trigger definitions | `console-react-app/.../ReachNotificationTriggers.php` |
| Console template seed | `console-react-app/.../migrations/021_reach_notification_templates.sql` |

---

## Troubleshooting

| Issue | Check |
|-------|--------|
| No SMS/email | `CONSOLE_API_TOKEN` on Reach; Console gateways configured |
| Template not sending | Status must be **Active** in Console Notification Triggers |
| No SMS | User mobile on Console identity; DLT ID set; `SMS_SKIP_SEND=false` |
| No email | Template active; user `email_enabled` preference (or critical trigger default) |
| HTTP 4xx from AOC Portal | DLT template ID mismatch or unapproved template |

---

*Last updated: Reach editorial notification integration via Console.*
