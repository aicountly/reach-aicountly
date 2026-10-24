/**
 * Ads drill-down: summary tile → the campaigns behind it (sorted by that
 * metric) → one campaign's days (/social/ads/:id) → one day's results row.
 * Every step reads the figures GET v1/ads/summary and
 * GET v1/ads/campaigns/{id}/results report for the same period, so the
 * numbers add up from tile to day.
 */
import { drillHref } from '../../../components/drill';
import { ROUTES } from '../../../constants/routes';
import { formatInr } from '../../../utils/formatInr';

export const num = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-IN'));
export const inr = (v) => (v === null || v === undefined ? '—' : formatInr(Number(v).toFixed(2)));
export const pct = (v) => (v === null || v === undefined ? '—' : `${(Number(v) * 100).toFixed(2)}%`);

const present = (v) => v !== null && v !== undefined;

/**
 * Tile metric → which summary campaign rows it summed and how to sort them.
 * `has` is the summary's own rule: a null figure was not entered and was not
 * added; leads count only when there are some; the live budget sums the
 * daily budgets of `live` campaigns in the period.
 */
export const ADS_METRICS = {
  spend:   { label: 'Spend',             key: 'spend_inr',        fmt: inr, has: (c) => present(c.spend_inr) },
  clicks:  { label: 'Clicks',            key: 'clicks',           fmt: num, has: (c) => present(c.clicks) },
  leads:   { label: 'Leads',             key: 'leads',            fmt: num, has: (c) => Number(c.leads) > 0 },
  signups: { label: 'Sign-ups',          key: 'signups',          fmt: num, has: (c) => present(c.signups) },
  budget:  { label: 'Live daily budget', key: 'daily_budget_inr', fmt: inr, has: (c) => c.status === 'live' },
};

/** The summary campaigns a metric's tile counted, largest first. */
export function campaignsForMetric(campaigns, metric) {
  const m = ADS_METRICS[metric];
  if (!m) return [];
  return (campaigns || [])
    .filter(m.has)
    .sort((a, b) => (Number(b[m.key]) || 0) - (Number(a[m.key]) || 0) || String(a.name).localeCompare(String(b.name)));
}

/** Day-table columns a metric sorts by (budget has no daily figure: newest first). */
export const DAY_SORT = {
  spend: 'spend_inr', clicks: 'clicks', impressions: 'impressions', conversions: 'conversions', signups: 'signups', leads: 'leads',
};

/** One campaign's days for the summary period, sorted by the drilled metric. */
export function campaignHref(id, { period, metric } = {}) {
  return drillHref(`${ROUTES.SOCIAL_ADS}/${id}`, { from: period?.from, to: period?.to, metric });
}

/**
 * The leads AdsSummary counted for one campaign: its UTM set, captured within
 * the campaign's dates inside [from, to]. Zero is not a link.
 */
export function campaignLeadsHref(figure, period) {
  if (!figure?.leads || !figure.utm) return undefined;
  const later = (a, b) => (a && b ? (a > b ? a : b) : a || b);
  const earlier = (a, b) => (a && b ? (a < b ? a : b) : a || b);
  return drillHref(ROUTES.LEADS, {
    utm_source: figure.utm.source,
    utm_medium: figure.utm.medium,
    utm_campaign: figure.utm.campaign,
    created_from: later(period?.from, figure.start_date),
    created_to: earlier(period?.to, figure.end_date),
  });
}

/** One day's leads for a campaign (its UTM set). */
export function dayLeadsHref(campaign, day, leads) {
  if (!leads || !campaign) return undefined;
  return drillHref(ROUTES.LEADS, {
    utm_source: campaign.utm_source, utm_medium: campaign.utm_medium, utm_campaign: campaign.utm_campaign,
    created_from: day, created_to: day,
  });
}

/** yyyy-mm-dd `days` before `ymd` (calendar arithmetic, no time zone). */
export function daysBefore(ymd, days) {
  const [y, m, d] = String(ymd).split('-').map(Number);
  const t = new Date(Date.UTC(y, m - 1, d) - days * 86_400_000);
  return t.toISOString().slice(0, 10);
}

/**
 * Day rows for the campaign page: every results row, plus days that had
 * leads but no results row (so the leads add up to the summary's).
 */
export function dayRows(results, leadsByDay) {
  const leads = leadsByDay || {};
  const rows = (results || []).map((r) => ({ ...r, day: r.result_date, leads: leads[r.result_date] ?? 0, has_result: true }));
  const seen = new Set(rows.map((r) => r.day));
  Object.entries(leads).forEach(([day, n]) => {
    if (!seen.has(day)) rows.push({ id: `leads-${day}`, day, leads: n, has_result: false });
  });
  return rows;
}

export function sortDays(rows, metric) {
  const key = DAY_SORT[metric];
  return [...rows].sort((a, b) => {
    if (key) {
      const d = (Number(b[key]) || 0) - (Number(a[key]) || 0);
      if (d !== 0) return d;
    }
    return a.day < b.day ? 1 : a.day > b.day ? -1 : 0;
  });
}

/** Period totals over day rows; a figure no row entered stays null. */
export function dayTotals(rows) {
  const sum = (k) => rows.reduce((acc, r) => (present(r[k]) ? (acc ?? 0) + Number(r[k]) : acc), null);
  const t = {
    spend_inr: sum('spend_inr'), impressions: sum('impressions'), clicks: sum('clicks'),
    conversions: sum('conversions'), signups: sum('signups'), leads: rows.reduce((a, r) => a + (Number(r.leads) || 0), 0),
  };
  return { ...t, ...ratios(t) };
}

export function ratios(r) {
  const div = (a, b) => (present(a) && present(b) && Number(b) !== 0 ? Number(a) / Number(b) : null);
  return { ctr: div(r.clicks, r.impressions), cpc_inr: div(r.spend_inr, r.clicks) };
}
