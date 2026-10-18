/** Formatting helpers for the command centre (kept out of component files). */

const nf = new Intl.NumberFormat('en-IN');

export const num = (v) => (typeof v === 'number' && Number.isFinite(v) ? nf.format(Math.round(v * 10) / 10) : '—');

export const pct = (v, digits = 1) => (typeof v === 'number' ? `${(v * 100).toFixed(digits)}%` : '—');

/** +12.5% / −3.0% with a direction for colouring; null when there is no base. */
export function change(v) {
  if (typeof v !== 'number') return null;
  const dir = v > 0.005 ? 'up' : v < -0.005 ? 'down' : 'flat';
  const sign = v > 0 ? '+' : v < 0 ? '−' : '';
  return { text: `${sign}${Math.abs(v * 100).toFixed(1)}%`, dir };
}

export const inr = (v) => (typeof v === 'number'
  ? new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(v)
  : '—');

/** "3m ago", "5h ago", "2d ago" from an ISO / SQL timestamp. */
export function ago(ts, now = Date.now()) {
  if (!ts) return '—';
  const t = new Date(String(ts).replace(' ', 'T')).getTime();
  if (Number.isNaN(t)) return '—';
  const s = Math.max(0, Math.round((now - t) / 1000));
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

export const WINDOWS = [
  { id: 'h24', label: '24 h', ms: 86400e3 },
  { id: 'd7', label: '7 days', ms: 7 * 86400e3 },
  { id: 'd30', label: '30 days', ms: 30 * 86400e3 },
];
