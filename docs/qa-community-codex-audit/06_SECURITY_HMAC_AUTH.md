# HMAC Auth, Idempotency, Nonce/Replay

## Header contract (preserved, not renamed)

Per the locked decision, the existing `X-Reach-*` convention was kept as-is
— no `X-AICOUNTLY-*` rename. `CommunityReceiverAuth` (public repo) extends
`PublisherAuth` verbatim rather than reimplementing verification, so there is
exactly one HMAC implementation on the public site shared by the blog
publisher and the Community receiver. Reach's `HmacSigner`
(`server-php/app/Libraries/Publishing/Connector/HmacSigner.php`) sends:

| Header | Purpose |
|--------|---------|
| `X-Reach-Key-Id` | Which signing key was used |
| `X-Reach-Timestamp` | Unix timestamp, checked against `REACH_TIMESTAMP_TOLERANCE` |
| `X-Reach-Nonce` | Single-use value, rejected on reuse (`NonceStore::consume()`) |
| `X-Reach-Content-SHA256` | Body hash, binds the signature to the exact payload sent |
| `X-Reach-Signature` | HMAC over method+path+timestamp+nonce+body-hash |
| `X-Request-ID` | Correlation ID, logged both sides |
| `X-Idempotency-Key` | Dedup key — see below |
| `X-Reach-API-Version` | Version negotiation |

## Idempotency

Two independent layers enforce "a retried request cannot produce a second
side effect":

1. **Public receiver**: `X-Idempotency-Key` (or an envelope-level
   `idempotency_key`) is checked against prior requests before performing a
   mutation — a repeat is answered with the original result, not re-executed
   (`CommunityReceiverController`; see `05_PUBLIC_RECEIVER_CUTOVER.md` for the
   cutover that made this the *live* enforcement path, not a dead parallel
   one).
2. **Reach's own queue**: `reach_jobs.idempotency_key` has a partial unique
   index (`WHERE idempotency_key IS NOT NULL`); `JobService::enqueue()`
   catches the resulting constraint violation and returns the existing job's
   ID rather than inserting a duplicate row. This is what makes the new
   `CommunitySchedule` tick-bucketed enqueues and the new
   `CommunityDeploymentRetrySweepJob`'s per-attempt keys safe against
   overlapping cron runs — see `12_AUTOMATION_SCHEDULE_QUEUE.md`.
3. **Deployment records**: `reach_community_deployments.idempotency_key`
   (`UUID NOT NULL UNIQUE`) — a retried deployment reuses the *same*
   idempotency key so a retry can never become a second publication.

## Nonce / replay

`NonceStore::consume($nonce, $timestamp)` returns `true` exactly once per
nonce; a second call with the same nonce returns `false`, and
`PublisherAuth` (parent of `CommunityReceiverAuth`) treats that as an
authentication failure. Regression test:
`tests/Publisher/PublisherAuthReplayTest::testNonceCannotBeReused()` —
asserts the first `consume()` call succeeds and the second, identical call
fails.

## Test evidence (current run, public repo)

```
composer test:publisher
# PHPUnit 10.5.64 — 62 tests, 130 assertions — OK
composer test:publicsite
# PHPUnit 10.5.64 — 167 tests, 215 assertions — OK (8 skipped: DB-dependent)
```

Both suites are part of the default `composer test` script (fixing the
previously-excluded-from-CI gap recorded as G7 in `04_GAP_REGISTER.md`), so
this evidence reflects what CI actually runs, not a hand-picked subset.

## Residual items

None found. HMAC/nonce/idempotency is shared, pre-existing infrastructure
reused correctly by the Community receiver rather than reimplemented; the
only Community-specific security defect found in this entire audit was the
`javascript:`-URI XSS in `community_markdown()` (G21, see
`15_RBAC_SSO_SECURITY.md`), which is unrelated to the HMAC layer.
