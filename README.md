# reach-aicountly

Internal Aicountly marketing operations portal for **reach.aicountly.org**.

Scope: marketing only — blog approval/publishing for aicountly.com, campaigns
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
    retried until Console has it every 5 minutes by `php spark reach:console-audit-deliver`,
    which the production deploy schedules (`server-php/bin/ops/jobs.txt`; "Production deployment (cPanel)" step 9). The command reports each delivery run to Console's Cron Job Monitor as `reach.console_audit_deliver` (`app/Libraries/CronHeartbeat.php`; `CONSOLE_CRON_MONITOR_KEY` in `.env`, console-react-app `docs/CRON_MONITORS.md`); without the key nothing is reported, and a failed report never fails the command.
    Console de-duplicates on `external_id` (`reach:audit_logs:<id>`), so a retry never
    doubles an event; failures are logged as `[ConsoleAuditClient] …` and shown on the
    Console Sync Status page.
  - **Engage** — lead push via `POST {ENGAGE_API_BASE_URL}/internal/reach/leads`
    with header `X-Portal-Token: {ENGAGE_INBOUND_TOKEN}` (matches Engage's
    `ReachController::ingest`). Duplicate detection is inferred locally. The push carries the
    seller `ref` and UTM / landing data in `reach_meta`. Engage reports lead outcomes back to
    `POST /api/v1/inbound/engage/lead-outcomes` (`X-Engage-Token: {REACH_INBOUND_ENGAGE_TOKEN}`),
    shown under Leads → Lead Outcomes and on Analytics — `docs/integrations/ENGAGE_OUTCOMES.md`.
  - **Worker** — Playwright/UI/screenshot jobs via
    `worker.apis.aicountly.com/v1/{health,screenshot,review,runs}`; graceful
    degradation when the endpoints or token are unavailable.
  - **social-api.ai** — social posts (LinkedIn, Facebook, Instagram, X) and the video pipeline's
    YouTube vlogs/Shorts (`social.socialapi.*`, `video.youtube.publisher`). Posts are `posted` only
    once social-api.ai reports them published (polled every 5 minutes) —
    [`docs/operations/SOCIAL_API.md`](docs/operations/SOCIAL_API.md). Verify with
    `php spark reach:integrations-check --only=social_api`.
    The **social inbox bot** reads comments / DMs through the same key every 10 minutes and answers
    (Gemini draft + vetting), lodges complaints in Flow, records sales leads — Social → Inbox,
    SOCIAL_API.md § "Social inbox bot".
  - **Flow** — complaints from social media become Flow tickets via
    `POST {FLOW_API_BASE_URL}/internal/reach/social-tickets` (`Authorization: Bearer {FLOW_REACH_INBOUND_KEY}`,
    the same key in Flow's `.env`; flow-react-app `docs/REACH_SOCIAL_TICKETS.md`).
  - **aicountly.com blog publish** — HTTP placeholder against
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
   For lead outcomes, set Reach's `REACH_INBOUND_ENGAGE_TOKEN` (≥32 characters) and give
   Engage's outcome sender the same value (see `docs/integrations/ENGAGE_OUTCOMES.md`).
8. On Console (`console.aicountly.org`) set `REACH_BOT_API_URL` to
   `https://reach.aicountly.org/api/v1` and `REACH_BOT_SERVICE_KEY` to the
   same value as Reach's `CONSOLE_INBOUND_TOKEN`.
9. Scheduled jobs: the **Deploy Production via SSH** workflow runs `api/bin/ops/post-deploy-jobs.sh` on the
   server (the Aicountly ops kit, identical to console-react-app `ops/estate/kit/bin/ops/`). It writes
   `CONSOLE_CRON_MONITOR_KEY` (repository secret, sent over SSH on STDIN) into `api/.env` — the only line of `.env`
   a deploy writes; Console's production refuses the shared `CONSOLE_SERVICE_KEY` for heartbeats — and installs
   `server-php/bin/ops/jobs.txt` into the deploy user's crontab (block `aicountly-reach jobs`, `flock -n`, log
   `~/logs/aicountly-reach-console-audit-deliver.log`): `php spark reach:console-audit-deliver` every 5 minutes,
   reporting itself as `reach.console_audit_deliver`. A hand-pasted `spark reach:console-audit-deliver` line is
   removed so it never runs twice; the worker / blog / community crons
   ([docs/operations/REACH_WORKER_AND_CRON.md](docs/operations/REACH_WORKER_AND_CRON.md)) stay hand-installed. No
   secret: a warning (the job runs, Console shows it OVERDUE). Crontab not writable: the deploy fails. Check on the
   server: `bash public_html/api/bin/ops/install-jobs.sh --check`.

## Non-goals

- No sales/CRM/licensing pipeline (push leads to Engage).
- No sandbox domain logic. No `my.aicountly.com` dependency.
- No portal-specific bot logic in `worker.apis.aicountly.com`.
