import { useEffect, useState } from 'react';
import { FileText } from 'lucide-react';
import { listSearchMetrics } from '../../services/intelligenceService.js';

export default function SearchPagePerformancePage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listSearchMetrics({ dimension: 'page' })
      .then((data) => {
        if (cancelled) return;
        const list = Array.isArray(data) ? data : (data?.metrics || data?.data || []);
        setRows(list);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load page metrics');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return (
    <div style={{ padding: '1.5rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <FileText size={26} style={{ color: 'var(--color-info)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Page Performance</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            How individual pages perform in search results
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading page metrics…</p>}

      {!loading && rows.length === 0 && !error && (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No page metrics available yet. Connect Google Search Console and run an ingest job to populate live data.
            </p>
          </div>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Page</th>
                <th style={{ textAlign: 'right' }}>Clicks</th>
                <th style={{ textAlign: 'right' }}>Impressions</th>
                <th style={{ textAlign: 'right' }}>Avg Position</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((p, i) => {
                const position = Number(p.position ?? p.avg_position ?? 0);
                return (
                  <tr key={p.url || p.page || i}>
                    <td style={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{p.url || p.page || p.dimension_value || '—'}</td>
                    <td style={{ textAlign: 'right', fontWeight: 600 }}>{Number(p.clicks || 0).toLocaleString()}</td>
                    <td style={{ textAlign: 'right' }}>{Number(p.impressions || 0).toLocaleString()}</td>
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
