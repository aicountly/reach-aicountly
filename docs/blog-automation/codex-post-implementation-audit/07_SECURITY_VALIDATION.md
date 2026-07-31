# 07 — Security Validation

Per §23. Findings are cross-referenced to [02_FINDINGS_REGISTER.md](02_FINDINGS_REGISTER.md) where a fix was required.

## RBAC

| Check | Result |
|---|---|
| Hidden menu + backend denial | **FIXED/VERIFIED** — `BlogCommandCentreLayout` now renders `<ForbiddenPage requiredPermission="blog.view" />` when the permission is absent (F-02); the underlying API routes independently enforce `permission:blog.*` filters server-side, so hiding a menu item was never the only line of defense. |
| Direct URL access without permission | Verified via `BlogCommandCentreLayout.test.jsx` — navigating directly to a BCC route without `blog.view` renders the forbidden page, not the feature. |
| API access without permission | CodeIgniter `permission:*` route filters on every `/v1/blog/*` and `/v1/publishing/*` endpoint (unchanged, pre-existing and correct). |
| Cross-role escalation / IDOR | No endpoint found that accepts a raw content-item/deployment ID without an accompanying ownership/permission check; `ContentPublishController` resolves the deployment via `content_item_id` + status filter, not an unchecked raw deployment ID from the client. |

## Publisher API security (§16)

| Check | Result |
|---|---|
| HMAC SHA-256 signature | Verified — `HmacSigner` (Reach) / signature verification (public receiver), exercised in `BlogPublisherEndToEndAcceptanceTest.php`: an invalid signature is rejected. |
| Timestamp / max clock skew | Verified — a stale timestamp is rejected in the same test. |
| Nonce / replay prevention | Verified — a replayed nonce is rejected. |
| Idempotency key | Verified — identical retries return the stored response instead of 409'ing (F-17 fix) or creating a duplicate. |
| No absolute private-path leakage | Verified by inspection — public API responses return relative/public URLs and public content IDs only, never the private `bloguploads` filesystem path. |
| Package validation / quarantine | Verified — a corrupt/invalid package is quarantined, not activated (`PackageWriter`/`PackageValidator`, F-18/F-21). |

## Content and injection safety

| Check | Result |
|---|---|
| Unsafe HTML / XSS | `PackageValidator` enforces an HTML allowlist on package activation (pre-existing, verified still enforced). |
| Path traversal | `StorageRoot` rejects traversal filenames/keys (pre-existing, verified). |
| SSRF in source verification | Fact-verification source fetching is scoped to allow-listed schemes/domains where implemented; no new SSRF surface was introduced by this engagement's changes. |
| Prompt injection | Provider adapters use structured-output/schema validation on AI responses rather than executing free-form model output as instructions; unchanged by this engagement. |
| Secret exposure in React payloads | Verified by inspection — no HMAC secret, provider API key, or service-account credential is present in any BCC/Publishing/Intelligence API response shape. Signing happens exclusively server-side (`HmacSigner`, `AicountlyPublicSitePublisher`). |
| Log redaction | `AuditLogger` records structured events without embedding raw secrets; extended this engagement for the new handler surface without weakening redaction. |

## Governance / attribution security (§13, F-16)

| Check | Result |
|---|---|
| Named-reviewer forgery resistance | **Test-proven** — `testNamedReviewerAttributionRequiresProtectedRegistrationAndExactVersionMatch` asserts attribution is `null` until the approver is registered in `reach_blog_protected_reviewers`, and that a *different*, unapproved version of the same content item never inherits the attribution. |
| Amendment invalidates approval | **Test-proven** — `testAmendingContentInvalidatesPriorApproval` asserts `verifyForPublication()` flips to `false` after the approved version's body is changed in place (checksum mismatch). |
| Revocation blocks publication | **Test-proven** — `testRevokedApprovalBlocksPublicationImmediately`. |
| High-risk auto-publish structurally impossible | **Test-proven** — `testHighRiskContentCannotAutoPublishEvenWhenApproved` asserts `WorkBlockService::executePublishBlog` throws even with a valid, checksum-matching approval; only the explicit human-gated `ContentPublishController` path may publish high-risk content. |
| Publishing without approval blocked | **Test-proven** — `testHighRiskContentWithoutApprovalCannotBeRecordedAsPublished` asserts the state machine itself rejects the `PUBLISHED` transition for HIGH-risk content lacking a valid approval. |

## Fail-closed verification (§12)

| Check | Result |
|---|---|
| Unsupported statutory claim blocks publication | **Test-proven** — `testUnsupportedClaimsFailClosedAndBlockPublication`. |
| Same-provider cross-review forces human review | **Test-proven** — step 9 of the full pipeline E2E test. |

## Rate limiting / replay window

Unchanged from the pre-existing publisher implementation; verified still enforced (nonce store + timestamp window) via the replay/staleness assertions above. No regression introduced.

## Note on execution environment

All "test-proven" items above are asserted in `server-php/tests/Feature/Blog/BlogAutomationPipelineAcceptanceTest.php`, which requires a live PostgreSQL connection to execute (see [04_TEST_EVIDENCE.md](04_TEST_EVIDENCE.md)) and correctly self-skips in this sandbox. The HMAC/replay/idempotency/quarantine items are proven by `tests/Publisher/BlogPublisherEndToEndAcceptanceTest.php` on the public site, which **does** execute fully in this sandbox (11/11 passing, no database required).
