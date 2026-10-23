import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';
import { usePermission } from '../../hooks/usePermission';
import { formatDateTime } from '../../utils/formatDate';
import { useUrlFilter } from '../../components/drill';

const STATUS_LABELS = {
  pending_vetting: 'Pending vetting',
  rejected: 'Rejected',
  approved: 'Approved',
  published: 'Published',
  failed: 'Failed',
  superseded: 'Superseded',
};

const STATUS_CLASS = {
  pending_vetting: 'badge--info',
  rejected: 'badge--error',
  approved: 'badge--info',
  published: 'badge--success',
  failed: 'badge--error',
  superseded: 'badge--neutral',
};

const STATUS_FILTERS = [
  { key: '', label: 'All' },
  { key: 'published', label: 'Published' },
  { key: 'pending_vetting', label: 'Pending vetting' },
  { key: 'failed', label: 'Failed' },
  { key: 'rejected', label: 'Rejected' },
  { key: 'superseded', label: 'Superseded' },
];

const KINDS = {
  release_note: {
    endpoint: 'v1/publishing/release-notes',
    title: 'Release notes',
    subtitle:
      "What's new notes written by the release-notes routine from each product's changes. "
      + 'Gemini vets every note; approved notes are published to aicountly.com automatically under the Reach System Bot.',
    empty: 'No release notes submitted yet. The routine runs daily at 21:00 IST.',
    noun: 'note',
  },
  workflow_guide: {
    endpoint: 'v1/publishing/workflow-guides',
    title: 'Workflow guides',
    subtitle:
      'Guides on aicountly.com/guides refreshed weekly by the workflow-guides routine from the product screens. '
      + 'Gemini vets every guide; approved guides are published automatically under the Reach System Bot.',
    empty: 'No workflow guides submitted yet. The routine runs every Monday at 06:00 IST.',
    noun: 'guide',
  },
};

function asList(r) {
  if (Array.isArray(r)) return r;
  if (Array.isArray(r?.items)) return r.items;
  if (Array.isArray(r?.data)) return r.data;
  return [];
}

function Reasons({ item }) {
  const reasons = Array.isArray(item.reasons) ? item.reasons : [];
  const error = item.last_error;
  if (reasons.length === 0 && !error) return <span className="muted">—</span>;
  return (
    <div className="text-sm">
      {reasons.length > 0 && (
        <ul style={{ margin: 0, paddingLeft: 16 }}>
          {reasons.map((r, i) => <li key={i}>{r}</li>)}
        </ul>
      )}
      {error && (
        <p className="text-error" style={{ margin: reasons.length ? '4px 0 0' : 0 }} title={error}>
          {item.error_category && <code>{item.error_category}</code>}{item.error_category ? ' — ' : ''}
          {error.length > 140 ? `${error.slice(0, 140)}…` : error}
        </p>
      )}
    </div>
  );
}

/**
 * Publishing → Release notes / Workflow guides. One row per routine
 * submission: status, the vetting run that decided it and its reasons, the
 * live link once published, and Retry for submissions not live yet (a
 * rejection is final and has no Retry).
 *
 * @param {{ kind: 'release_note' | 'workflow_guide' }} props
 */
export default function StructuredSubmissionsPage({ kind }) {
  const cfg = KINDS[kind];
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  // In the URL so a failure count elsewhere can open ?status=failed.
  const [status, setStatus] = useUrlFilter('status');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [retryingId, setRetryingId] = useState(null);
  const { has } = usePermission();
  const canPublish = has('publishing.publish');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get(cfg.endpoint, status ? { status } : undefined)
      .then((r) => {
        const list = asList(r);
        setItems(list);
        setTotal(typeof r?.total === 'number' ? r.total : list.length);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [cfg.endpoint, status]);

  useEffect(() => { load(); }, [load]);

  const retry = async (item) => {
    setRetryingId(item.id);
    setNotice(null);
    setError(null);
    try {
      const r = await api.post(`${cfg.endpoint}/${item.id}/retry`);
      const now = r?.status ? (STATUS_LABELS[r.status] ?? r.status) : 'updated';
      setNotice(`#${item.id}: ${now}${r?.public_url ? ` — ${r.public_url}` : ''}`);
      load();
    } catch (e) {
      setError(`Retry failed for #${item.id}: ${e.message}`);
    } finally {
      setRetryingId(null);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>{cfg.title}</h1>
          <p className="page-header__subtitle">{cfg.subtitle}</p>
        </div>
      </div>

      <div className="filter-bar" style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginBottom: 16 }}>
        <label>
          <span className="text-sm text-muted" style={{ display: 'block', marginBottom: 4 }}>Status</span>
          <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)}>
            {status && !STATUS_FILTERS.some((f) => f.key === status) && (
              <option value={status}>{STATUS_LABELS[status] ?? status}</option>
            )}
            {STATUS_FILTERS.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
          </select>
        </label>
        <div style={{ alignSelf: 'flex-end' }}>
          <button type="button" className="btn btn--sm btn--secondary" onClick={load}>Refresh</button>
        </div>
      </div>

      {error && <p className="text-error">{error}</p>}
      {notice && <p className="text-success">{notice}</p>}

      {loading ? <p className="muted">Loading {cfg.title.toLowerCase()}…</p> : items.length === 0 ? (
        <div className="card">
          <div className="card__body">
            <p className="muted" style={{ margin: 0, padding: 0 }}>
              {status ? `No ${cfg.noun}s with this status.` : cfg.empty}
            </p>
          </div>
        </div>
      ) : (
        <>
          <p className="text-sm text-muted" style={{ marginBottom: 8 }}>{total} {cfg.noun}{total === 1 ? '' : 's'}</p>
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Product</th>
                  {kind === 'release_note' && <th>Version</th>}
                  <th>Title</th>
                  <th>Status</th>
                  <th>Vetting</th>
                  <th>Reasons</th>
                  <th>Public link</th>
                  <th>Updated</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.id}</td>
                    <td>{item.product_name ?? item.product_slug}</td>
                    {kind === 'release_note' && <td><code>{item.version}</code></td>}
                    <td>{item.title}</td>
                    <td>
                      <span className={`badge ${STATUS_CLASS[item.status] ?? 'badge--neutral'}`}>
                        {STATUS_LABELS[item.status] ?? item.status}
                      </span>
                      {item.status === 'failed' && item.next_attempt_at && (
                        <div className="text-sm text-muted">Next try {formatDateTime(item.next_attempt_at)}</div>
                      )}
                    </td>
                    <td className="text-sm">
                      {item.vetting_decision ? <code>{item.vetting_decision}</code> : <span className="muted">—</span>}
                      {item.vetting_run_id ? <div className="text-muted">run #{item.vetting_run_id}</div> : null}
                      {item.approved_by ? <div className="text-muted">by {item.approved_by}</div> : null}
                    </td>
                    <td style={{ maxWidth: 320 }}><Reasons item={item} /></td>
                    <td>
                      {item.public_url
                        ? <a href={item.public_url} target="_blank" rel="noopener noreferrer">{item.slug}</a>
                        : <span className="muted">{item.slug}</span>}
                    </td>
                    <td>{formatDateTime(item.updated_at)}</td>
                    <td>
                      {canPublish && item.retryable && (
                        <button
                          type="button"
                          className="btn btn--sm btn--primary"
                          disabled={retryingId === item.id}
                          onClick={() => retry(item)}
                        >
                          {retryingId === item.id ? 'Retrying…' : 'Retry'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
