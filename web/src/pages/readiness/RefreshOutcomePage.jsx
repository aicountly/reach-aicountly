import { useEffect, useState } from 'react';
import { Target } from 'lucide-react';
import { usePermission } from '../../hooks/usePermission';
import {
  listRefreshOutcomes,
  getRefreshOutcome,
  measureRefreshOutcome,
} from '../../services/readinessService.js';

const STATUS_TABS = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'complete', label: 'Complete' },
  { value: 'insufficient_data', label: 'Insufficient data' },
  { value: 'partial', label: 'Partial' },
];

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function statusBadge(status) {
  switch (status) {
    case 'complete':
      return 'badge--success';
    case 'pending':
      return 'badge--info';
    case 'partial':
      return 'badge--warning';
    case 'insufficient_data':
      return 'badge--muted';
    default:
      return 'badge--muted';
  }
}

function confidenceBadge(confidence) {
  switch (confidence) {
    case 'high':
      return 'badge--success';
    case 'medium':
      return 'badge--info';
    case 'low':
      return 'badge--warning';
    default:
      return 'badge--muted';
  }
}

function formatPct(value) {
  if (value == null || Number.isNaN(Number(value))) return '—';
  const n = Number(value);
  const sign = n > 0 ? '+' : '';
  return `${sign}${n.toFixed(1)}%`;
}

function formatMetricName(name) {
  return String(name || '')
    .replace(/^observed_change_/, '')
    .replace(/_/g, ' ');
}

function formatDate(value) {
  if (!value) return '—';
  try {
    return new Date(value).toLocaleDateString();
  } catch {
    return String(value);
  }
}

function changeTone(pct) {
  const n = Number(pct);
  if (!Number.isFinite(n) || n === 0) return undefined;
  return n > 0 ? { color: 'var(--color-success)' } : { color: 'var(--color-danger)' };
}

