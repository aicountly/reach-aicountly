/**
 * Pure helpers behind Channels → Social & Ads. Everything here reflects what
 * the API reports; nothing is shown as posted / connected unless Reach's own
 * records say so (see docs/operations/SOCIAL_API.md and CAMPAIGN_READINESS.md).
 */
import { Facebook, Instagram, Linkedin, Twitter, Youtube, Share2 } from 'lucide-react';

/** Reach social channels (reach_social_posts.channel) in display order. */
export const NETWORKS = [
  { key: 'linkedin',  label: 'LinkedIn',    icon: Linkedin },
  { key: 'facebook',  label: 'Facebook',    icon: Facebook },
  { key: 'instagram', label: 'Instagram',   icon: Instagram },
  { key: 'twitter',   label: 'X / Twitter', icon: Twitter },
  { key: 'youtube',   label: 'YouTube',     icon: Youtube },
];

export function networkMeta(key) {
  return NETWORKS.find((n) => n.key === key) ?? { key, label: key ? String(key).replace(/_/g, ' ') : 'Unknown', icon: Share2 };
}

/** Readiness states from GET v1/distribution/readiness. */
export const READINESS_META = {
  ready:          { label: 'Connected',      badge: 'badge--success', dot: 'ok' },
  test_mode:      { label: 'Test mode',      badge: 'badge--warning', dot: 'warning' },
  not_configured: { label: 'Not configured', badge: 'badge--danger',  dot: 'error' },
  blocked:        { label: 'Blocked',        badge: 'badge--warning', dot: 'warning' },
  not_supported:  { label: 'Not connected',  badge: 'badge--neutral', dot: 'none' },
};

export function readinessMeta(state) {
  return READINESS_META[state] ?? { label: state ? String(state).replace(/_/g, ' ') : 'Unknown', badge: 'badge--neutral', dot: 'none' };
}

/** reach_social_posts.status → label + badge. `publishing` is not `posted`. */
export const POST_STATUS_META = {
  draft:            { label: 'Draft',            badge: 'badge--neutral' },
  pending_approval: { label: 'Pending approval', badge: 'badge--neutral' },
  approved:         { label: 'Approved — not sent yet', badge: 'badge--info' },
  scheduled:        { label: 'Scheduled',        badge: 'badge--info' },
  manual_queue:     { label: 'Waiting — no publisher', badge: 'badge--warning' },
  publishing:       { label: 'Publishing',       badge: 'badge--warning' },
  posted:           { label: 'Posted',           badge: 'badge--success' },
  failed:           { label: 'Failed',           badge: 'badge--danger' },
  archived:         { label: 'Archived',         badge: 'badge--neutral' },
};

export function postStatusMeta(status) {
  return POST_STATUS_META[status] ?? { label: status ? String(status).replace(/_/g, ' ') : 'Unknown', badge: 'badge--neutral' };
}

/** Calendar filters → the statuses each one lists (the API filters one status per call). */
export const CALENDAR_FILTERS = [
  { id: 'upcoming',   label: 'Upcoming',   statuses: ['scheduled', 'approved', 'manual_queue'], order: 'asc' },
  { id: 'publishing', label: 'Publishing', statuses: ['publishing'], order: 'desc' },
  { id: 'posted',     label: 'Posted',     statuses: ['posted'], order: 'desc' },
  { id: 'failed',     label: 'Failed',     statuses: ['failed'], order: 'desc' },
];

/** The time that matters for a post in a calendar: when it goes / went out. */
export function postWhen(post) {
  if (!post) return null;
  if (post.status === 'posted') return post.published_at || post.submitted_at || post.updated_at || null;
  if (post.status === 'publishing') return post.submitted_at || post.scheduled_at || post.updated_at || null;
  if (post.status === 'failed') return post.submitted_at || post.scheduled_at || post.updated_at || null;
  return post.scheduled_at || post.created_at || null;
}

function ts(value) {
  if (!value) return NaN;
  let raw = String(value).trim();
  if (raw.includes(' ') && !raw.includes('T')) raw = raw.replace(' ', 'T');
  raw = raw.replace(/([+-]\d{2})$/, '$1:00'); // Postgres "+00" offsets
  const t = Date.parse(raw);
  return Number.isNaN(t) ? NaN : t;
}

/** Sort posts by postWhen (undated last), ascending or descending. */
export function sortPosts(posts, order = 'asc') {
  const dir = order === 'desc' ? -1 : 1;
  return [...posts].sort((a, b) => {
    const ta = ts(postWhen(a));
    const tb = ts(postWhen(b));
    if (Number.isNaN(ta) && Number.isNaN(tb)) return (b.id ?? 0) - (a.id ?? 0);
    if (Number.isNaN(ta)) return 1;
    if (Number.isNaN(tb)) return -1;
    return (ta - tb) * dir;
  });
}

