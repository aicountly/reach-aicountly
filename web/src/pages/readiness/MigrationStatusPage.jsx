import { useEffect, useState } from 'react';
import { DataTable } from '../../components/common/DataTable';
import { useUrlFilter } from '../../components/drill';
import { formatDateTime } from '../../utils/formatDate';
import { getMigrationStatus } from '../../services/readinessService.js';

const STATES = ['applied', 'pending', 'orphaned'];
const STATE_BADGE = { applied: 'badge--success', pending: 'badge--warning', orphaned: 'badge--danger' };
const STATE_LABEL = { applied: 'Applied', pending: 'Pending', orphaned: 'Orphaned (file missing)' };

/**
 * Every database migration file against the applied history: what has run
 * (batch, time), what is still pending, and history rows whose file is gone.
 * Each count filters the list to those migrations (?state=).
 */
export default function MigrationStatusPage() {
  const [stateFilter, setStateFilter] = useUrlFilter('state');
  const [data, setData] = useState({ loading: true, error: null, summary: null, items: [] });

  useEffect(() => {
    let cancelled = false;
    setData((d) => ({ ...d, loading: true, error: null }));
    getMigrationStatus(stateFilter ? { state: stateFilter } : {})
      .then((d) => { if (!cancelled) setData({ loading: false, error: null, summary: d?.summary || null, items: d?.items || [] }); })
      .catch((e) => { if (!cancelled) setData({ loading: false, error: e.message || 'Failed to load migration status', summary: null, items: [] }); });
    return () => { cancelled = true; };
  }, [stateFilter]);

  const { loading, error, summary, items } = data;
  const columns = [
    { key: 'version', label: 'Version', render: (r) => <code className="text-xs">{r.version}</code> },
    { key: 'name', label: 'Migration' },
    { key: 'state', label: 'State', render: (r) => <span className={`badge ${STATE_BADGE[r.state] ?? 'badge--neutral'}`}>{r.state}</span>,
      drill: (r) => (r.state !== stateFilter ? { onClick: () => setStateFilter(r.state), title: 'Only this state' } : null) },
    { key: 'batch', label: 'Batch', render: (r) => r.batch ?? '—' },
    { key: 'applied_at', label: 'Applied', render: (r) => (r.applied_at ? formatDateTime(r.applied_at) : '—') },
  ];

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Migration Status</h1>
        <p className="page-header__subtitle">
          Database migration files compared with the applied migration history.
          A pending migration must be run (<code>php spark migrate</code>) before release.
        </p>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}

      {summary && (
        <div className="stat-grid mb-4">
          {STATES.map((s) => {
            const n = summary[s] ?? 0;
            const body = (
              <>
                <div className="stat-card__value" style={s === 'pending' && n > 0 ? { color: 'var(--color-warning)' } : undefined}>{n}</div>
                <div className="stat-card__label">{STATE_LABEL[s]}</div>
              </>
            );
            return n > 0 && s !== stateFilter ? (
              <button key={s} type="button" className="stat-card stat-tile" onClick={() => setStateFilter(s)}
                      aria-label={`${STATE_LABEL[s]}: ${n} — list them`}>{body}</button>
            ) : <div key={s} className="stat-card">{body}</div>;
          })}
          <div className="stat-card">
            <div className="stat-card__value">{summary.last_batch ?? '—'}</div>
            <div className="stat-card__label">Last batch{summary.last_applied_at ? ` · ${formatDateTime(summary.last_applied_at)}` : ''}</div>
          </div>
        </div>
      )}

      {stateFilter && (
        <div className="mb-4">
          <button type="button" className="badge badge--neutral" onClick={() => setStateFilter('')} aria-label={`Clear ${stateFilter} filter`}>
            {STATE_LABEL[stateFilter] || stateFilter} ✕
          </button>
        </div>
      )}

      <div className="card">
        {loading ? (
          <div className="card__body"><p className="muted" style={{ margin: 0 }}>Loading migrations…</p></div>
        ) : (
          <DataTable
            columns={columns}
            rows={items}
            rowKey={(r) => `${r.version}-${r.name}`}
            emptyMessage={stateFilter ? `No ${stateFilter} migrations.` : 'No migration files found.'}
          />
        )}
      </div>
    </div>
  );
}
