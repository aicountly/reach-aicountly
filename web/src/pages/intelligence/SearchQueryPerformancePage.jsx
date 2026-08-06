import { useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import { listSearchMetrics } from '../../services/intelligenceService.js';

export default function SearchQueryPerformancePage() {
  const [rows, setRows] = useState([]);
  const [period, setPeriod] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listSearchMetrics({ dimension: 'query' })
      .then((data) => {
        if (cancelled) return;
        const list = Array.isArray(data) ? data : (data?.rows || data?.metrics || data?.data || []);
        setRows(list);
        setPeriod(data?.period ?? null);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load query metrics');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <Search size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Query Performance</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Search queries driving traffic to your content
            {period && <> · {period.from} → {period.to}</>}
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading query metrics…</p>}

      {!loading && rows.length === 0 && !error && (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No query metrics available yet. Connect Google Search Console and run an ingest job to populate live data.
            </p>
          </div>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Query</th>
                <th style={{ textAlign: 'right' }}>Clicks</th>
                <th style={{ textAlign: 'right' }}>Impressions</th>
                <th style={{ textAlign: 'right' }}>CTR</th>
                <th style={{ textAlign: 'right' }}>Avg Position</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((q, i) => {
                const ctr = Number(q.ctr ?? 0);
                const position = Number(q.position ?? q.avg_position ?? 0);
                return (
                  <tr key={q.query || i}>
                    <td style={{ fontWeight: 500 }}>{q.query || q.dimension_value || '—'}</td>
                    <td style={{ textAlign: 'right' }}>{Number(q.clicks || 0).toLocaleString()}</td>
                    <td style={{ textAlign: 'right' }}>{Number(q.impressions || 0).toLocaleString()}</td>
                    <td style={{ textAlign: 'right' }}>{(ctr <= 1 ? ctr * 100 : ctr).toFixed(1)}%</td>
                    <td style={{ textAlign: 'right', fontWeight: 600 }}>{position.toFixed(1)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
