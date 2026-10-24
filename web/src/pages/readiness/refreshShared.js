/** Shared vocabulary of the refresh recommendation / workflow pages. */

export const RECOMMENDATION_STATUSES = ['pending', 'recommended', 'triaged', 'accepted', 'rejected', 'deferred', 'superseded', 'expired'];
export const RISKS = ['critical', 'high', 'medium', 'low'];
export const WORKFLOW_STATUSES = [
  'accepted', 'brief_prepared', 'draft_generating', 'draft_ready', 'in_review',
  'approved', 'publish_queued', 'published', 'monitoring', 'outcome_recorded',
  'rejected', 'deferred', 'changes_requested', 'blocked', 'cancelled', 'superseded', 'failed', 'withdrawn',
];

export const RISK_BADGE = {
  critical: 'badge--danger',
  high: 'badge--warning',
  medium: 'badge--info',
  low: 'badge--neutral',
};

export const label = (v) => String(v ?? '').replace(/,/g, ' + ').replace(/_/g, ' ');

export function shortUrl(url) {
  return String(url || '').replace(/^https?:\/\/(www\.)?/, '');
}

export function score(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? n.toFixed(3) : '—';
}

export function confidence(value) {
  if (value == null) return '—';
  const n = Number(value);
  return Number.isFinite(n) ? `${Math.round(n * 100)}%` : '—';
}
