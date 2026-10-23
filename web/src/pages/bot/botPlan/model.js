/**
 * Pure helpers for Bot → Plan & Performance. Dates are shown in IST with a
 * local Intl helper (the shared date helpers are being unified separately).
 */
import { toDate } from '../../../utils/formatDate';
import { drillHref } from '../../../utils/drill';
import { ROUTES } from '../../../constants/routes';

export const IST = 'Asia/Kolkata';

export const PLAN_PERIODS = [
  { key: 'today', label: 'Today' },
  { key: 'tomorrow', label: 'Tomorrow' },
  { key: 'this_week', label: 'This week' },
  { key: 'next_week', label: 'Next week' },
  { key: 'this_month', label: 'This month' },
  { key: 'next_month', label: 'Next month' },
  { key: 'past', label: 'Past' },
];

export const PAST_RANGES = [
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'last_7_days', label: 'Last 7 days' },
  { key: 'last_30_days', label: 'Last 30 days' },
  { key: 'this_week', label: 'This week so far' },
  { key: 'last_week', label: 'Last week' },
  { key: 'this_month', label: 'This month so far' },
  { key: 'last_month', label: 'Last month' },
];

export const GROUP_LABELS = {
  content: 'Content', video: 'Video', social: 'Social', email: 'Email', covers: 'Covers',
  seo: 'SEO checks', search: 'Search', leads: 'Leads', engage: 'Engage',
};

export const SOURCE_LABELS = {
  config: 'From settings',
  schedule: 'Scheduled',
  mixed: 'Settings + scheduled',
  trend: 'Trend forecast',
  self_tune: 'Self-tuned',
};

export const STATUS_META = {
  met:             { label: 'Met', tone: 'success' },
  behind:          { label: 'Slightly behind', tone: 'warning' },
  lagging:         { label: 'Lagging', tone: 'danger' },
  nothing_planned: { label: 'Nothing planned', tone: 'secondary' },
  no_plan:         { label: 'No plan recorded', tone: 'secondary' },
  no_data:         { label: 'No data yet', tone: 'secondary' },
  baseline:        { label: 'Baseline', tone: 'info' },
};

export const ACTION_LABELS = {
  lower_target: 'Lowered target',
  restore_target: 'Restored target',
  requeue_stuck: 'Retried stuck draft',
  retry_job: 'Retried failed job',
  topic_mix: 'Changed topic mix',
};

/** "YYYY-MM-DD" (an IST calendar date) or an ISO instant → "18 Oct 2026". */
export function formatIstDate(value) {
  if (!value) return '—';
  const s = String(value);
  // toDate reads PostgreSQL's "2026-10-22 10:00:00+00" (an hour-only offset new Date() rejects).
  const d = /^\d{4}-\d{2}-\d{2}$/.test(s) ? new Date(`${s}T12:00:00+05:30`) : toDate(s);
  if (!d || Number.isNaN(d.getTime())) return s;
  return new Intl.DateTimeFormat('en-IN', { timeZone: IST, day: 'numeric', month: 'short', year: 'numeric' }).format(d);
}

/** An instant → "18 Oct 2026, 01:00 IST". */
export function formatIstDateTime(value) {
  if (!value) return '—';
  const d = toDate(value);
  if (!d || Number.isNaN(d.getTime())) return String(value);
  const out = new Intl.DateTimeFormat('en-IN', {
    timeZone: IST, day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).format(d);
  return `${out} IST`;
}

/** A period/range: "12 Oct 2026" or "12 Oct – 18 Oct 2026". */
export function formatIstRange(from, to) {
  if (!from) return '—';
  if (!to || from === to) return formatIstDate(from);
  const short = (v) => new Intl.DateTimeFormat('en-IN', { timeZone: IST, day: 'numeric', month: 'short' })
    .format(new Date(`${v}T12:00:00+05:30`));
  return `${short(from)} – ${formatIstDate(to)}`;
}

/** A metric value in its unit. null → "—". */
export function formatValue(value, unit) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  if (unit === 'inr') {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(n);
  }
  return new Intl.NumberFormat('en-IN', { maximumFractionDigits: n < 10 ? 1 : 0 }).format(n);
}

export function pct(achieved, target) {
  const a = Number(achieved);
  const t = Number(target);
  if (achieved === null || achieved === undefined || !Number.isFinite(a) || !Number.isFinite(t) || t <= 0) return null;
  return Math.round((a / t) * 100);
}

/** Tone for an achievement %: ≥ 100 success, ≥ 80 warning, else danger. */
export function pctTone(p) {
  if (p === null || p === undefined) return 'secondary';
  if (p >= 100) return 'success';
  if (p >= 80) return 'warning';
  return 'danger';
}

