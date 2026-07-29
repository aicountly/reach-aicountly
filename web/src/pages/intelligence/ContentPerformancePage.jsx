import { useEffect, useState } from 'react';
import { BarChart2 } from 'lucide-react';
import { listContentMetrics } from '../../services/intelligenceService.js';

export default function ContentPerformancePage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listContentMetrics()
      .then((data) => {
        if (cancelled) return;
        const list = Array.isArray(data) ? data : (data?.metrics || data?.data || []);
        setRows(list);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load content metrics');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <BarChart2 size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Content Performance</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Per-content GA4 engagement metrics
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading content metrics…</p>}

      {!loading && rows.length === 0 && !error && (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No content analytics available yet. Connect a GA4 property and run an ingest job to populate live data.
            </p>
          </div>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Content</th>
                <th style={{ textAlign: 'right' }}>Sessions</th>
                <th style={{ textAlign: 'right' }}>Engagement Rate</th>
                <th style={{ textAlign: 'right' }}>Avg Time</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((c, i) => {
                const engagement = Number(c.engagement_rate ?? 0);
                const avgTime = Number(c.avg_time ?? c.avg_session_duration ?? 0);
                return (
                  <tr key={c.id || c.url || i}>
                    <td>
                      <p style={{ fontWeight: 600, margin: 0 }}>{c.title || c.content_title || 'Untitled'}</p>
                      <p className="text-xs text-muted" style={{ margin: '0.15rem 0 0', fontFamily: 'monospace' }}>
                        {c.url || c.path || '—'}
                      </p>
                    </td>
                    <td style={{ textAlign: 'right', fontWeight: 600 }}>{Number(c.sessions || 0).toLocaleString()}</td>
                    <td style={{ textAlign: 'right' }}>
                      {(engagement <= 1 ? engagement * 100 : engagement).toFixed(0)}%
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {Math.floor(avgTime / 60)}m {Math.round(avgTime % 60)}s
                    </td>
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
