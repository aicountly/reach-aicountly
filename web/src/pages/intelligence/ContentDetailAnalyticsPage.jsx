import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { BarChart2 } from 'lucide-react';
import api from '../../services/api.js';

export default function ContentDetailAnalyticsPage() {
  const { id } = useParams();
  const [facts, setFacts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api.get(`v1/intelligence/content/metrics/${id}`)
      .then((data) => {
        if (cancelled) return;
        const rows = Array.isArray(data) ? data : (data?.facts || data?.data || []);
        setFacts(rows);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load content analytics');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [id]);

  const sessions = facts.reduce((n, f) => n + Number(f.sessions || f.session_count || 0), 0);
  const engagementVals = facts.map((f) => Number(f.engagement_rate)).filter((n) => Number.isFinite(n));
  const avgEngagement = engagementVals.length
    ? engagementVals.reduce((a, b) => a + b, 0) / engagementVals.length
    : null;

  return (
    <div style={{ padding: '1.5rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <BarChart2 size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Content Analytics</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Identity #{id} performance detail
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      <div className="grid grid-2" style={{ marginBottom: '1.25rem' }}>
        <div className="stat-tile">
          <div className="stat-tile__label">Sessions (loaded facts)</div>
          <div className="stat-tile__value">{loading ? '…' : sessions.toLocaleString()}</div>
        </div>
        <div className="stat-tile">
          <div className="stat-tile__label">Avg Engagement Rate</div>
          <div className="stat-tile__value">
            {loading || avgEngagement == null
              ? '—'
              : `${(avgEngagement <= 1 ? avgEngagement * 100 : avgEngagement).toFixed(0)}%`}
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-header">Metric Facts</div>
        <div className="card-body">
          {loading && <p className="text-sm text-muted" style={{ margin: 0 }}>Loading…</p>}
          {!loading && facts.length === 0 && !error && (
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No analytics facts for this content identity yet.
            </p>
          )}
          {!loading && facts.length > 0 && (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th style={{ textAlign: 'right' }}>Sessions</th>
                    <th style={{ textAlign: 'right' }}>Engagement</th>
                  </tr>
                </thead>
                <tbody>
                  {facts.map((f, i) => (
                    <tr key={f.id || i}>
                      <td>{f.metric_date || f.date || '—'}</td>
                      <td style={{ textAlign: 'right' }}>{Number(f.sessions || f.session_count || 0).toLocaleString()}</td>
                      <td style={{ textAlign: 'right' }}>
                        {f.engagement_rate == null
                          ? '—'
                          : `${(Number(f.engagement_rate) <= 1 ? Number(f.engagement_rate) * 100 : Number(f.engagement_rate)).toFixed(0)}%`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
