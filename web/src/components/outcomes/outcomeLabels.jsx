// Row labels for OutcomeBreakdownTable (kept apart so the component file exports only components).

export function campaignLabel(r) {
  if (r.campaign_id == null) return <span className="text-muted">No campaign</span>;
  return r.campaign_name ? `${r.campaign_name} (#${r.campaign_id})` : `Campaign #${r.campaign_id}`;
}

export function sourceLabel(r) {
  return r.source === 'unattributed' ? <span className="text-muted">Unattributed</span> : r.source;
}

export function contentLabel(r) {
  return (
    <span style={{ overflowWrap: 'anywhere' }}>
      {r.content_type ? `${r.content_type.replace(/_/g, ' ')} · ` : ''}{r.canonical_url || `#${r.content_identity_id}`}
    </span>
  );
}

export function sellerLabel(r) {
  return r.seller_code;
}

// Drill targets on Leads → Outcomes for each breakdown row (null = nothing to filter on).
export const campaignFilter = (r) => (r.campaign_id == null ? null : { campaign_id: r.campaign_id });
export const sourceFilter = (r) => ({ source: r.source });
export const contentFilter = (r) => ({ content_identity_id: r.content_identity_id });
export const sellerFilter = (r) => ({ seller_code: r.seller_code });
