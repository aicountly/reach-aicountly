# Bot-Vote Ban, AI Disclosure, Bot Profile, Comments/Revisions

## Bot/official vote ban (Critical — Fixed, regression-tested)

`CommunityService::vote()` (`aicountly-com/includes/community/CommunityService.php`)
now looks up the voter's `community_users.role` and throws `RuntimeException`
for any role in `VOTE_FORBIDDEN_ROLES = ['bot', 'official', 'official_bot',
'service_account', 'automation']` before any vote row can be written. This is
defense-in-depth: `CommunityAuth::resolveCurrentUser()` only ever assigns
`member`/`admin` from a real SSO session today, so no live path currently
produces a forbidden role — but the guard makes the guarantee explicit and
testable rather than incidental.

Test: `aicountly-com/tests/CommunityServiceVoteGuardTest.php` — inserts users
with each forbidden role plus a `member` control into an in-memory SQLite DB
and asserts the guard rejects the former and allows the latter.

## AI disclosure (High — Fixed)

`community/question.php` disclosure precedence: identity's own `ai_disclosure`
column (seeded per-identity in migration `008`) → identity's
`disclosure_template` → canonical fallback string `AI-assisted AICOUNTLY
community contributor`. `CommunityRepository::getPublishedOfficialAnswersForPost`
now selects `ci.ai_disclosure`, `ci.operational_role`, `ci.avatar_url` so the
page has the real per-identity text instead of only a generic constant.

## Bot profile page (High — Fixed)

New `aicountly-com/community/bot.php`, routed at `/community/bot/{slug}` via
`router.php` (dev server) and `.htaccess` (Apache), rendering identity
metadata, disclosure, and lists from
`CommunityRepository::getPublishedQuestionsByIdentity` /
`getPublishedAnswersByIdentity`. Official-answer badges on `question.php` link
to this page.

## Comments / revisions (High — Schema fixed, UI pending verification)

`community_comments` and `community_answer_revisions` tables added in
migration `008` with the columns `CommunityReceiverController` writes to
(post/answer/parent relationships, author type, AI-assisted flag,
facilitation reason, revision tracking). Front-end comment thread rendering
and correction-notice display against these tables is tracked as a residual
item — see Gap Register G6 follow-up / Phase 5 pass.
