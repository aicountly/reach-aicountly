import { useEffect, useState } from 'react';
import { Target, Users, CheckCircle, AlertCircle } from 'lucide-react';
import { getAttributionOverview } from '../../services/intelligenceService.js';

const EMPTY_STATS = {
  total_conversions: 0,
  attributed: 0,
  unattributed: 0,
  attribution_rate: 0,
};

export default function AttributionOverviewPage() {
  const [stats, setStats] = useState(EMPTY_STATS);
  const [breakdown, setBreakdown] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    getAttributionOverview()
      .then((data) => {
        if (cancelled) return;
        setStats(data?.stats || EMPTY_STATS);
        setBreakdown(Array.isArray(data?.first_touch_breakdown) ? data.first_touch_breakdown : []);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load attribution data');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  const tiles = [
    { label: 'Total Conversions', value: stats.total_conversions, icon: Target, color: 'var(--color-info)' },
    { label: 'Attributed', value: stats.attributed, icon: CheckCircle, color: 'var(--color-success)' },
    { label: 'Unattributed', value: stats.unattributed, icon: AlertCircle, color: 'var(--color-warning)' },
    { label: 'Attribution Rate', value: `${stats.attribution_rate ?? 0}%`, icon: Users, color: 'var(--color-primary-hover)' },
  ];

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <Target size={28} style={{ color: 'var(--color-info)', flexShrink: 0 }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Attribution Overview</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            First-touch and last-touch lead attribution
          </p>
        </div>
      </div>

      {error && (
        <div className="alert alert-danger">{error}</div>
      )}

      {loading ? (
        <p className="text-sm text-muted">Loading attribution metrics…</p>
      ) : (
        <>
          <div className="grid grid-4" style={{ marginBottom: '1.25rem' }}>
            {tiles.map((s) => (
              <div key={s.label} className="stat-tile">
                <s.icon size={18} style={{ color: s.color, marginBottom: '0.35rem' }} />
                <div className="stat-tile__value" style={{ color: s.color }}>{s.value}</div>
                <div className="stat-tile__label">{s.label}</div>
              </div>
            ))}
          </div>

          <div className="card" style={{ marginBottom: 0 }}>
            <div className="card-header">Conversions by First-Touch Channel</div>
            <div className="card-body">
              {breakdown.length === 0 ? (
                <p className="text-sm text-muted" style={{ margin: 0 }}>
                  No attributed conversions yet. Channel breakdown appears after conversion links
                  are calculated from live touchpoints.
                </p>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
                  {breakdown.map((b) => (
                    <div key={b.channel}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', marginBottom: '0.3rem' }}>
                        <span>{b.channel}</span>
                        <span style={{ fontWeight: 600 }}>{b.conversions} ({b.pct}%)</span>
                      </div>
                      <div style={{ width: '100%', background: 'var(--color-border)', borderRadius: 999, height: 8 }}>
                        <div
                          style={{
                            width: `${Math.min(100, b.pct || 0)}%`,
                            background: 'var(--color-primary)',
                            height: 8,
                            borderRadius: 999,
                          }}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
