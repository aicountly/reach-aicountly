import { TrendingUp, TrendingDown, Minus } from 'lucide-react';

/**
 * `comparison` is `{ previous, delta, delta_pct }` for the same metric over
 * the immediately preceding period of equal length (see TrafficAnalyticsService
 * ::buildComparison). `delta_pct` is null when the previous period had no
 * baseline to compare against (previous = 0, current > 0).
 */
function ComparisonBadge({ comparison, invert, periodLabel }) {
  if (!comparison) return null;

  const label = periodLabel ? `vs prior ${periodLabel}` : 'vs prior period';
  const { delta_pct: deltaPct } = comparison;

  if (deltaPct === null || deltaPct === undefined) {
    return <p className="text-sm text-muted" style={{ marginTop: '0.25rem' }}>New · {label}</p>;
  }

  const isFlat = deltaPct === 0;
  const isRise = deltaPct > 0;
  // "Good" depends on the metric: more sessions is good, but a lower bounce
  // rate is good too — `invert` flips which direction counts as an improvement.
  const isGood = !isFlat && (invert ? !isRise : isRise);
  const Icon = isFlat ? Minus : (isRise ? TrendingUp : TrendingDown);
  const color = isFlat ? 'var(--color-text-muted)' : (isGood ? 'var(--color-success)' : 'var(--color-danger)');
  const sign = isRise ? '+' : '';

  return (
    <p className="flex items-center gap-1 text-sm" style={{ marginTop: '0.25rem', color }}>
      <Icon size={14} aria-hidden="true" />
      <span>{sign}{deltaPct}% {label}</span>
    </p>
  );
}

export function KPICard({
  title, value, subtitle, icon: Icon, color = 'var(--color-primary)',
  comparison, invert = false, periodLabel,
}) {
  return (
    <div className="card" style={{ padding: '1.25rem' }}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted" style={{ marginBottom: '0.25rem' }}>{title}</p>
          <p style={{ fontSize: '1.75rem', fontWeight: 700, color }}>{value}</p>
          {subtitle && <p className="text-sm text-muted" style={{ marginTop: '0.25rem' }}>{subtitle}</p>}
          <ComparisonBadge comparison={comparison} invert={invert} periodLabel={periodLabel} />
        </div>
        {Icon && (
          <div style={{
            width: 48, height: 48, borderRadius: 12,
            background: `${color}15`, display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <Icon size={24} style={{ color }} />
          </div>
        )}
      </div>
    </div>
  );
}
