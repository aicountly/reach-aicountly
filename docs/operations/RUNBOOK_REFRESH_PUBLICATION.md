# Runbook: Refresh Publication Failure

**Trigger:** `reach_refresh_publication_links.delivery_status = failed`  
**Phase:** 9

---

## Purpose

Handles failed refresh publication attempts. Publications use idempotency keys to prevent duplicates.

## Investigation steps

1. Find failing links: `SELECT * FROM reach_refresh_publication_links WHERE delivery_status = 'failed' ORDER BY updated_at DESC LIMIT 10`
2. Check idempotency key uniqueness
3. Check aicountly-com receiver health: `GET https://aicountly.com/api/reach/v1/health`
4. Check HMAC signing key validity (env var `REACH_HMAC_SECRET`)
5. Review `reach_audit_logs` for `refresh.publication.failed` events

## Retry

Update `delivery_status` to `queued` and `retry_count` to 0 to trigger requeue:
```sql
UPDATE reach_refresh_publication_links SET delivery_status = 'queued', retry_count = 0 WHERE id = :id;
```

## Escalation

If aicountly-com receiver is unreachable, escalate to infrastructure team.
