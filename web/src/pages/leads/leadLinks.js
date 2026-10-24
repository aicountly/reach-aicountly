import { drillHref } from '../../utils/drill';

// Paths of the lead detail and attribution conversion pages. They mirror the
// ROUTES entries (LEAD_DETAIL, ATTRIBUTION_CONVERSIONS) so this area links
// correctly before and after those constants exist.
export const LEAD_DETAIL_PATH = '/leads/:id';
export const ATTRIBUTION_CONVERSIONS_PATH = '/intelligence/attribution/conversions';

/** The lead detail page for one lead; null when there is no lead to open. */
export function leadHref(id) {
  return id ? LEAD_DETAIL_PATH.replace(':id', encodeURIComponent(String(id))) : null;
}

/** The attribution conversion list narrowed by `params` (attributed, channel, model, …). */
export function conversionsHref(params = {}) {
  return drillHref(ATTRIBUTION_CONVERSIONS_PATH, params);
}
