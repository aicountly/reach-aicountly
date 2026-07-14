import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function CommunityAnalyticsPage() {
  const [overview, setOverview]   = useState(null);
  const [engagement, setEngagement] = useState([]);
  const [coverage, setCoverage]   = useState([]);
  const [days, setDays]           = useState(30);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);

  useEffect(() => {
    setLoading(true);
    Promise.all([
      api.get('/community/analytics/overview'),
      api.get('/community/analytics/engagement', { params: { days } }),
      api.get('/community/analytics/coverage'),
    ])
      .then(([o, e, c]) => {
        setOverview(o.data?.data);
        setEngagement(e.data?.data ?? []);
        setCoverage(c.data?.data ?? []);
      })
      .catch(err => setError(err.message))
      .finally(() => setLoading(false));
  }, [days]);

  if (loading) return <p className="muted">Loading analytics…</p>;
  if (error) return <p className="text-error">{error}</p>;

  const eventsByType = {};
  engagement.forEach(r => {
    eventsByType[r.event_type] = (eventsByType[r.event_type] ?? 0) + Number(r.cnt);
  });

  return (
    <div>
      <div className="page-header">
        <h1>Community Analytics</h1>
        <label className="toolbar__label ml-auto">
          Days
          <select value={days} onChange={e => setDays(Number(e.target.value))} className="form-select form-select--sm">
            {[7, 14, 30, 60, 90].map(d => <option key={d} value={d}>{d}d</option>)}
          </select>
        </label>
      </div>

      {overview && (
        <div className="stat-grid mb-4">
          <div className="stat-card">
            <div className="stat-card__value">{overview.published_answers ?? 0}</div>
            <div className="stat-card__label">Published answers</div>
          </div>
          <div className="stat-card">
            <div className="stat-card__value">{overview.pending_approval ?? 0}</div>
            <div className="stat-card__label">Pending approval</div>
          </div>
          <div className="stat-card stat-card--warning">
            <div className="stat-card__value">{overview.open_moderation_flags ?? 0}</div>
            <div className="stat-card__label">Open moderation flags</div>
          </div>
        </div>
      )}

      <div className="two-col-grid">
        <section className="card">
          <h2 className="card__title">Genuine engagement events (last {days}d)</h2>
          {Object.keys(eventsByType).length === 0 ? (
            <p className="muted">No validated engagement events.</p>
          ) : (
            <table className="data-table data-table--compact">
              <thead><tr><th>Event type</th><th>Count</th></tr></thead>
              <tbody>
                {Object.entries(eventsByType).map(([type, cnt]) => (
                  <tr key={type}>
                    <td>{type}</td>
                    <td>{cnt}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>

        <section className="card">
          <h2 className="card__title">Top grounding sources</h2>
          {coverage.length === 0 ? (
            <p className="muted">No coverage data.</p>
          ) : (
            <table className="data-table data-table--compact">
              <thead><tr><th>Type</th><th>ID</th><th>Usage</th></tr></thead>
              <tbody>
                {coverage.slice(0, 15).map((c, i) => (
                  <tr key={i}>
                    <td>{c.source_type}</td>
                    <td>{c.source_id}</td>
                    <td>{c.usage_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      </div>
    </div>
  );
}
