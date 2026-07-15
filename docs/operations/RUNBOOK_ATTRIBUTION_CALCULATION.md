# Runbook: Attribution Calculation Failure

**Phase:** 9
**Service:** `AttributionModelService`

---

## Common failures

| Error | Cause | Fix |
|-------|-------|-----|
| No touchpoints for conversion | Anonymous or expired session | Mark `identity_confidence = pseudonymous`, record with empty touchpoints warning |
| Model version not approved | Version created but not approved | Approve the model version via `attribution_model.approve` permission |
| Negative allocation weight | Rounding error in time-decay | Normalise with fallback to equal-weight |

## Verification

```sql
-- Check allocation weights sum to 1.0 per journey
SELECT journey_calculation_id, SUM(allocation_weight) AS total
FROM reach_attribution_allocation_facts
GROUP BY journey_calculation_id
HAVING ABS(SUM(allocation_weight) - 1.0) > 0.001;
```

## Recalculation

Attribution facts are immutable. To recalculate, create a new `attribution_journey_calculation` record. Do not delete or update existing facts.
