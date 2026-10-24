/** Shared vocabulary of the GA4 traffic drill-down (TrafficAnalyticsSection, TrafficExplorerPage). */
import { drillHref } from '../drill';

// Paths of the Analytics record pages (ROUTES.ANALYTICS_TRAFFIC / _SNAPSHOTS / _BLOG_POSTS).
export const TRAFFIC_EXPLORER_PATH = '/analytics/traffic';
export const SNAPSHOTS_PATH = '/analytics/snapshots';
export const LEGACY_BLOG_POSTS_PATH = '/analytics/blog-posts';

export const TRAFFIC_FILTER_KEYS = ['date', 'channel', 'source', 'page', 'device', 'country', 'event'];

export const TRAFFIC_DIMENSIONS = {
  date: { label: 'Day', plural: 'Days' },
  channel: { label: 'Channel', plural: 'Channels' },
  source: { label: 'Source / medium', plural: 'Sources' },
  page: { label: 'Page', plural: 'Pages' },
  device: { label: 'Device', plural: 'Devices' },
  country: { label: 'Country', plural: 'Countries' },
  event: { label: 'Event', plural: 'Events' },
};

export const TRAFFIC_FILTER_LABELS = Object.fromEntries(
  Object.entries(TRAFFIC_DIMENSIONS).map(([k, d]) => [k, d.label]),
);

// After choosing a value, the next useful question: a day → which channels;
// a channel → which sources; a source → which pages; then when, on what
// device, where, and which events fired.
const NEXT = ['channel', 'source', 'page', 'date', 'device', 'country', 'event'];

export function nextTrafficDimension(filters) {
  return NEXT.find((d) => !filters[d]) || null;
}

export const DAYS_OPTIONS = [7, 30, 90];

export const EVENT_LABELS = {
  lead_form_submit: 'Contact form submits',
  blog_lead_submit: 'Blog lead submits',
  blog_cta_click: 'Blog CTA clicks',
  pricing_view: 'Pricing page views',
  signup_click: 'Sign-up clicks',
};

// GA4 events that submit a lead form: they link to the leads Reach captured
// in the same window (GA4 counts submits, Reach counts stored leads, so the
// two can differ); clicks and page views have no Reach records.
export const LEAD_SUBMIT_EVENTS = new Set(['lead_form_submit', 'blog_lead_submit']);

/** Fallback when desk taxonomy has not loaded yet (mirrors DeskTaxonomy::trafficAnalyticsSaasProducts). */
const SAAS_PRODUCTS_FALLBACK = {
  smart_books: 'Aicountly Smart Books',
  contacts: 'Aicountly Contacts',
  calendar: 'Aicountly Calendar',
  financial_reporting: 'Aicountly Financial Reporting',
  secretarial: 'Aicountly Secretarial',
  auditor: 'Aicountly Auditor',
  vault: 'Aicountly Vault',
  hrms: 'Aicountly HRMS',
  my_account: 'My Account',
  our_people: 'Aicountly Our People',
  manage_account: 'Aicountly Manage',
  buddy: 'Aicountly AI Pulse',
  drive: 'Aicountly Drive',
  connect: 'Aicountly Connect',
  notes: 'Aicountly Notes',
  pos: 'Aicountly POS',
  billing: 'Aicountly Billing',
  inventory: 'Aicountly Inventory',
  sales: 'Aicountly Sales',
  purchase: 'Aicountly Purchase',
  insights: 'Aicountly Insights',
  remote: 'Aicountly Remote',
  advisor: 'Aicountly Advisor',
  appointments: 'Aicountly Appointments',
  contracts: 'Aicountly Contracts',
  pay: 'Aicountly Pay',
};

export const STREAM_OPTIONS = [
  { value: 'all', label: 'All sites' },
  ...Object.entries(SAAS_PRODUCTS_FALLBACK)
    .filter(([slug]) => slug !== 'flow')
    .sort(([, a], [, b]) => a.localeCompare(b, undefined, { sensitivity: 'base' }))
    .map(([slug, label]) => ({ value: `saas:${slug}`, label })),
];

const STREAM_NAMES = { marketing_site: 'Marketing site', portal: 'Reach portal' };

export function streamLabel(streamId) {
  return STREAM_OPTIONS.find((o) => o.value === streamId)?.label ?? STREAM_NAMES[streamId] ?? streamId;
}

/** YYYYMMDD (GA4) → YYYY-MM-DD. */
export function isoDay(ymd) {
  const s = String(ymd || '').replace(/-/g, '');
  return s.length === 8 ? `${s.slice(0, 4)}-${s.slice(4, 6)}-${s.slice(6, 8)}` : String(ymd || '');
}

/** How a dimension value reads on screen. */
export function formatTrafficValue(key, value) {
  if (value === '' || value == null) return '(not set)';
  if (key === 'date') return isoDay(value);
  if (key === 'event') return EVENT_LABELS[value] ? `${EVENT_LABELS[value]} (${value})` : value;
  if (key === 'device') return value.charAt(0).toUpperCase() + value.slice(1);
  return value;
}

export function num(value) {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString() : '—';
}

export function pct(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? `${n.toFixed(1)}%` : '—';
}

/**
 * Link into the traffic explorer for the same window and stream. `all` is
 * the explorer's default stream, so it is left out of the URL.
 */
export function trafficExplorerHref({ days, stream, ...params }) {
  return drillHref(TRAFFIC_EXPLORER_PATH, {
    days: days && Number(days) !== 30 ? days : '',
    stream: stream && stream !== 'all' ? stream : '',
    ...params,
  });
}
