import { useCallback, useEffect, useState } from 'react';
import { Search, RefreshCw, Activity, DownloadCloud, History, Link2Off } from 'lucide-react';
import {
  listSearchMetrics,
  getSearchConsoleConfig,
  listSearchConnections,
  checkSearchConnection,
  ingestSearchConnection,
  backfillSearchConnection,
  listSearchIngestionRuns,
  listUnmappedSearchUrls,
  syncContentIdentities,
} from '../../services/intelligenceService.js';
import { formatTime } from '../../utils/formatDate';

const DEFAULT_BACKFILL_DAYS = 90;

function asList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.rows)) return payload.rows;
  return [];
}

function pct(value) {
  if (value == null) return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return `${(n <= 1 ? n * 100 : n).toFixed(1)}%`;
}

function num(value) {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString() : '—';
}

/**
 * Maps the connector's configuration state onto a badge. `configured` only
 * means the credentials parse — it is deliberately distinct from a health
 * check, which is the first thing that actually contacts Google.
 */
function statusTone(status) {
  if (status === 'configured' || status === 'healthy') return 'var(--color-success)';
  if (status === 'disabled' || status === 'mock') return 'var(--color-warning)';
  return 'var(--color-danger)';
}

export default function SearchIntelligencePage() {
  const [config, setConfig] = useState(null);
  const [connections, setConnections] = useState([]);
  const [totals, setTotals] = useState(null);
  const [queries, setQueries] = useState([]);
  const [runs, setRuns] = useState([]);
  const [unmapped, setUnmapped] = useState([]);

  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);

    Promise.allSettled([
      getSearchConsoleConfig(),
      listSearchConnections(),
      listSearchMetrics({ dimension: 'query', limit: 25 }),
      listSearchIngestionRuns({ limit: 10 }),
      listUnmappedSearchUrls(),
    ])
      .then(([cfg, conns, metrics, runList, unmappedList]) => {
        if (cfg.status === 'fulfilled') setConfig(cfg.value);
        else setError(cfg.reason?.message || 'Failed to read connector configuration');

        setConnections(conns.status === 'fulfilled' ? asList(conns.value) : []);
        setRuns(runList.status === 'fulfilled' ? asList(runList.value) : []);
        setUnmapped(unmappedList.status === 'fulfilled' ? asList(unmappedList.value) : []);

        if (metrics.status === 'fulfilled') {
          setTotals(metrics.value?.totals ?? null);
          setQueries(asList(metrics.value?.rows ?? metrics.value));
        }
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const primary = connections.find((c) => c.enabled) || connections[0] || null;

  const runAction = async (key, fn, describe) => {
    setBusy(key);
    setError(null);
    setNotice(null);
    try {
      const result = await fn();
      setNotice(result?.message || describe(result));
      load();
    } catch (e) {
      setError(e.message || 'Action failed');
    } finally {
      setBusy(null);
    }
  };

  const configured = config?.status === 'configured';

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
          <Search size={26} style={{ color: 'var(--color-info)' }} />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Search Console Intelligence</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Live Google Search Console performance, ingested into Reach
            </p>
          </div>
        </div>
        <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading || busy}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {notice && <div className="alert alert-success">{notice}</div>}

      {/* Connector state — the honest answer to "why is this empty?" */}
      <div className="card" style={{ marginBottom: '1.25rem' }}>
        <div className="card-body">
          <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: 0 }}>Connector</h2>
            {config && (
              <span className="text-xs" style={{ fontWeight: 700, color: statusTone(config.status) }}>
                {String(config.status || 'unknown').toUpperCase()}
              </span>
            )}
          </div>

          {loading && !config && <p className="text-sm text-muted" style={{ marginBottom: 0 }}>Checking connector…</p>}

          {config && (
            <>
              <p className="text-sm" style={{ margin: '0.5rem 0 0.35rem' }}>{config.detail}</p>
              {config.next_step && (
                <p className="text-sm text-muted" style={{ margin: 0 }}>
                  <strong>Next:</strong> {config.next_step}
                </p>
              )}
              <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '0.5rem 1.25rem', margin: '0.85rem 0 0' }}>
                <div>
                  <dt className="text-xs text-secondary">Site property</dt>
                  <dd style={{ margin: 0 }}>{config.site_property || '—'}</dd>
                </div>
                <div>
                  <dt className="text-xs text-secondary">Service account</dt>
                  <dd style={{ margin: 0, wordBreak: 'break-all' }}>{config.client_email || '—'}</dd>
                </div>
                <div>
                  <dt className="text-xs text-secondary">Data lag</dt>
                  <dd style={{ margin: 0 }}>{config.capabilities?.data_lag_days ?? '—'} day(s)</dd>
                </div>
                <div>
                  <dt className="text-xs text-secondary">Blog Command Centre card</dt>
                  <dd style={{ margin: 0 }}>{config.blog_card_enabled ? 'Enabled' : 'Disabled'}</dd>
                </div>
              </dl>
              {config.is_mock && (
                <div className="alert alert-warning" style={{ margin: '0.85rem 0 0' }}>
                  The mock connector is active — every figure below is simulated. Set SEARCH_CONSOLE_USE_MOCK=false for real data.
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* Operations */}
      <div className="card" style={{ marginBottom: '1.25rem' }}>
        <div className="card-body">
          <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.35rem' }}>Operations</h2>
          {!primary ? (
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No Search Console connection is registered yet. Add one under <strong>Intelligence → Connectors</strong>,
              or run <code>php spark reach:search-console register</code> on the server to create it from the environment.
            </p>
          ) : (
            <>
              <p className="text-sm text-muted" style={{ margin: '0 0 0.85rem' }}>
                {primary.display_name} — {primary.site_property}
                {primary.last_successful_ingest && <> · last ingest {formatTime(primary.last_successful_ingest)}</>}
              </p>
              <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  disabled={!!busy}
                  onClick={() => runAction('health', () => checkSearchConnection(primary.id),
                    (r) => (r?.healthy ? `Healthy (${r.latency_ms}ms).` : `Failing: ${r?.error || 'unknown error'}`))}
                >
                  <Activity size={14} /> {busy === 'health' ? 'Checking…' : 'Health check'}
                </button>
                <button
                  type="button"
                  className="btn btn-primary btn-sm"
                  disabled={!!busy || !configured}
                  title={configured ? undefined : 'Fix the connector configuration first'}
                  onClick={() => runAction('ingest', () => ingestSearchConnection(primary.id),
                    (r) => `Ingested ${r?.ingested ?? 0} row(s).`)}
                >
                  <DownloadCloud size={14} /> {busy === 'ingest' ? 'Ingesting…' : 'Ingest now'}
                </button>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  disabled={!!busy || !configured}
                  onClick={() => runAction('backfill', () => backfillSearchConnection(primary.id, DEFAULT_BACKFILL_DAYS),
                    () => `Backfill of ${DEFAULT_BACKFILL_DAYS} days started.`)}
                >
                  <History size={14} /> {busy === 'backfill' ? 'Starting…' : `Backfill ${DEFAULT_BACKFILL_DAYS} days`}
                </button>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  disabled={!!busy}
                  onClick={() => runAction('identities', () => syncContentIdentities(),
                    (r) => `${r?.created ?? 0} identity/identities created.`)}
                >
                  <Link2Off size={14} /> {busy === 'identities' ? 'Syncing…' : 'Sync content identities'}
                </button>
              </div>
            </>
          )}
        </div>
      </div>

      {/* Totals over the ingested facts */}
      <div className="grid grid-3" style={{ marginBottom: '1.25rem' }}>
        {[
          { label: 'Clicks (28d)', value: num(totals?.clicks) },
          { label: 'Impressions (28d)', value: num(totals?.impressions) },
          { label: 'Avg CTR', value: pct(totals?.ctr) },
          { label: 'Avg Position', value: totals?.avg_position == null ? '—' : Number(totals.avg_position).toFixed(1) },
          { label: 'Queries', value: num(totals?.queries) },
          { label: 'Freshest day', value: totals?.latest_metric_date || '—' },
        ].map((s) => (
          <div key={s.label} className="stat-tile">
            <div className="stat-tile__label">{s.label}</div>
            <div className="stat-tile__value">{loading ? '…' : s.value}</div>
          </div>
        ))}
      </div>

      {!loading && (totals?.rows ?? 0) === 0 && (
        <div className="alert alert-info">
          No search facts have been ingested yet, so every figure above is a true zero rather than a placeholder.
          {configured
            ? ' Use “Ingest now” above — Search Console finalises data on a 2–3 day lag, so the newest days will always be absent.'
            : ' Fix the connector configuration first.'}
        </div>
      )}

      {queries.length > 0 && (
        <div className="card" style={{ marginBottom: '1.25rem' }}>
          <div className="card-body">
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.75rem' }}>Top queries (28 days)</h2>
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
                  {queries.map((row, i) => (
                    <tr key={row.dimension_value || i}>
                      <td style={{ fontWeight: 500 }}>{row.dimension_value || '—'}</td>
                      <td style={{ textAlign: 'right' }}>{num(row.clicks)}</td>
                      <td style={{ textAlign: 'right' }}>{num(row.impressions)}</td>
                      <td style={{ textAlign: 'right' }}>{pct(row.ctr)}</td>
                      <td style={{ textAlign: 'right', fontWeight: 600 }}>
                        {row.avg_position == null ? '—' : Number(row.avg_position).toFixed(1)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {runs.length > 0 && (
        <div className="card" style={{ marginBottom: '1.25rem' }}>
          <div className="card-body">
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.75rem' }}>Recent ingestion runs</h2>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Started</th>
                    <th>Type</th>
                    <th>Window</th>
                    <th>Status</th>
                    <th style={{ textAlign: 'right' }}>Ingested</th>
                    <th style={{ textAlign: 'right' }}>Skipped</th>
                  </tr>
                </thead>
                <tbody>
                  {runs.map((run) => (
                    <tr key={run.id}>
                      <td>{formatTime(run.started_at)}</td>
                      <td>{run.run_type}</td>
                      <td className="text-sm text-muted">{run.date_from} → {run.date_to}</td>
                      <td style={{ color: run.status === 'failed' ? 'var(--color-danger)' : undefined }}>
                        {run.status}
                        {run.error_message && <div className="text-xs text-muted">{run.error_message}</div>}
                      </td>
                      <td style={{ textAlign: 'right' }}>{num(run.rows_ingested)}</td>
                      <td style={{ textAlign: 'right' }}>{num(run.rows_skipped)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {unmapped.length > 0 && (
        <div className="card">
          <div className="card-body">
            <h2 style={{ fontSize: '1rem', fontWeight: 650, margin: '0 0 0.35rem' }}>Unmapped URLs</h2>
            <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
              Search Console reports on these URLs but no Reach content claims them, so their figures are not stored.
              Sync content identities above, or check that the published canonical URL matches the live page.
            </p>
            <ul className="text-sm" style={{ margin: 0, paddingLeft: '1.1rem' }}>
              {unmapped.slice(0, 25).map((finding) => (
                <li key={finding.id} style={{ wordBreak: 'break-all' }}>{finding.unmapped_url}</li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </div>
  );
}
