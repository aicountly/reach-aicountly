import { useState, useEffect, useCallback, Fragment } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { normalizeVideoList, normalizeVideoTotal } from './videoListUtils';

function VideoStatusBadge({ status }) {
  const colorMap = {
    draft: 'badge--neutral',
    ready: 'badge--info',
    accepted: 'badge--success',
    rejected: 'badge--error',
    archived: 'badge--muted',
    converted: 'badge--neutral',
  };
  return (
    <span className={`badge ${colorMap[status] ?? 'badge--neutral'}`}>{status}</span>
  );
}

function VideoScoreBreakdown({ breakdown }) {
  if (!breakdown) return null;
  return (
    <ul className="score-breakdown" role="list">
      {Object.entries(breakdown).map(([dim, score]) => (
        <li key={dim} className="score-breakdown__item">
          <span className="score-breakdown__dim">{dim.replace(/_/g, ' ')}</span>
          <span className="score-breakdown__score">{score}/20</span>
        </li>
      ))}
    </ul>
  );
}

export default function VideoIdeaBacklogPage() {
  const [ideas, setIdeas] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filter, setFilter] = useState('');
  const [expanded, setExpanded] = useState(null);
  const navigate = useNavigate();
  const perPage = 25;

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = { page, per_page: perPage };
    if (filter) params.status = filter;

    api.get('v1/video/ideas', params)
      .then((r) => {
        const rows = normalizeVideoList(r);
        setIdeas(rows);
        setTotal(normalizeVideoTotal(r, rows));
      })
      .catch((e) => {
        if (e?.status === 404) {
          setIdeas([]);
          setTotal(0);
          setError(null);
          return;
        }
        setError(e?.message || 'Failed to load video ideas.');
      })
      .finally(() => setLoading(false));
  }, [page, filter]);

  useEffect(() => { load(); }, [load]);

  const handleAction = async (uuid, action) => {
    try {
      await api.post(`v1/video/ideas/${uuid}/${action}`);
      load();
    } catch (e) {
      alert(e?.message || 'Action failed.');
    }
  };

  const handleConvert = async (uuid) => {
    try {
      const res = await api.post(`v1/video/ideas/${uuid}/convert`);
      const projectUuid = res?.uuid ?? res?.data?.uuid;
      if (projectUuid) navigate(`/video/projects/${projectUuid}`);
      else load();
    } catch (e) {
      alert(e?.message || 'Convert failed.');
    }
  };

  const totalPages = Math.max(1, Math.ceil(total / perPage));

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Video Idea Backlog</h1>
        <p className="page-header__subtitle">AI-scored video ideas awaiting editorial decision</p>
        <div className="page-header__actions">
          <Link to="/video/projects" className="btn btn--primary">View projects</Link>
        </div>
      </div>

      <div className="filter-bar mb-4">
        <select
          value={filter}
          onChange={(e) => { setFilter(e.target.value); setPage(1); }}
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          {['draft', 'ready', 'accepted', 'rejected', 'archived', 'converted'].map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Loading ideas…</p>}

      {!loading && ideas.length === 0 && !error && (
        <p className="muted">No video ideas found. Ideas can be generated from the AI generation panel.</p>
      )}

      {!loading && ideas.length > 0 && (
        <table className="data-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Status</th>
              <th>Score</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {ideas.map((idea) => (
              <Fragment key={idea.uuid ?? idea.id}>
                <tr>
                  <td>
                    <button
                      type="button"
                      className="link-btn"
                      onClick={() => setExpanded(expanded === idea.uuid ? null : idea.uuid)}
                      aria-expanded={expanded === idea.uuid}
                    >
                      {idea.title}
                    </button>
                    {idea.duplicate_of_id && (
                      <span className="badge badge--warning ml-2">Possible duplicate</span>
                    )}
                  </td>
                  <td><VideoStatusBadge status={idea.status} /></td>
                  <td>{idea.score_total != null ? <strong>{idea.score_total}/100</strong> : '—'}</td>
                  <td>
                    {idea.status === 'ready' && (
                      <>
                        <button type="button" className="btn btn--sm btn--success" onClick={() => handleAction(idea.uuid, 'accept')}>Accept</button>
                        <button type="button" className="btn btn--sm btn--error ml-2" onClick={() => handleAction(idea.uuid, 'reject')}>Reject</button>
                      </>
                    )}
                    {idea.status === 'accepted' && (
                      <button type="button" className="btn btn--sm btn--primary" onClick={() => handleConvert(idea.uuid)}>Convert to project</button>
                    )}
                  </td>
                </tr>
                {expanded === idea.uuid && (
                  <tr>
                    <td colSpan={4}>
                      <p className="mb-2">{idea.summary || 'No summary.'}</p>
                      {idea.rationale && <p className="muted mb-2">{idea.rationale}</p>}
                      <VideoScoreBreakdown breakdown={idea.score_breakdown} />
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      )}

      {total > perPage && (
        <div className="pagination mt-4">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="btn btn--sm btn--secondary">Previous</button>
          <span className="pagination__info">Page {page} of {totalPages}</span>
          <button type="button" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)} className="btn btn--sm btn--secondary">Next</button>
        </div>
      )}
    </div>
  );
}
