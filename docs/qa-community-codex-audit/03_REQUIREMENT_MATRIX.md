# Requirement Matrix

Every area named in the remediation plan (`community_qa_audit_fix_*.plan.md`),
mapped to the evidence that it was actually inspected/reproduced/fixed/tested
— not just reported.

| Requirement area | Evidence | Status |
|---|---|---|
| Wire `CommunityReceiverController` as the live public receiver (1A) | `05_PUBLIC_RECEIVER_CUTOVER.md`, G1/G2 | Fixed |
| Corrective public schema migration for receiver-referenced columns | `database/migrations/008_community_receiver_schema.pgsql.sql`, G2 | Fixed |
| Legacy `community.php` shim/retirement | `05_PUBLIC_RECEIVER_CUTOVER.md` | Fixed |
| Auth/guardEngagement alignment on live path | `06_SECURITY_HMAC_AUTH.md` | Fixed |
| `CommunityPublisherInterface` full-contract coverage (identities/comments/corrections/moderation/reconcile) | G8, `CommunityPublisherInterface`/both implementations | Fixed |
| Public bot-vote ban | G3, `CommunityService::vote()` + `CommunityServiceVoteGuardTest` | Fixed (regression-tested) |
| Canonical AI disclosure across questions/answers/comments/bot profile | G5, `07_BOT_VOTE_AI_DISCLOSURE.md` | Fixed |
| Bot profile / contribution history page | G6, `community/bot.php` | Fixed |
| Publisher PHPUnit suite in default CI | G7, `composer.json` `test` script | Fixed |
| Engagement ingestion/analytics schema alignment | G9, `08_ENGAGEMENT_SCHEMA.md` | Fixed |
| Publish-time risk re-check | G10, `09_PUBLISH_RISK_GATE.md` | Fixed (regression-tested) |
| Reconciliation service + job + endpoint | G11, `10_RECONCILIATION.md` | Fixed (regression-tested) |
| Content Studio dead-domain confirmation | G12 | Verified — no gap |
| Menu/frontend dead-button/mock-data check | G13 | Verified — no gap |
| Role-routed operational agent runtime (2A), five roles | G17, `11_AGENT_RUNTIME.md` | Fixed (regression-tested) |
| Thread Facilitator limits (self-reply/depth/cooldown) | `11_AGENT_RUNTIME.md` §`thread_facilitator` | Fixed (regression-tested) |
| Community Steward: no engagement-inflating actions | `CommunityStewardServiceGuardrailTest` (reflection-based) | Fixed (regression-tested) |
| Asia/Kolkata 09:00–19:00 automation window | `CommunityAutomationWindow` + test | Fixed (regression-tested) |
| Daily caps (4Q/4A/≤5 comments) | `CommunityOperationalAgentService::DAILY_CAPS` + test | Fixed (regression-tested) |
| Spark commands / schedule / queue safety | G15, G18, G19, `12_AUTOMATION_SCHEDULE_QUEUE.md` | Fixed (regression-tested) |
| Agent runtime HTTP reachability + RBAC | G20, `15_RBAC_SSO_SECURITY.md` | Fixed |
| RBAC route-level re-check | `15_RBAC_SSO_SECURITY.md` | Verified — no gap (G20 excepted, fixed) |
| Prompt-injection regression | `15_RBAC_SSO_SECURITY.md` | Verified — no gap (shared, pre-tested infra) |
| XSS regression | G21, `15_RBAC_SSO_SECURITY.md` | Fixed (regression-tested) — **Critical finding** |
| SSRF allowlist regression | `15_RBAC_SSO_SECURITY.md` | Verified — no gap |
| Rate & cost limit regression | `15_RBAC_SSO_SECURITY.md` | Verified — no gap |
| QAPage scoped to real Q&A pages + disclosure in JSON-LD | `14_SEO_STRUCTURED_DATA.md` | Verified — no gap |
| Analytics from real columns only | G9 (same fix), `08_ENGAGEMENT_SCHEMA.md` | Fixed |
| SSO `returnUrl` allowlist | `15_RBAC_SSO_SECURITY.md` | Verified — no gap |
| Reach `composer test` + Vitest green | `13_TEST_AND_BUILD_EVIDENCE.md` | Pass |
| Public `composer test` (both suites) green | `13_TEST_AND_BUILD_EVIDENCE.md` | Pass |
| `git diff --check` both repos | `13_TEST_AND_BUILD_EVIDENCE.md` | Pass (clean) |
| Full docs 00–16 + final verdict | This document set | Complete |

## Explicitly out of scope (per plan, not evaluated as gaps)

- Production deploy, secret creation, live cron install — see
  `16_FINAL_VERDICT.md` for the exact list of required ops actions.
- Committing/pushing changes.
- Renaming HMAC headers globally.
- Dropping `reach_content_community_details` with destructive data loss.
- Fabricating "approved external source" candidate-selection logic for
  `question_curator`'s daily-plan dispatch — this is a product/ops
  configuration decision, not a code defect (see `11_AGENT_RUNTIME.md`
  residual items and `12_AUTOMATION_SCHEDULE_QUEUE.md`'s explanation of why
  dispatch itself is intentionally excluded from the cron schedule).