export default function RefreshOutcomePage() {
  const { has } = usePermission();
  const canMeasure = has('refresh_outcome.trigger');

  const [filter, setFilter] = useState('');
  const [windows, setWindows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedId, setSelectedId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [measuring, setMeasuring] = useState(false);
  const [actionMsg, setActionMsg] = useState(null);
  const [actionError, setActionError] = useState(null);

  const loadWindows = () => {
    setLoading(true);
    setError(null);
    const params = filter ? { measurement_status: filter } : {};
    listRefreshOutcomes(params)
      .then((data) => {
        const rows = normalizeList(data);
        setWindows(rows);
        if (selectedId && !rows.some((r) => Number(r.id) === Number(selectedId))) {
          setSelectedId(null);
          setDetail(null);
        }
      })
      .catch((e) => {
        setError(e.message || 'Failed to load outcome windows');
        setWindows([]);
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadWindows();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filter]);

  const loadDetail = (id) => {
    if (!id) return;
    setSelectedId(id);
    setDetailLoading(true);
    setActionError(null);
    setActionMsg(null);
    getRefreshOutcome(id)
      .then((data) => setDetail(data || null))
      .catch((e) => {
        setActionError(e.message || 'Failed to load outcome detail');
        setDetail(null);
      })
      .finally(() => setDetailLoading(false));
  };

  const handleMeasure = async () => {
    if (!selectedId || !canMeasure) return;
    setMeasuring(true);
    setActionError(null);
    setActionMsg(null);
    try {
      const data = await measureRefreshOutcome(selectedId);
      setDetail(data || null);
      const status = data?.result?.status || data?.window?.measurement_status;
      setActionMsg(
        status === 'complete'
          ? `Measurement complete — ${data?.result?.metrics_recorded ?? 0} observed metrics recorded.`
          : status === 'insufficient_data'
            ? 'Measurement finished with insufficient data for observed comparisons.'
            : `Measurement status: ${status || 'updated'}.`,
      );
      loadWindows();
    } catch (e) {
      setActionError(e.message || 'Unable to run measurement');
    } finally {
      setMeasuring(false);
    }
  };

  const selectedWindow = detail?.window;
  const metrics = Array.isArray(detail?.metrics) ? detail.metrics : [];

  return (
    <div>
      <div className="page-header page-header--stack">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <Target size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} aria-hidden="true" />
          <h1 style={{ margin: 0 }}>Refresh Outcomes</h1>
        </div>
        <p className="page-header__subtitle">
          Observed post-refresh changes relative to the pre-refresh baseline.
          Results represent observational data only — no causal claims are made.
        </p>
      </div>

      <div className="alert alert-warning mb-4">
        <strong>Important:</strong> Outcome data represents observed changes in the
        post-refresh period compared to the pre-refresh baseline. These observations
        are not proof of causation and no revenue is attributed.
      </div>

      <div className="btn-group mb-4" role="tablist" aria-label="Outcome status filter">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value || 'all'}
            type="button"
            role="tab"
            aria-selected={filter === tab.value}
            className={`btn btn--sm ${filter === tab.value ? 'btn--primary' : 'btn--secondary'}`}
            onClick={() => setFilter(tab.value)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}
      {actionError && <div className="alert alert-danger mb-4">{actionError}</div>}
      {actionMsg && <div className="alert alert-success mb-4">{actionMsg}</div>}

      <div className="grid grid-2" style={{ alignItems: 'start' }}>
        <section className="card">
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Outcome windows</h2>
            <button type="button" className="btn btn--sm btn--secondary" onClick={loadWindows}>
              Refresh
            </button>
          </div>

          {loading ? (
            <p className="muted" style={{ margin: 0, padding: '1rem 1.1rem' }}>Loading outcome windows…</p>
          ) : windows.length === 0 ? (
            <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
              <p className="text-sm text-muted" style={{ margin: '0 0 0.5rem' }}>
                No outcome windows{filter ? ` with status “${filter.replace(/_/g, ' ')}”` : ''} yet.
              </p>
              <p className="text-sm text-muted" style={{ margin: 0 }}>
                Windows open when a refresh is published. Measurement runs after the
                28-day post-refresh period ends.
              </p>
            </div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Content</th>
                    <th>Baseline</th>
                    <th>Post window</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {windows.map((w) => {
                    const active = Number(selectedId) === Number(w.id);
                    return (
                      <tr
                        key={w.id}
                        onClick={() => loadDetail(w.id)}
                        style={{
                          cursor: 'pointer',
                          background: active ? 'var(--color-primary-light)' : undefined,
                        }}
                      >
                        <td style={{ fontWeight: 600 }}>
                          Identity #{w.content_identity_id}
                          <div className="text-sm text-muted">Window #{w.id}</div>
                        </td>
                        <td className="text-sm">
                          {formatDate(w.baseline_from)} → {formatDate(w.baseline_to)}
                        </td>
                        <td className="text-sm">
                          {formatDate(w.post_from)} → {formatDate(w.post_to)}
                        </td>
                        <td>
                          <span className={`badge ${statusBadge(w.measurement_status)}`}>
                            {(w.measurement_status || 'unknown').replace(/_/g, ' ')}
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="card">
          <div className="card__header">
            <h2 className="card__title" style={{ margin: 0 }}>Observed changes</h2>
            {selectedId && canMeasure && (
              <button
                type="button"
                className="btn btn--sm btn--primary"
                onClick={handleMeasure}
                disabled={measuring || detailLoading}
              >
                {measuring ? 'Measuring…' : 'Measure now'}
              </button>
            )}
          </div>

          {!selectedId ? (
            <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
              <p className="text-sm text-muted" style={{ margin: 0 }}>
                Select a published refresh outcome window to view its observed measurement.
              </p>
            </div>
          ) : detailLoading ? (
            <p className="muted" style={{ margin: 0, padding: '1rem 1.1rem' }}>Loading measurement…</p>
          ) : !selectedWindow ? (
            <div className="card__body">
              <p className="text-sm text-muted" style={{ margin: 0 }}>Unable to load this outcome window.</p>
            </div>
          ) : (
            <>
              <div className="card__body" style={{ borderBottom: '1px solid var(--color-border)' }}>
                <dl className="definition-list">
                  <dt>Content identity</dt>
                  <dd>#{selectedWindow.content_identity_id}</dd>
                  <dt>Baseline window</dt>
                  <dd className="text-sm">
                    {formatDate(selectedWindow.baseline_from)} → {formatDate(selectedWindow.baseline_to)}
                  </dd>
                  <dt>Post-refresh window</dt>
                  <dd className="text-sm">
                    {formatDate(selectedWindow.post_from)} → {formatDate(selectedWindow.post_to)}
                  </dd>
                  <dt>Status</dt>
                  <dd>
                    <span className={`badge ${statusBadge(selectedWindow.measurement_status)}`}>
                      {(selectedWindow.measurement_status || 'unknown').replace(/_/g, ' ')}
                    </span>
                  </dd>
                </dl>
                <p className="text-sm text-muted" style={{ margin: '0.85rem 0 0' }}>
                  Causal claim: none (observational data only)
                </p>
              </div>

              {metrics.length === 0 ? (
                <div className="card__body" style={{ textAlign: 'center', padding: '1.5rem 1.25rem' }}>
                  <p className="text-sm text-muted" style={{ margin: 0 }}>
                    No observed metrics recorded yet.
                    {selectedWindow.measurement_status === 'pending'
                      ? ' Measurement becomes available after the 28-day post-refresh window ends.'
                      : canMeasure
                        ? ' Use “Measure now” to compare baseline vs post-refresh evidence.'
                        : ''}
                  </p>
                </div>
              ) : (
                <div style={{ overflowX: 'auto' }}>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Domain</th>
                        <th>Observed metric</th>
                        <th style={{ textAlign: 'right' }}>Baseline</th>
                        <th style={{ textAlign: 'right' }}>Post</th>
                        <th style={{ textAlign: 'right' }}>Observed change</th>
                        <th>Confidence</th>
                        <th>Source</th>
                      </tr>
                    </thead>
                    <tbody>
                      {metrics.map((m) => (
                        <tr key={m.id || `${m.metric_domain}-${m.metric_name}`}>
                          <td className="text-sm" style={{ textTransform: 'capitalize' }}>{m.metric_domain}</td>
                          <td style={{ fontWeight: 500, textTransform: 'capitalize' }}>
                            {formatMetricName(m.metric_name)}
                          </td>
                          <td style={{ textAlign: 'right' }} className="text-sm">
                            {m.baseline_value != null ? Number(m.baseline_value).toLocaleString() : '—'}
                          </td>
                          <td style={{ textAlign: 'right' }} className="text-sm">
                            {m.post_value != null ? Number(m.post_value).toLocaleString() : '—'}
                          </td>
                          <td style={{ textAlign: 'right', fontWeight: 600, ...changeTone(m.observed_change_pct) }}>
                            {formatPct(m.observed_change_pct)}
                          </td>
                          <td>
                            <span className={`badge ${confidenceBadge(m.confidence)}`}>
                              {(m.confidence || 'n/a').replace(/_/g, ' ')}
                            </span>
                            <div className="text-sm text-muted">
                              {m.data_points_baseline ?? 0}/{m.data_points_post ?? 0} days
                            </div>
                          </td>
                          <td className="text-sm text-muted">
                            {(m.evidence_source || '—').replace(/_/g, ' ')}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}
        </section>
      </div>
    </div>
  );
}