/** Plan items split for the page: output vs expected outcomes, grouped. */
export function splitPlanItems(items = []) {
  const outputs = [];
  const outcomes = [];
  for (const it of items) (it.kind === 'outcome' ? outcomes : outputs).push(it);
  return { outputs: groupBy(outputs), outcomes };
}

export function groupBy(items = []) {
  const order = Object.keys(GROUP_LABELS);
  const map = new Map();
  for (const it of items) {
    const g = it.metric_group || it.group || 'other';
    if (!map.has(g)) map.set(g, []);
    map.get(g).push(it);
  }
  return [...map.entries()]
    .sort(([a], [b]) => (order.indexOf(a) === -1 ? 99 : order.indexOf(a)) - (order.indexOf(b) === -1 ? 99 : order.indexOf(b)))
    .map(([group, rows]) => ({ group, label: GROUP_LABELS[group] || group, rows }));
}

/** Performance summary counts by status. */
export function statusCounts(metrics = []) {
  const out = { met: 0, behind: 0, lagging: 0, other: 0 };
  for (const m of metrics) {
    if (m.status in out) out[m.status] += 1;
    else out.other += 1;
  }
  return out;
}

/** Human "before → after" for a self-tune log row. */
export function describeChange(row) {
  const b = row?.before || {};
  const a = row?.after || {};
  if ('target_per_day' in a) return `${formatValue(b.target_per_day)} → ${formatValue(a.target_per_day)} a day`;
  if (row?.action === 'retry_job') return `${b.job_type || 'job'}: ${b.status || '?'} → ${a.status || '?'}`;
  if (row?.action === 'requeue_stuck') return `${b.status || 'waiting'} → retry now`;
  if (row?.action === 'topic_mix') {
    const list = (v) => (Array.isArray(v) && v.length ? v.join(', ') : 'none');
    const [kind, status] = String(a.blog_steer || '').split(':');
    const steer = kind ? `; blog next: ${kind.replace('_', '-')}${status ? ` (${status} app)` : ''}` : '';
    return `blog prefer: ${list(a.blog_prefer)}; avoid: ${list(a.blog_avoid)}; KB prefer: ${list(a.kb_prefer)}${steer}`;
  }
  return '—';
}

/**
 * Where the records behind a plan metric live (PlanMetrics.php keys; the
 * predicates follow ActualsCollector). The lists are not date-scoped, so a
 * link narrows to the kind of record, not to the range. null = no list.
 */
export function metricRecordsHref(metricKey) {
  const key = String(metricKey || '');
  let m = key.match(/^content\.(blog|knowledge_base)\.published$/);
  if (m) return drillHref(ROUTES.CONTENT, { content_type: m[1], workflow_status: 'published' });
  m = key.match(/^video\.(vlog|short)\.published$/);
  if (m) return drillHref('/video', { status: 'published', format: m[1] });
  m = key.match(/^social\.([a-z]+)\.posted$/);
  if (m) return drillHref(ROUTES.SOCIAL_CALENDAR, { status: 'posted', channel: m[1] });
  if (key.startsWith('search.')) return '/seo-centre/search';
  return METRIC_RECORDS[key] ?? null;
}

const METRIC_RECORDS = {
  'content.community.published': '/community/deployments',
  'content.release_note.published': '/publishing/release-notes',
  'content.workflow_guide.published': '/publishing/workflow-guides',
  'email.accepted': '/distribution/email',
  'covers.generated': '/quality/gallery',
  'seo.checks_run': '/seo-centre/tracker',
  'leads.captured': ROUTES.LEADS,
  'leads.pushed': drillHref(ROUTES.LEADS, { engage_push_status: 'pushed' }),
  'engage.conversions': drillHref(ROUTES.LEAD_OUTCOMES, { stage: 'converted' }),
  'engage.revenue_inr': drillHref(ROUTES.LEAD_OUTCOMES, { stage: 'payment_confirmed' }),
};

/** A link to a metric's records only when there is something to see (never a zero). */
export function recordsHrefIfAny(metricKey, value) {
  return Number(value) > 0 ? metricRecordsHref(metricKey) : null;
}

/** Each IST calendar day from → to ("YYYY-MM-DD", inclusive). */
export function rangeDates(from, to) {
  if (!from || !to) return [];
  const out = [];
  const d = new Date(`${from}T00:00:00Z`);
  const end = new Date(`${to}T00:00:00Z`);
  for (let i = 0; d <= end && i < 400; i += 1) {
    out.push(d.toISOString().slice(0, 10));
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return out;
}
