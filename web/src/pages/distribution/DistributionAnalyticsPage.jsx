import { useState, useEffect } from 'react';
import api from '../../services/api';
import { DrillLink, drillHref, useUrlFilter } from '../../components/drill';

// Bounces and unsubscribes end up on the suppression list, which is kept per
// channel (not per dispatch) — the link opens that channel's suppressions.
const suppressionsHref = (count, channel, reason) => (count > 0
  ? drillHref('/distribution/suppressions', { channel, reason })
  : undefined);

export default function DistributionAnalyticsPage() {
  const [metrics, setMetrics] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);
  // ?channel= narrows the table; the API applies it (AnalyticsController::index).
  const [channel, setChannel] = useUrlFilter('channel');

  useEffect(() => {
    setLoading(true);
    setError(null);
    api.get('v1/distribution/analytics', channel ? { channel } : undefined)
      .then(r => setMetrics(Array.isArray(r) ? r : (r?.data ?? [])))
      .catch(e => {
        // Soft-fail while migrations/deploy catch up — show empty state, not a hard error.
        const status = e?.status ?? e?.response?.status;
        if (status === 404 || status === 500) {
          setMetrics([]);
          return;
        }
        setError(e.message || 'Failed to load analytics');
      })
      .finally(() => setLoading(false));
  }, [channel]);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Distribution Analytics</h1>
        <p className="page-header__subtitle">
          Delivery, engagement, and compliance metrics across all channels
        </p>
      </div>

      {channel && (
        <div className="filter-bar mb-4">
          <button type="button" className="badge badge--neutral" onClick={() => setChannel('')}
                  aria-label={`Clear channel ${channel} filter`}>
            Channel: {channel} ✕
          </button>
        </div>
      )}

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading analytics…</p>}

      {!loading && !error && metrics.length === 0 && (
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
            {metrics.map((m) => (
              <tr key={m.id ?? `${m.dispatch_id}-${m.channel}`}>
                <td>
                  <DrillLink to={m.campaign_id ? `/distribution/campaigns/${m.campaign_id}` : undefined}>
                    {m.campaign_id ?? '—'}
                  </DrillLink>
                </td>
                <td>
                  <DrillLink onClick={m.channel && m.channel !== channel ? () => setChannel(m.channel) : undefined}
                             title="Only this channel">
                    <span className="badge badge--neutral">{m.channel}</span>
                  </DrillLink>
                </td>
                <td>{m.sent_count ?? 0}</td>
                <td>{m.delivered_count ?? 0}</td>
                <td>{m.open_count ?? 0}</td>
                <td>{m.click_count ?? 0}</td>
                <td>
                  <DrillLink to={suppressionsHref(m.bounce_count, m.channel, 'bounce')}
                             title="Bounced addresses suppressed on this channel">
                    {m.bounce_count ?? 0}
                  </DrillLink>
                </td>
                <td>
                  <DrillLink to={suppressionsHref(m.unsubscribe_count, m.channel, 'unsubscribe')}
                             title="Unsubscribed addresses on this channel">
                    {m.unsubscribe_count ?? 0}
                  </DrillLink>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
