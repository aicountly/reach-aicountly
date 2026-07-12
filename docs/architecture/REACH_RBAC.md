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
