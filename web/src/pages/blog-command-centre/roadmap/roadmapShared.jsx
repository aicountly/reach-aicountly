import { Map as MapIcon } from 'lucide-react';

/**
 * Renders a score delta. A missing trend is shown as an em dash rather than 0 so
 * "no comparison data" is never confused with "no movement".
 */
export function TrendCell({ value }) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-muted">—</span>;
  }

  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return <span className="text-muted">—</span>;

  const sign = numeric > 0 ? '+' : '';
  const variant = numeric > 0 ? 'success' : numeric < 0 ? 'danger' : 'muted';

  return <span className={`badge badge--${variant}`}>{sign}{numeric.toFixed(1)}</span>;
}

export function RoadmapEmptyState({ title, detail }) {
  return (
    <div className="empty-state" style={{ padding: '2.5rem 1rem', textAlign: 'center' }}>
      <MapIcon size={36} style={{ color: 'var(--color-text-muted)', marginBottom: '0.75rem' }} aria-hidden="true" />
      <h3 style={{ marginBottom: '0.35rem' }}>{title}</h3>
      <p className="text-muted">{detail}</p>
    </div>
  );
}
