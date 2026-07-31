# Community Q&A Independent Audit — Index

Independent post-Opus audit and remediation of the Community Q&A platform across
`reach-aicountly` (internal, this repo) and `aicountly-com` (public). Mirror of
public-owned evidence lives in `aicountly-com/docs/qa-community-codex-audit/`.

| # | Document | Scope |
|---|----------|-------|
| 00 | `00_INDEX.md` | This file |
| 01 | `01_BASELINE.md` | Repo/branch/HEAD baselines, pre-existing unrelated dirty state |
| 02 | `02_ARCHITECTURE_AS_BUILT.md` | As-built architecture vs. claimed architecture |
| 03 | `03_REQUIREMENT_MATRIX.md` | Every requirement in the brief mapped to evidence |
| 04 | `04_GAP_REGISTER.md` | Every confirmed gap, severity, status, fix commit/file |
| 05 | `05_PUBLIC_RECEIVER_CUTOVER.md` | Dual-stack receiver finding and Decision 1A cutover |
| 06 | `06_SECURITY_HMAC_AUTH.md` | HMAC auth, idempotency, nonce/replay evidence |
| 07 | `07_BOT_VOTE_AI_DISCLOSURE.md` | Bot-vote ban, AI disclosure, bot profile, comments/revisions |
| 08 | `08_ENGAGEMENT_SCHEMA.md` | Engagement ingestion/analytics schema fix |
| 09 | `09_PUBLISH_RISK_GATE.md` | Publish-time risk re-check hardening |
| 10 | `10_RECONCILIATION.md` | Reach↔Public reconciliation service |
| 11 | `11_AGENT_RUNTIME.md` | Role-routed operational agent runtime (Decision 2A) |
| 12 | `12_AUTOMATION_SCHEDULE_QUEUE.md` | Spark commands, schedule window, queue safety |
| 13 | `13_TEST_AND_BUILD_EVIDENCE.md` | Exact commands/results, both repos |
| 14 | `14_SEO_STRUCTURED_DATA.md` | QAPage JSON-LD correctness |
| 15 | `15_RBAC_SSO_SECURITY.md` | RBAC/SSO regression hardening |
| 16 | `16_FINAL_VERDICT.md` | Final verdict and required external deployment actions |

Working method: audit → reproduce → fix → test → re-audit, looped until no
material Critical/High gap remains. This index and all stubs are created in
Phase 0; each is filled in as its phase completes and finalized in Phase 7.
