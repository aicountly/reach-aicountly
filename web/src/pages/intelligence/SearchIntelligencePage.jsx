import { useEffect, useState } from 'react';
import { Search, RefreshCw } from 'lucide-react';
import { listSearchMetrics } from '../../services/intelligenceService.js';

export default function SearchIntelligencePage() {
  const [stats, setStats] = useState([
    { label: 'Total Queries', value: '—' },
    { label: 'Avg Position', value: '—' },
    { label: 'Total Clicks', value: '—' },
    { label: 'Impressions', value: '—' },
    { label: 'Avg CTR', value: '—' },
    { label: 'Rows Loaded', value: '—' },
  ]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = () => {
    setLoading(true);
    setError(null);
    listSearchMetrics()
      .then((data) => {
        const rows = Array.isArray(data) ? data : (data?.metrics || data?.data || []);
        const clicks = rows.reduce((n, r) => n + Number(r.clicks || 0), 0);
        const impressions = rows.reduce((n, r) => n + Number(r.impressions || 0), 0);
        const positions = rows.map((r) => Number(r.position ?? r.avg_position)).filter((n) => Number.isFinite(n) && n > 0);
        const avgPos = positions.length ? (positions.reduce((a, b) => a + b, 0) / positions.length) : null;
        const ctr = impressions > 0 ? (clicks / impressions) * 100 : null;
        setStats([
          { label: 'Total Queries', value: String(rows.length) },
          { label: 'Avg Position', value: avgPos == null ? '—' : avgPos.toFixed(1) },
          { label: 'Total Clicks', value: clicks.toLocaleString() },
          { label: 'Impressions', value: impressions.toLocaleString() },
          { label: 'Avg CTR', value: ctr == null ? '—' : `${ctr.toFixed(1)}%` },
          { label: 'Rows Loaded', value: String(rows.length) },
        ]);
      })
      .catch((e) => setError(e.message || 'Failed to load search metrics'))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <Search size={26} style={{ color: 'var(--color-info)' }} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Search Console Intelligence</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Google Search Console performance analytics
            </p>
          </div>
        </div>
        <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      <div className="grid grid-3" style={{ marginBottom: '1.25rem' }}>
        {stats.map((s) => (
          <div key={s.label} className="stat-tile">
            <div className="stat-tile__label">{s.label}</div>
            <div className="stat-tile__value">{loading ? '…' : s.value}</div>
          </div>
        ))}
      </div>

      <div className="alert alert-info" style={{ marginBottom: 0 }}>
        Connect a Google Search Console property via <strong>Intelligence → Connectors</strong> and ingest data to populate live metrics.
        Empty zeros mean no live facts have been ingested yet.
      </div>
    </div>
  );
}
