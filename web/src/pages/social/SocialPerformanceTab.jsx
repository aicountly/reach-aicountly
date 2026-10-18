import { useCallback, useEffect, useState } from 'react';
import { BarChart3, RefreshCw } from 'lucide-react';
import api from '../../services/api';
import { postedWithin, successRate, tabulate } from './socialAdsModel';

const STATUSES = ['posted', 'failed', 'publishing', 'scheduled'];
const LIMIT = 200;

/**
 * Performance — what Reach actually stores about social posts: delivery
 * outcomes per network. Reach stores no reach / engagement numbers
 * (impressions, likes, views); the empty state names the data source that
 * would be needed instead of inventing figures.
 */
export default function SocialPerformanceTab() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    Promise.all(STATUSES.map((status) => api.get('v1/social/posts', { status, page: 1, limit: LIMIT })
      .then((r) => {
        const items = Array.isArray(r) ? r : (r?.items ?? r?.data ?? []);
        return [status, { items, total: Number(r?.total ?? items.length) }];
      })))
      .then((pairs) => setData(Object.fromEntries(pairs)))
      .catch((e) => setError(e?.message || 'Could not load social posts.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const totals = data ? Object.fromEntries(STATUSES.map((s) => [s, data[s].total])) : null;
  const table = data ? tabulate(data) : null;
  const rate = totals ? successRate(totals.posted, totals.failed) : null;
  const anything = totals && STATUSES.some((s) => totals[s] > 0);

  return (
    <section aria-labelledby="sa-perf-title">
      <div className="sa-toolbar">
        <div className="sa-toolbar__text">
          <h2 id="sa-perf-title" className="sa-h2">Performance</h2>
          <p className="text-sm text-muted">Delivery outcomes from Reach&apos;s own post records.</p>
        </div>
        <div className="sa-toolbar__actions">
          <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
            <RefreshCw size={14} aria-hidden="true" /> Refresh
          </button>
        </div>
      </div>

      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {loading && !data && <p className="muted">Counting posts…</p>}

      {totals && (
        <>
          <div className="as-summary" data-testid="performance-tiles">
            <div className="as-tile"><span className="as-tile__label">Posted</span><span className="as-tile__value">{totals.posted}</span>
              <span className="as-tile__hint">{postedWithin(data.posted.items, 7)} in the last 7 days · {postedWithin(data.posted.items, 30)} in 30</span></div>
            <div className="as-tile"><span className="as-tile__label">Failed</span><span className="as-tile__value">{totals.failed}</span>
              <span className="as-tile__hint">Retry from Calendar &amp; queue</span></div>
            <div className="as-tile"><span className="as-tile__label">Publishing now</span><span className="as-tile__value">{totals.publishing}</span>
              <span className="as-tile__hint">Accepted, waiting for confirmation</span></div>
            <div className="as-tile"><span className="as-tile__label">Delivery success</span><span className="as-tile__value">{rate == null ? '—' : `${rate}%`}</span>
              <span className="as-tile__hint">posted ÷ (posted + failed){totals.scheduled ? ` · ${totals.scheduled} scheduled` : ''}</span></div>
          </div>

          {anything ? (
            <div className="card">
              <div className="table-wrap">
                <table className="data-table sa-table">
                  <caption className="as-sr-only">Posts per network and status</caption>
                  <thead>
                    <tr><th scope="col">Network</th><th scope="col">Posted</th><th scope="col">Failed</th><th scope="col">Publishing</th><th scope="col">Scheduled</th></tr>
                  </thead>
                  <tbody>
                    {table.rows.map((r) => (
                      <tr key={r.key}>
                        <th scope="row"><span className="sa-netcell"><r.icon size={15} aria-hidden="true" /> {r.label}</span></th>
                        {STATUSES.map((s) => <td key={s}>{r.counts[s]}</td>)}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {table.truncated.length > 0 && (
                <p className="text-sm text-muted sa-pad">Per-network split counts the latest {LIMIT} posts for: {table.truncated.join(', ')}. Totals above are exact.</p>
              )}
            </div>
          ) : (
            <div className="as-empty"><p>No social posts have been sent yet.</p></div>
          )}
        </>
      )}

      <div className="sa-nodata" data-testid="engagement-empty">
        <BarChart3 size={22} aria-hidden="true" />
        <div>
          <h3 className="sa-nodata__title">Reach, engagement and views: no data source connected</h3>
          <p className="text-sm">
            Reach does not store impressions, likes, comments, shares or video views for social posts — social-api.ai is used
            for publishing only, and no analytics endpoint of it is integrated. Showing these needs one of: social-api.ai
            analytics (once its endpoint is confirmed), the YouTube Analytics API, LinkedIn Community Management API, or the
            Meta Graph API page/Instagram insights, each with its own OAuth grant.
          </p>
        </div>
      </div>
    </section>
  );
}
