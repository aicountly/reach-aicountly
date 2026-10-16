# reach-aicountly

Internal AICOUNTLY marketing operations portal for **reach.aicountly.org**.

Scope: marketing only — blog approval/publishing for AICOUNTLY.com, campaigns
(email/WhatsApp/social/landing/paid/webinar/referral), social media planning
and queue, SEO planner, keyword ideas, creative briefs, analytics, lead
capture with push to Engage, and the Reach Marketing Bot with Auto/Confirm
modes and superadmin approval gates.

- Frontend: React 19 + Vite in [`web/`](web) with the Flow-style green/white
  compact admin UI (plain CSS variables, no Tailwind).
- Backend: CodeIgniter 4.6 API in [`server-php/`](server-php), served under
  `reach.aicountly.org/api/v1` with its own JWT, issued after Console SSO.
  Staff roles (`super_admin`, `reach_admin`, `marketing_manager`,
  `content_reviewer`) are **assigned in Console → Roles & Access** and mirrored
  at sign-in / token refresh; every route is permission-gated. See
  [`docs/architecture/REACH_RBAC.md`](docs/architecture/REACH_RBAC.md#roles-come-from-console).
  Production must set `CONSOLE_API_URL` + `CONSOLE_API_TOKEN` (`staff.roles`).
- Database: PostgreSQL with `reach_*` prefix. DB name/user/password read from
  `.env` only — never hardcoded.
- JSON columns: every model with a JSON cast extends `ReachModel` (`TolerantJsonCast`; `text[]`
  columns use `pg-text-array`). Pass arrays to those fields, never `json_encode()` output. Rows an
  older release stored double-encoded still read; run `php spark reach:repair-json` (dry run) and
  `--apply` once after deploying.
- Integrations:
  - **Console** — audit/approval/bot-report events go to Console's central audit,
    `POST {CONSOLE_API_URL}/portal/audit` with Reach's own key (`CONSOLE_API_TOKEN`,
    capability `audit.write`; console-react-app AUTH_INTEGRATION.md "Central audit").
    Each event is queued in `reach_console_sync_logs` (the outbox), sent at once, and
    retried until Console has it by cron every 5 minutes:
    `0,5,10,15,20,25,30,35,40,45,50,55 * * * * cd /path/to/api && php spark reach:console-audit-deliver >> writable/logs/console_audit.log 2>&1`. The command reports each delivery run to Console's Cron Job Monitor as `reach.console_audit_deliver` (`app/Libraries/CronHeartbeat.php`; `CONSOLE_CRON_MONITOR_KEY` in `.env`, console-react-app `docs/CRON_MONITORS.md`); without the key nothing is reported, and a failed report never fails the command.
    Console de-duplicates on `external_id` (`reach:audit_logs:<id>`), so a retry never
    doubles an event; failures are logged as `[ConsoleAuditClient] …` and shown on the
    Console Sync Status page.
  - **Engage** — lead push via `POST {ENGAGE_API_BASE_URL}/internal/reach/leads`
    with header `X-Portal-Token: {ENGAGE_INBOUND_TOKEN}` (matches Engage's
    `ReachController::ingest`). Duplicate detection is inferred locally.
  - **Worker** — Playwright/UI/screenshot jobs via
    `worker.apis.aicountly.com/v1/{health,screenshot,review,runs}`; graceful
    degradation when the endpoints or token are unavailable.
  - **AICOUNTLY.com blog publish** — HTTP placeholder against
    `{AICOUNTLY_SITE_API_BASE_URL}/blog/posts`. If either env is empty the
    post stays `approved` with `publishing_status = pending_publishing`.

## Local development

### Backend

```bash
cd server-php
composer install
cp .env.example .env
# fill DB_*, JWT_SECRET, SUPER_ADMIN_*, CONSOLE_*, ENGAGE_*, WORKER_*, AICOUNTLY_SITE_*
# .env.example carries only what must be set; every optional override and its
# built-in default is in docs/operations/REACH_ENV_REFERENCE.md
php spark migrate
php spark db:seed InitialReachSeeder
php spark serve --port=8080
```

### Frontend

```bash
cd web
npm install
cp .env.example .env
# VITE_API_URL=http://localhost:8080
npm run dev
```

## Production deployment (cPanel)

1. Create the PostgreSQL database (default name `aicountly_reach`) and note
   the user + password.
2. Frontend: `cd web && npm ci && npm run build`, then rsync
   `web/dist/` into `public_html/` on the `reach.aicountly.org` subdomain.
3. Backend: rsync `server-php/` into `public_html/api/`. Copy
   `server-php/.env.example` to `public_html/api/.env` (never commit or push
   the live `.env`) and fill in real values.
4. SSH into the account and run:

   ```bash
   cd public_html/api
   composer install --no-dev --optimize-autoloader
   php spark migrate
   php spark db:seed InitialReachSeeder
   ```

5. Verify with:

   ```bash
   curl -s https://reach.aicountly.org/api/health | head -c 200
   ```

6. Ensure the `.htaccess` files are present:
   - `public_html/.htaccess` — SPA fallback (skip `/api/*`).
   - `public_html/api/.htaccess` — CI front controller + `Authorization` header
     passthrough.
7. On Engage (`engage.aicountly.org`) set `REACH_INBOUND_TOKEN` to the same
   value as Reach's `ENGAGE_INBOUND_TOKEN`.
8. On Console (`console.aicountly.org`) set `REACH_BOT_API_URL` to
   `https://reach.aicountly.org/api/v1` and `REACH_BOT_SERVICE_KEY` to the
   same value as Reach's `CONSOLE_INBOUND_TOKEN`.

## Non-goals

- No sales/CRM/licensing pipeline (push leads to Engage).
- No sandbox domain logic. No `my.aicountly.com` dependency.
- No portal-specific bot logic in `worker.apis.aicountly.com`.
