# Runbook: Content Refresh Detection Job

**Job:** `ContentRefreshDetectionJob`  
**Frequency:** Daily 03:00  
**Phase:** 9

---

## Purpose

Detects published content items older than 90 days and transitions them to `refresh_due`. Also evaluates active refresh policies and generates evidence-based refresh recommendations via `RefreshRecommendationService`.

## On failure

1. Check job worker health: `GET /api/reach/v1/health`
2. Check `reach_jobs` for error: `SELECT * FROM reach_jobs WHERE job_type = 'content_refresh_detection' ORDER BY id DESC LIMIT 5`
3. Check application logs: `writable/logs/log-*.log | grep ContentRefreshDetection`
4. Retry manually: create a new job via admin panel or `spark jobs:dispatch content_refresh_detection`

## Manual trigger

```bash
php spark jobs:dispatch content_refresh_detection '{"tenant_id": 1}'
```

## Expected output

```json
{"ok": true, "refresh_due": N, "recommendations_generated": M}
```
