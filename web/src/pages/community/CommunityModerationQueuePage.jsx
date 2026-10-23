import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { DrillLink, useUrlFilter } from '../../components/drill';
import { normalizeCommunityList, normalizeCommunityMeta } from './communityListUtils';
import { formatDate } from '../../utils/formatDate';

const FINDING_CLASS = {
  spam: 'badge--error',
  prompt_injection: 'badge--error',
  legal_risk: 'badge--error',
  pii_detected: 'badge--warning',
  malicious_html: 'badge--error',
  unsafe_link: 'badge--warning',
  ungrounded_claim: 'badge--warning',
};

const SEVERITIES = ['info', 'warning', 'error', 'critical'];

export default function CommunityModerationQueuePage() {
  // Filters live in the URL so a link (or a shared view) opens the same slice.
  const [findingType, setFindingType] = useUrlFilter('finding_type');
  const [severity, setSeverity]       = useUrlFilter('severity');
  const [findings, setFindings]   = useState([]);
  const [total, setTotal]         = useState(0);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [page, setPage]           = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [actionMsg, setActionMsg] = useState('');

  // A new filter is a new list: start it from page 1.
  const filterKey = `${findingType}|${severity}`;
  const [lastFilterKey, setLastFilterKey] = useState(filterKey);
  if (filterKey !== lastFilterKey) {
    setLastFilterKey(filterKey);
    setPage(1);
  }

  function load() {
    setLoading(true);
    api.getPage('v1/community/moderation/queue', {
      page,
      finding_type: findingType || undefined,
      severity: severity || undefined,
    })
      .then(r => {
        const list = normalizeCommunityList(r);
        const meta = normalizeCommunityMeta(r);
        setFindings(list);
        setTotal(meta.total ?? list.length);
        setTotalPages(meta.last_page ?? 1);
      })
      .catch(e => setError(e.message))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [page, findingType, severity]); // eslint-disable-line

  async function handleResolve(id) {
    const note = prompt('Resolution note:') || '';
    try {
      await api.post(`v1/community/moderation/${id}/resolve`, { resolution_note: note });
      setActionMsg(`Finding #${id} resolved.`);
      load();
    } catch (e) {
      setActionMsg('Error: ' + e.message);
    }
  }

  async function handleEscalate(id) {
    const note = prompt('Escalation note:') || '';
    try {
      await api.post(`v1/community/moderation/${id}/escalate`, { note });
      setActionMsg(`Finding #${id} escalated.`);
      load();
    } catch (e) {
      setActionMsg('Error: ' + e.message);
    }
  }

  if (loading) return <p className="muted">Loading moderation queue…</p>;
  if (error) return <p className="text-error">{error}</p>;

  return (
    <div>
      <div className="page-header">
        <h1>Moderation Queue</h1>
        <span className="badge badge--warning">{total} open</span>
      </div>

      <div className="toolbar mb-3">
        <label className="toolbar__label">
          Severity
          <select value={severity} onChange={e => setSeverity(e.target.value)} className="form-select form-select--sm">
            <option value="">All</option>
            {SEVERITIES.map(s => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
          </select>
        </label>
        {findingType && (
          <button type="button" className="badge badge-secondary" onClick={() => setFindingType('')}
                  aria-label={`Clear finding type ${findingType} filter`}>
            Type: {findingType} ✕
          </button>
        )}
      </div>

      {actionMsg && <div className="alert alert--info mb-3">{actionMsg}</div>}

      <table className="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Finding type</th>
            <th>Severity</th>
            <th>Answer version</th>
            <th>Detail</th>
            <th>Flagged</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {findings.length === 0 ? (
            <tr><td colSpan={7} className="muted">Queue is empty.</td></tr>
          ) : findings.map(f => (
            <tr key={f.id}>
              <td>{f.id}</td>
              <td>
                <DrillLink
                  onClick={f.finding_type && f.finding_type !== findingType ? () => setFindingType(f.finding_type) : undefined}
                  title="Only this finding type"
                >
                  <span className={`badge ${FINDING_CLASS[f.finding_type] ?? 'badge--neutral'}`}>{f.finding_type}</span>
                </DrillLink>
              </td>
              <td>
                <DrillLink onClick={f.severity && f.severity !== severity ? () => setSeverity(f.severity) : undefined} title="Only this severity">
                  {f.severity ?? '—'}
                </DrillLink>
              </td>
              <td>
                {/* The flagged answer opens in its editor. */}
                {f.answer_uuid
                  ? <Link to={`/community/answers/${f.answer_uuid}`}>{f.answer_version_id} {f.version_number ? `(v${f.version_number})` : ''}</Link>
                  : <>{f.answer_version_id ?? '—'} {f.version_number ? `(v${f.version_number})` : ''}</>}
              </td>
              <td className="td--truncate">{f.details ?? '—'}</td>
              <td>{f.created_at ? formatDate(f.created_at) : '—'}</td>
              <td>
                <button className="btn btn--sm btn--success mr-1" onClick={() => handleResolve(f.id)}>Resolve</button>
                <button className="btn btn--sm btn--ghost" onClick={() => handleEscalate(f.id)}>Escalate</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {totalPages > 1 && (
        <div className="pagination">
          <button className="btn btn--sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Prev</button>
          <span className="pagination__info">Page {page} / {totalPages}</span>
          <button className="btn btn--sm" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>Next</button>
        </div>
      )}
    </div>
  );
}
