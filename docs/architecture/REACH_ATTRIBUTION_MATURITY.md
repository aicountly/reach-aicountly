# Reach Attribution Maturity

**Phase:** 9
**Extends:** Phase 8 first/last-touch attribution foundations

---

## Attribution Models Supported

Phase 9 implements three transparent, deterministic attribution models:

### 1. Equal Weight
Every touchpoint in the journey receives equal credit.

**Formula:** `allocation = 1 / total_touchpoints`

**Use case:** No prior knowledge about which touchpoints matter more.

**Limitation:** Treats a 15-second blog visit identically to a demo request.

### 2. Position Based
First and last touchpoints receive elevated credit; middle touchpoints share the remainder.

**Formula (default):** First = 40%, Last = 40%, Middle = 20% shared equally

**Use case:** Emphasise discovery and decision touchpoints.

**Limitation:** Middle touchpoints may be disproportionately underweighted for short journeys.

### 3. Time Decay
More recent touchpoints receive greater credit, decaying exponentially backwards in time.

**Formula:** `weight_i = e^(-λ * days_before_conversion)` then normalise to sum 1.0

**Default λ:** 0.1 (7-day half-life)

**Use case:** Emphasise touchpoints closest to conversion.

**Limitation:** May undervalue brand-awareness content that appeared early in long journeys.

---

## Mandatory Disclosures

Every attribution result must include:

```
Model: [model_name] v[version]
Formula: [formula text]
Lookback window: [N] days
Eligible touchpoints: [count]
Excluded touchpoints: [count] (e.g. anonymous sessions)
Identity confidence: [high/medium/low/pseudonymous]
Completeness: [score]
Limitation: [required disclosure text]
```

Attribution results represent a modelled allocation, not factual causation.

---

## Journey Views

Phase 9 adds:

1. **Ordered touchpoint journey** — all touchpoints in chronological order per conversion
2. **Assisted touchpoint view** — content that appeared in a journey but was not first or last touch
3. **Content-assisted conversions** — aggregated count of conversions where a content item appeared in the journey
4. **Campaign-assisted conversions** — same aggregated view per campaign
5. **Channel-assisted conversions** — same per channel

---

## Data Storage

| Table | Purpose |
|-------|---------|
| `reach_attribution_models` | Model definitions with formulas and limitations |
| `reach_attribution_model_versions` | Versioned weight rules |
| `reach_attribution_journey_calculations` | Per-conversion journey computation record |
| `reach_attribution_allocation_facts` | Per-touchpoint allocation weights (immutable) |

---

## Corrections

Manual corrections require:
- `attribution.correct` permission
- Actor, reason, and timestamp recorded in audit
- Original allocation facts retained (never overwritten)
- Correction creates new calculation version

---

## What Attribution Is Not

Attribution results in this system are **not**:
- Proof of causation
- Revenue attribution
- ROI calculation
- Marketing mix modelling
- Incrementality measurement

These require separately approved experimental designs (randomised controlled trials, holdout groups) which are out of scope for Phase 9.
