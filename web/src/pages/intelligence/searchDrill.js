/** Shared vocabulary of the Search Console drill-down (SearchExplorer, SiteWideSearchPanel). */

export const SEARCH_FILTER_KEYS = ['date', 'query', 'page', 'device', 'country', 'bucket'];

export const SEARCH_DIMENSIONS = {
  date: { label: 'Day', plural: 'Days' },
  query: { label: 'Query', plural: 'Queries' },
  page: { label: 'Page', plural: 'Pages' },
  device: { label: 'Device', plural: 'Devices' },
  country: { label: 'Country', plural: 'Countries' },
};

export const FILTER_LABELS = { ...Object.fromEntries(Object.entries(SEARCH_DIMENSIONS).map(([k, d]) => [k, d.label])), bucket: 'Position' };

// After choosing a value, the most useful next question: a day → which
// queries; a query → which pages rank for it; a page → which queries bring
// people there; then when, on what device, and where.
const NEXT = ['query', 'page', 'date', 'device', 'country'];

export function nextDimension(filters) {
  return NEXT.find((d) => !filters[d]) || null;
}

export function num(value) {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString() : '—';
}

export function pct(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? `${(n * 100).toFixed(1)}%` : '—';
}

export function pos(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? n.toFixed(1) : '—';
}

export function shortUrl(url) {
  return String(url || '').replace(/^https?:\/\/(www\.)?/, '');
}

const DEVICE_NAMES = { MOBILE: 'Mobile', DESKTOP: 'Desktop', TABLET: 'Tablet', UNKNOWN: 'Unknown device' };

/** How a dimension value reads on screen; '' is a row Google did not attribute. */
export function formatSearchValue(key, value) {
  if (value === '' || value == null) return key === 'query' ? '(query not provided)' : '(not provided)';
  if (key === 'page') return shortUrl(value);
  if (key === 'device') return DEVICE_NAMES[value] || value;
  if (key === 'country') return value === 'ZZZ' ? 'Unknown country' : value.toUpperCase();
  if (key === 'bucket') return value;
  return value;
}
