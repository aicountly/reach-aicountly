import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { analyticsService } from '../../services/analyticsService';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { DrillPath, useDrillFilters } from '../../components/drill';
import { ROUTES } from '../../constants/routes';

const SOURCES = ['ga4', 'gsc', 'meta', 'linkedin', 'twitter', 'youtube', 'email', 'whatsapp', 'internal'];
const SOURCE_NAMES = {
  external: 'All imported (not internal)', ga4: 'GA4', gsc: 'Search Console', meta: 'Meta', linkedin: 'LinkedIn',
  twitter: 'X / Twitter', youtube: 'YouTube', email: 'Email', whatsapp: 'WhatsApp', internal: 'Internal',
};
const WINDOWS = ['7', '30', '90'];
const LABELS = { source: 'Source', days: 'Captured in the last', from: 'From', to: 'To', id: 'Snapshot #' };
const PAGE_SIZE = 25;

const formatFilter = (key, value) => {
  if (key === 'source') return SOURCE_NAMES[value] || value;
  if (key === 'days') return `${value} days`;
  return value;
};

function when(value) {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString();
}

/** First few scalar metrics as "key: value", for the collapsed row. */
function metricSummary(metrics) {
  if (!metrics || typeof metrics !== 'object') return String(metrics ?? '—');
  const entries = Object.entries(metrics);
  if (entries.length === 0) return '(empty)';
  const shown = entries.slice(0, 4).map(([k, v]) => `${k}: ${v !== null && typeof v === 'object' ? '{…}' : v}`);
  return entries.length > 4 ? `${shown.join(' · ')} · +${entries.length - 4} more` : shown.join(' · ');
}

/**
 * Imported engagement snapshots (reach_analytics_snapshots) — the records the
 * Analytics page's "Engagement imported" tile counts (?source=external&days=30).
 * Filters live in the URL; each row opens to its full metrics payload.
 */
export function AnalyticsSnapshotsPage() {
  const drill = useDrillFilters(['source', 'days', 'from', 'to', 'id']);
  const { filters, params } = drill;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const [state, setState] = useState({ loading: true, error: null, data: null });
  const [expanded, setExpanded] = useState(null);
  const filterKey = JSON.stringify(filters);

  useEffect(() => {
    let cancelled = false;
    setState((s) => ({ ...s, loading: true, error: null }));
    analyticsService.snapshots({ ...filters, page, limit: PAGE_SIZE })
      .then((data) => { if (!cancelled) setState({ loading: false, error: null, data }); })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: e.message, data: null }); });
    return () => { cancelled = true; };
    // filterKey captures every filter value.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterKey, page]);

  const { loading, error, data } = state;
  const items = data?.items || [];
  const bySource = data?.by_source || {};
  const setFilter = (patch) => drill.drill({ ...patch, page: '' });

  const columns = [
    { key: 'captured_at', label: 'Captured', render: (r) => when(r.captured_at) },
    { key: 'source', label: 'Source', render: (r) => SOURCE_NAMES[r.source] || r.source },
    { key: 'metrics', label: 'Metrics', render: (r) => <span className="text-sm">{metricSummary(r.metrics)}</span> },
    { key: 'id', label: '#', render: (r) => <span className="text-muted">{r.id}</span> },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Engagement snapshots</h1>
          <p className="text-sm text-muted">
            <Link to={ROUTES.ANALYTICS}>Analytics</Link> › metrics imported from analytics and channel sources, one row per snapshot.
          </p>
        </div>
      </div>

      <DrillPath drill={drill} rootLabel="All snapshots" labels={LABELS} format={formatFilter} />

      <div className="flex gap-2" style={{ flexWrap: 'wrap', margin: '0.75rem 0' }}>
        <select
          className="input"
          aria-label="Source"
          value={filters.source}
          onChange={(e) => setFilter({ source: e.target.value })}
          style={{ width: 'auto' }}
        >
          <option value="">Any source</option>
          <option value="external">{SOURCE_NAMES.external}</option>
          {SOURCES.map((s) => <option key={s} value={s}>{SOURCE_NAMES[s]}</option>)}
        </select>
        <select
          className="input"
          aria-label="Captured window"
          value={filters.days}
          onChange={(e) => setFilter({ days: e.target.value, from: '', to: '' })}
          style={{ width: 'auto' }}
        >
          <option value="">Any time</option>
          {WINDOWS.map((d) => <option key={d} value={d}>Last {d} days</option>)}
        </select>
      </div>

      {Object.keys(bySource).length > 0 && (
        <div className="flex gap-2" style={{ flexWrap: 'wrap', marginBottom: '0.75rem' }} aria-label="Snapshots by source">
          {Object.entries(bySource).map(([source, n]) => (
            <button
              key={source}
              type="button"
              className={`btn btn-sm ${filters.source === source ? 'btn-primary' : 'btn-secondary'}`}
              onClick={() => setFilter({ source })}
            >
              {SOURCE_NAMES[source] || source} · {Number(n).toLocaleString()}
            </button>
          ))}
        </div>
      )}

      {error && <div className="alert alert-danger">{error}</div>}

      {loading && !data ? (
        <p className="text-sm text-muted">Loading snapshots…</p>
      ) : (
        <>
          <p className="text-xs text-muted" style={{ margin: '0 0 0.5rem' }}>
            {(data?.total ?? 0).toLocaleString()} snapshot{data?.total === 1 ? '' : 's'} · click a row for its full payload
          </p>
          <DataTable
            columns={columns}
            rows={items}
            onRowClick={(r) => setExpanded(expanded === r.id ? null : r.id)}
            rowLabel={(r) => `Snapshot ${r.id} from ${SOURCE_NAMES[r.source] || r.source}`}
            expandedRowId={expanded}
            renderExpanded={(r) => (
              <div style={{ padding: '0.75rem 1rem' }} data-testid={`snapshot-${r.id}`}>
                <div className="text-xs text-muted" style={{ marginBottom: '0.5rem' }}>
                  Snapshot #{r.id} · captured {when(r.captured_at)} · stored {when(r.created_at)}
                </div>
                <pre style={{ margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: '0.8125rem' }}>
                  {JSON.stringify(r.metrics, null, 2)}
                </pre>
              </div>
            )}
            emptyMessage={
              Object.values(filters).some(Boolean)
                ? 'No snapshots match these filters.'
                : 'No engagement snapshots have been imported yet.'
            }
          />
          <Pagination page={page} limit={PAGE_SIZE} total={data?.total ?? 0} onPage={(p) => drill.set({ page: p > 1 ? p : '' })} />
        </>
      )}
    </div>
  );
}

export default AnalyticsSnapshotsPage;
