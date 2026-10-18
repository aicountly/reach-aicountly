# REACH RBAC — Phase 0

_Last updated: Phase 0 (branch `feature/phase-0-foundation`, baseline `1766ec2`)._

Reach uses granular, permission-based authorization rather than the legacy
blanket `super_admin` filter. Every authenticated route asserts one or more
permission slugs via the CI4 `permission:...` filter (`App\Filters\PermissionFilter`).

## Actor model

Every business row that can be created by a non-human is stamped with:

| Column                | Values                                    |
|-----------------------|-------------------------------------------|
| `created_actor_type`  | `human` \| `system` \| `bot` \| `service` |
| `created_by_service`  | e.g. `reach:worker`, `reach:cron`         |
| `generation_job_id`   | FK to `reach_jobs.id`, if job-driven      |

The seeder inserts a canonical **`system-bot`** user in `reach_users` with
`is_login_disabled = true` for FK compatibility. That user MUST NOT be
issued a JWT.

## Permission list

Slugs live in `App\Config\Permissions`. They are grouped for UI rendering
and CHECK constraints.

| Group          | Slugs                                                                                              |
|----------------|----------------------------------------------------------------------------------------------------|
| dashboard      | `dashboard.view`                                                                                   |
| blog           | `blog.view` `blog.manage` `blog.approve` `blog.publish`                                            |
| campaign       | `campaign.view` `campaign.manage` `campaign.approve` `campaign.dispatch`                           |
| social         | `social.view` `social.manage` `social.approve`                                                     |
| email          | `email.view` `email.manage` `email.approve`                                                        |
| whatsapp       | `whatsapp.view` `whatsapp.manage` `whatsapp.approve`                                               |
| lead           | `lead.view` `lead.manage`                                                                          |
| approval       | `approval.view` `approval.decide` `approval.override`                                              |
| bot            | `bot.view` `bot.dispatch` `bot.configure`                                                          |
| job            | `job.view` `job.retry` `job.cancel`                                                                |
| settings       | `settings.view` `settings.manage`                                                                  |
| integration    | `integration.view` `integration.manage`                                                            |
| audit          | `audit.view`                                                                                       |
| analytics      | `analytics.view`                                                                                   |

A wildcard `*` grants **all** permissions; `blog.*` grants every slug in
that group. The wildcard is reserved for `super_admin`.

## Roles (seeded)

| Role slug             | Permissions summary                                                     |
|-----------------------|-------------------------------------------------------------------------|
| `super_admin`         | `["*"]`                                                                 |
| `reach_admin`         | Everything except `super_admin`-only ops (destructive tenant actions).  |
| `marketing_manager`   | View + manage + dispatch across content/campaign/social/email/whatsapp. |
| `content_reviewer`    | View + approval.decide across content channels; no manage/dispatch.     |
| `analyst`             | Read-only across dashboard/analytics/lead/audit.                        |
| `viewer`              | Dashboard + analytics only.                                             |

Per-user grants/denials live in `reach_user_permissions`
(`user_id, permission, mode ∈ (grant, deny)`). Overrides are merged with the
role permissions in `PermissionService::resolveEffective(int $userId)`; denies
win.

## Roles come from Console

_Added 2026-10 (branch `claude/optimistic-knuth-u20tyq`)._ Reach no longer
decides who is a staff member or which role they hold. Console
(`console.aicountly.org` → **Roles & Access**, app_code `reach`) assigns one or
more of `super_admin`, `reach_admin`, `marketing_manager`, `content_reviewer`;
Reach mirrors it into `reach_users.role_id`. Reach is single-role, so the
highest ranked wins: **super_admin > reach_admin > marketing_manager >
content_reviewer**. Per-user grants/denies in `reach_user_permissions` are
portal-local and are never touched by a sync. There is no role editing in
Reach; the Settings page and the sidebar role label say "Roles are assigned in
Console → Roles & Access".

The old rule that only `super_admin` may sign in is gone — every role signs in
and sees what its permissions allow (the routes and the sidebar were already
permission-driven).

**Sign-in** (`POST v1/auth/controller-sso`, `GET v1/auth/sso-callback`,
`POST v1/auth/console-session`) reads the Console SSO exchange / introspect payload:

| Payload | Result |
|---|---|
| `app_roles` non-empty, or `app_superadmin: true` | `role_id` set to the highest ranked role (unknown slugs ignored; app superadmin ⇒ `super_admin`); `console_user_id`, `roles_synced_at`, `authz_version` stored |
| `app_roles: []` and not `app_superadmin` | **403** "Access not assigned. Ask a Console superadmin to assign you a role." — no local user is provisioned |
| no `app_roles` key (older Console) | existing users keep their local role; a new user is provisioned `super_admin` only when Console says `global_superadmin`, otherwise refused (as before). Never an automatic `super_admin` for anyone else |

