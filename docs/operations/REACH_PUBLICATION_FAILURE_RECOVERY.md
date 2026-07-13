# Reach Publication Failure Recovery Guide

## Overview

This guide provides step-by-step procedures for recovering from publication failures, categorised by error type.

---

## Error Categories

| Category | Retryable | Common Cause |
|----------|-----------|-------------|
| `server_error` | Yes | Public site 5xx response |
| `timeout` | Yes | Network latency / site overload |
| `network_error` | Yes | DNS failure, connection refused |
| `rate_limited` | Yes | Too many requests in short window |
| `auth_error` | No | Invalid credentials |
| `validation_error` | No | Malformed payload |
| `not_found` | No | Content does not exist on public site |
| `unknown_error` | No | Unexpected error |

---

## Recovery Procedures

### server_error (5xx)

The retry service handles this automatically. If the deployment does not recover after 5 attempts:

1. Check the public site server logs for the error.
2. Verify the public site is responding: Publishing → Connections → Check Health.
3. If the public site is down, wait for it to recover, then manually re-queue the deployment.

### timeout

1. Check network connectivity between Reach server and the public site.
2. Increase `AICOUNTLY_PUBLIC_SITE_TIMEOUT` in Reach `.env` if the site is legitimately slow (default: 15 seconds).
3. Monitor retry attempts in Publishing → Deployments.

### network_error

1. Verify `AICOUNTLY_PUBLIC_SITE_BASE_URL` is correct in Reach `.env`.
2. Check DNS resolution from the Reach server.
3. Check firewall rules between Reach server and public site.

### auth_error

1. Verify `AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN` in Reach `.env` matches `REACH_SERVICE_TOKEN` on the public site.
2. Verify `AICOUNTLY_PUBLIC_SITE_SIGNING_KEY` matches `REACH_SIGNING_KEY` on the public site.
3. Verify `AICOUNTLY_PUBLIC_SITE_KEY_ID` matches `REACH_KEY_ID` on the public site.
4. Check clock synchronisation between Reach and public site servers (timestamp tolerance is 60 seconds).
5. After fixing credentials, re-queue the deployment.

### validation_error

1. Review the content item's SEO profile and payload.
2. Check `reach_content_deployments.safe_error_message` for details.
3. Fix the content issues (missing slug, invalid structured data, etc.).
4. Re-run readiness check.
5. Re-queue the deployment.

### rate_limited

1. The retry service adds exponential backoff for rate-limited requests.
2. If persistent, check the public site's rate limit configuration.
3. Consider reducing the frequency of bulk publishing operations.

---

## Manual Re-Queue

After fixing the root cause of a non-retryable failure:

1. Navigate to Publishing → Deployments.
2. Find the failed deployment.
3. Click "Retry" (if available for the error type).
4. A new `PublicationJob` is enqueued with a new `idempotency_key`.

---

## Verification Failures

If content is published but verification fails:

1. Manually check the canonical URL in a browser.
2. If the page is live, the verification may have run too quickly. Click "Re-verify" in Publishing → Verifications.
3. If the page returns 404, the public site may have failed to apply the publication. Check public site deployment status.
4. If the robots directive is wrong, update the SEO profile and publish an update.

---

## Escalation Criteria

Escalate to engineering if:
- More than 10 deployments fail with `auth_error` simultaneously (credential rotation may be needed).
- All deployments fail with `network_error` (infrastructure issue).
- The public site returns 500 errors consistently (server-side bug).
- The nonce store is full or unavailable (database issue on public site).
