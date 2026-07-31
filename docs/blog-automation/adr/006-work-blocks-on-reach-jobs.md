# ADR-006: Work blocks layered on existing reach_jobs

## Status

Accepted

## Context

Reach already has `reach_jobs` with lease, SKIP LOCKED, priority, backoff, and dead-letter handling via `JobService` and `reach:work`. Publication enqueue was broken (wrong column names, unregistered handler).

## Decision

1. Repair publication enqueue to use `JobService::enqueue()` with `job_type` / `payload_json`.
2. Register `PublicationJob` (and retry) in `JobHandlerRegistry`.
3. Introduce `reach_work_blocks` as the planning/eligibility layer. Cron (`reach:blog-dispatch`) selects eligible work blocks inside the Asia/Kolkata automation window and enqueues bounded batches onto `reach_jobs`.
4. Do **not** create a second competing queue.

## Consequences

- Job Monitor continues to observe blog jobs via filters.
- Work-block types for deferred AI/roadmap phases are registered as constants early; handlers land when adapters exist.