**Token refresh** (`POST v1/auth/refresh {token}`; the SPA calls it on load
when its stored token is more than 10 minutes old — each refresh adds a session row). The presented token must still have a live
`reach_sessions` row. When `CONSOLE_API_URL` and `CONSOLE_API_TOKEN` are set,
Reach calls `GET {CONSOLE_API_URL}/portal/controller-apps/user-access?app_code=reach&console_user_id=…`
(`&email=…` before the first sync) with `Authorization: Bearer {CONSOLE_API_TOKEN}`:

| Console answer | Result |
|---|---|
| not `exists` / not `active` / no `app_access` / no roles | **403** and every `reach_sessions` row of the user is revoked (JwtFilter rejects them at once) |
| access with roles | role re-synced, new token minted with it |
| unreachable / error | allowed only when `roles_synced_at` is under 12 h old, otherwise **503** (sessions are not revoked) |
| env not set | warning logged, token re-issued with the local role (previous behaviour) — **production must set both** (`GET v1/admin/api-health` → `checks.console_role_sync_configured`) |

Code: `App\Libraries\ConsoleRoleSync` (pure rules), `App\Services\StaffAccessService`
(DB), `ConsoleIdentityService::fetchUserAccess()`. Migration
`2026-10-14-100001_AddConsoleRoleSyncToReachUsers` adds the three
`reach_users` columns and inserts any of the four Console-assignable roles that
are missing (canonical permissions from `RolesAndPermissionsSeeder`; existing
rows untouched). Tests: `tests/Unit/Auth/ConsoleRoleSyncTest.php`,
`tests/Feature/Auth/ConsoleRoleSyncAuthTest.php`.

## Resolution flow

```
HTTP request
  → RequestIdFilter (stamps X-Request-Id)
  → CorsFilter
  → JsonBodySizeFilter
  → JwtFilter          (populates $request->reachUser)
  → PermissionFilter   (permission:blog.approve[,other])
  → Controller         (BaseApiController::user() / userId())
```

## Route → permission matrix (excerpt)

| Method | Path                              | Permission(s)                            |
|--------|-----------------------------------|------------------------------------------|
| GET    | v1/dashboard/*                    | `dashboard.view`                         |
| GET    | v1/blog/posts                     | `blog.view`                              |
| POST   | v1/blog/posts                     | `blog.manage`                            |
| POST   | v1/blog/posts/{id}/approve        | `blog.approve` + `throttle:approval`     |
| POST   | v1/blog/posts/{id}/publish        | `blog.publish`                           |
| POST   | v1/campaigns                      | `campaign.manage`                        |
| POST   | v1/campaigns/{id}/status          | `campaign.dispatch`                      |
| POST   | v1/approvals/{id}/decide          | `approval.decide` + `throttle:approval`  |
| POST   | v1/bot/dispatch                   | `bot.dispatch` + `throttle:bot_dispatch` |
| GET    | v1/jobs                           | `job.view`                               |
| POST   | v1/jobs/{id}/retry                | `job.retry` + `throttle:integration`     |
| POST   | v1/jobs/{id}/cancel               | `job.cancel` + `throttle:integration`    |
| POST   | v1/engage-push/{id}               | `lead.manage` + `throttle:integration`   |
| POST   | v1/settings                       | `settings.manage`                        |
| POST   | v1/settings/automation/live-check | `settings.manage` + `throttle:integration` |
| POST   | v1/bot-settings                   | `bot.configure`                          |

The full table is authoritative in `app/Config/Routes.php`.

## Frontend wiring

- `web/src/context/AuthContext.jsx` exposes `permissions: Set<string>` and
  `hasPermission(slug)` derived from `/me` on login and refresh.
- `web/src/hooks/usePermission.js` returns `{ has, hasAny, hasAll }`.
- `web/src/components/auth/RequirePermission.jsx` renders children only when
  the current user has the required slug (fallback prop supported).
- `web/src/auth/ProtectedRoute.jsx` guards routes and redirects to
  `ForbiddenPage.jsx` when the JWT is valid but the permission is missing.
- Sidebar entries declare a `requires` slug and self-hide when the user lacks
  it (`Sidebar.jsx`). Whole sections disappear when every child is hidden.
- Action buttons (approve, reject, publish, retry, cancel) are wrapped in
  `has(slug)` checks so unauthorised users see no misleading UI.

## Approval policy

`App\Libraries\ApprovalPolicy::canApprove(...)` centralises the following
rules; both `ApprovalController::decide` and `BlogController::approve` call it
before approving:

1. Bot-created content: any user with `approval.decide` may approve.
2. Manual content authored by the current user: requires **override** flag
   AND `approval.override` permission AND a non-empty `reason`.
3. Bulk self-approval attempts are rejected.
4. Overrides are recorded in `reach_audit_logs` as `approval.overridden`
   with the `reason` column populated.

## Extending

- Add a new permission: extend `Permissions.php`, seed it onto the desired
  roles, add it to CHECK docs, wire it into `Routes.php`, and expose it on
  the frontend (`Sidebar` `requires`, `RequirePermission`, etc.).
- Add a new role: extend `RolesAndPermissionsSeeder`. Existing users keep
  their role until an admin re-assigns them.
- Add per-user overrides: insert into `reach_user_permissions`. `PermissionService`
  cache is per-request; the next request sees the update.
