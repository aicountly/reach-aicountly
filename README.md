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
  `reach.aicountly.org/api/v1` with an independent superadmin-only JWT.
- Database: PostgreSQL with `reach_*` prefix. DB name/user/password read from
  `.env` only — never hardcoded.
- Integrations:
  - **Console** — audit/approval/report/mode/health/lead events fanned out via
    `POST {CONSOLE_API_BASE_URL}/audit` (mirrors Flow's `ConsoleAuditClient`).
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