/** Local calendar day key (YYYY-MM-DD) for grouping, or 'undated'. */
export function dayKey(value) {
  const t = ts(value);
  if (Number.isNaN(t)) return 'undated';
  const d = new Date(t);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** [{ day, posts }] in the given (already sorted) order. */
export function groupByDay(posts) {
  const groups = [];
  const index = new Map();
  for (const p of posts) {
    const k = dayKey(postWhen(p));
    if (!index.has(k)) {
      index.set(k, groups.length);
      groups.push({ day: k, posts: [] });
    }
    groups[index.get(k)].posts.push(p);
  }
  return groups;
}

/** A one-line title: the YouTube title, else the first line of the text. */
export function postTitle(post) {
  const raw = String(post?.title || post?.content || '').trim();
  const first = raw.split(/\r?\n/)[0] || '';
  if (!first) return '(no text)';
  return first.length > 110 ? `${first.slice(0, 107)}…` : first;
}

/** Videos come from the video pipeline: the pipeline (not this page) re-publishes them. */
export function isPipelineVideo(post) {
  return post?.channel === 'youtube';
}

/** Retry = dispatch again (SOCIAL_API.md: social-api.ai refuses a duplicate within 24 h). */
export function canRetry(post) {
  return post?.status === 'failed' && post?.approval_status === 'approved' && !isPipelineVideo(post);
}

/**
 * Counts per network from rows fetched per status.
 * @param {Record<string, { items: any[], total: number }>} byStatus
 */
export function tabulate(byStatus) {
  const statuses = Object.keys(byStatus);
  const rows = NETWORKS.map((n) => ({ ...n, counts: Object.fromEntries(statuses.map((s) => [s, 0])) }));
  const other = { key: 'other', label: 'Other', icon: Share2, counts: Object.fromEntries(statuses.map((s) => [s, 0])) };
  for (const s of statuses) {
    for (const p of byStatus[s]?.items ?? []) {
      const row = rows.find((r) => r.key === p.channel) ?? other;
      row.counts[s] += 1;
    }
  }
  const all = [...rows, other].filter((r) => r.key !== 'other' || statuses.some((s) => r.counts[s] > 0));
  const truncated = statuses.filter((s) => (byStatus[s]?.total ?? 0) > (byStatus[s]?.items?.length ?? 0));
  return { rows: all, truncated };
}

/** Posted in the last `days` days (by published_at), from posted rows. */
export function postedWithin(items, days, now = Date.now()) {
  const since = now - days * 86_400_000;
  return (items ?? []).filter((p) => {
    const t = ts(p.published_at || p.submitted_at);
    return !Number.isNaN(t) && t >= since && t <= now + 60_000;
  }).length;
}

export function successRate(posted, failed) {
  const done = (posted ?? 0) + (failed ?? 0);
  if (done === 0) return null;
  return Math.round(((posted ?? 0) / done) * 100);
}

/**
 * Paid-ads platforms. Reach has no ads integration (CAMPAIGN_READINESS.md:
 * "Google Ads, Meta Ads, LinkedIn Ads — not supported"); these cards say what
 * a connection would need, they do not pretend one exists.
 */
export const ADS_PLATFORMS = [
  {
    key: 'google_ads',
    label: 'Google Ads',
    needs: [
      'A Google Ads manager (MCC) account and an approved Google Ads API developer token (Basic or Standard access).',
      'A Google Cloud OAuth 2.0 client (client id + secret) and a refresh token for a user with access to the ads account.',
      'The customer id of the AICOUNTLY ads account (and the manager login-customer-id).',
      'Reach-side code for campaigns, spend and conversion reporting — not built.',
    ],
  },
  {
    key: 'meta_ads',
    label: 'Meta Ads (Facebook & Instagram)',
    needs: [
      'A Meta Business app with the Marketing API product, and Business verification.',
      'ads_read / ads_management permissions with Advanced Access (Meta App Review).',
      'A system user in Business Manager with a long-lived access token, assigned to the ad account (act_…).',
      'Reach-side code for campaigns, audiences and insights — not built.',
    ],
  },
  {
    key: 'linkedin_ads',
    label: 'LinkedIn Ads',
    needs: [
      'Approved access to the LinkedIn Advertising API (Marketing Developer Platform).',
      'An OAuth app with r_ads / rw_ads (and r_ads_reporting) scopes and a token for an ad-account admin.',
      'The sponsored ad account id.',
      'Reach-side code for campaigns and reporting — not built.',
    ],
  },
];
