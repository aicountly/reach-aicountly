import { useState, useEffect } from 'react';
import api from '../../services/api';

export default function DistributionAnalyticsPage() {
  const [metrics, setMetrics] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get('/distribution/analytics')
      .then(r => setMetrics(r.data?.data ?? []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="page-header">
        <h1>Distribution Analytics</h1>
        <p className="page-header__subtitle">Delivery, engagement, and compliance metrics across all channels</p>
      </div>

      {loading && <p className="muted">Loading analytics…</p>}
      {!loading && metrics.length === 0 && (
        <p className="muted">No analytics data available yet. Dispatch campaigns to generate metrics.</p>
      )}

      {!loading && metrics.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Campaign</th>
              <th>Channel</th>
              <th>Sent</th>
              <th>Delivered</th>
              <th>Opens</th>
              <th>Clicks</th>
              <th>Bounces</th>
              <th>Unsubscribes</th>
            </tr>
          </thead>
          <tbody>
            {metrics.map((m, i) => (
              <tr key={i}>
                <td>{m.campaign_id}</td>
                <td><span className="badge badge--neutral">{m.channel}</span></td>
                <td>{m.sent_count ?? 0}</td>
                <td>{m.delivered_count ?? 0}</td>
                <td>{m.open_count ?? 0}</td>
                <td>{m.click_count ?? 0}</td>
                <td>{m.bounce_count ?? 0}</td>
                <td>{m.unsubscribe_count ?? 0}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
