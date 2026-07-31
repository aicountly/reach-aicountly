# Security, RBAC, SSO Regression Hardening

Each brief item below was re-traced end-to-end against the actual code, not
assumed sound from prior docs.

## RBAC on routes and services

Every `community/*` route in `Config/Routes.php` carries a `permission:`
filter (`PermissionFilter` → `PermissionService::hasPermission()`), including
every mutating action (generate/approve/publish/unpublish/withdraw/restore/
moderate/deploy-retry/reconcile). Enforcement happens server-side at the
filter layer before the controller ever runs — the admin frontend hiding
buttons a role can't use is UX only, not the security boundary.

**Gap found and fixed (G20, High):** `CommunityOperationalAgentService::dispatch()`
(the Phase 3 role-routed agent runtime) had **no route, no controller, and no
permission at all** — it was fully wired but completely unreachable from
outside a unit test. Fixed by adding:

- `Permissions::COMMUNITY_AGENT_DISPATCH = 'community_agent.dispatch'`
  (`Config/Permissions.php`, plus the matching `CommunityPermission::AgentDispatch`
  enum case — these two must stay in sync, enforced by
  `CommunityPermissionTest::testEveryConfigCommunityPermissionExistsInEnum()`).
- Seeded onto the `community_automation_admin` role (`RolesAndPermissionsSeeder`
  — idempotent, re-running it in any environment applies the grant to already-seeded
  roles; see `16_FINAL_VERDICT.md` for the required ops action).
- `CommunityAgentDispatchController::dispatch()` + `POST
  /community/agents/dispatch`, gated by the new permission.
- `force_window` (the service's test/ops-only bypass of the automation
  window) is deliberately **not** accepted from the request body — the only
  way to skip the window is to call the service directly in a test, never
  over HTTP.

No action reachable through this endpoint can publish, approve, or vote —
`CommunityOperationalAgentService::ROLE_ACTIONS` never includes any of those,
which `CommunityOperationalAgentServiceTest` already asserts structurally.

## Prompt injection

`AiGenerationOrchestrator` (Phase 3 shared AI infra, reused unmodified by
Community answer generation) wires `App\Libraries\Ai\Security\PromptInjectionDetector`
into every generation call regardless of caller — Community gets this for
free because `OfficialAnswerGenerationService::executeGeneration()` calls the
same orchestrator every other AI-generation domain calls. Pre-existing
coverage: `tests/Unit/Ai/Security/PromptInjectionDetectorTest.php`. **Verified
— no Community-specific gap**; rebuilding or re-testing this shared,
already-tested component would be scope creep beyond what a Community-focused
audit should touch.

## XSS

**Gap found and fixed (G21, Critical).** See `04_GAP_REGISTER.md` and
`tests/CommunityMarkdownXssTest.php` (public repo) for the full
`javascript:`-URI stored-XSS finding in `community_markdown()`. This is the
single most severe finding of the entire audit: it was reachable by any
authenticated community member simply by posting a question or answer, no
special privilege required.

Everywhere else user/bot content reaches an HTML page it goes through
`community_escape()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`) for plain
text — verified by direct code reading of `community/question.php`'s
rendering of title/author/tag/category/identity fields, all of which route
through `community_escape()`.

## SSRF

No Community code path fetches a user- or bot-supplied URL server-side.
`source_url` on question intake (`CommunityQuestionIntakeService`) is stored
as inert metadata and never dereferenced. The only `curl_init()` in the
Community libraries is `CommunityPublicSitePublisher`'s call to
`$this->baseUrl . $path`, where `$baseUrl` comes from the trusted
`AICOUNTLY_PUBLIC_SITE_BASE_URL` environment variable and `$path` is a
hardcoded per-method string with only `rawurlencode()`'d path segments as
variable input — never a caller-supplied full URL. **Verified — no gap.**

## Rate and cost limits

Two independent layers, both already correct:

1. **AI generation cost**: `AiGenerationOrchestrator` wires
   `AiBudgetService` (a budget check) into every generation call — applies to
   Community the same way it applies to every other AI-generation domain.
2. **Bot content-creation volume**: `CommunityOperationalAgentService::DAILY_CAPS`
   (4 curated questions, 4 drafted answers, ≤5 comments per identity per
   day — the brief's seed defaults) plus the Asia/Kolkata automation window,
   both regression-tested in `CommunityOperationalAgentServiceTest`. These
   caps apply specifically to *autonomous* dispatch; a human explicitly
   clicking "Generate" in the admin UI via `POST
   community/answers/(:segment)/generate` is a deliberate, permissioned,
   individually-accountable action and is intentionally not subject to the
   bot's daily count — the brief's caps are stated as "bot comments/day" etc.,
   not a blanket human-included limit.

**Verified — no gap** beyond what Phases 2–4 already fixed (engagement
bot-filtering, publish-time risk re-check, agent daily caps).

## QAPage / structured data

`community_qa_json_ld_with_official()` (`community/question.php`) is only
constructed and only emitted on the single real question-detail page, built
from the actual post/answer rows fetched for that page — never a generic
template stamped onto non-Q&A pages. Official (Phase 5) answers correctly
render as `Organization` authors with an `aiAssisted` `additionalProperty`;
community answers render as `Person` authors. All text fields go through
`strip_tags()` before JSON encoding (also neutralizes any `</script>`
breakout attempt inside the surrounding `<script type="application/ld+json">`
tag as a side effect, since `strip_tags` removes tag-shaped substrings before
they ever reach the page). **Verified — no gap.**

## SSO `returnUrl` allowlist

`CommunityAuth::loginUrl()` → `saas_login_url()` builds the `returnUrl` from
`saas_current_return_url()`, which reads the **current request's own**
`HTTP_HOST`/`REQUEST_URI` — it is not built from any client-supplied
`returnUrl`/`redirect`/`next` query parameter, and grep confirms no Community
page ever reads such a parameter. There is therefore no attacker-controlled
input reaching the redirect target for an open-redirect allowlist to
constrain in the first place; this pattern is shared site-wide (used
identically outside Community), so it is not a Community-specific
finding to fix. **Verified — no gap.**

## Test evidence

- `tests/Unit/Community/CommunityPermissionTest.php` — updated inventory
  (23 permissions now, was 22) still green: format, uniqueness,
  config↔enum sync all still hold with the new permission added.
- Public repo: `tests/CommunityMarkdownXssTest.php` — 8 tests, all green.
- Full suites (both repos) green — see `13_TEST_AND_BUILD_EVIDENCE.md`.
