import { useCallback, useEffect, useState } from 'react';
import { blogCommandCentreService } from '../../../services/blogCommandCentreService';
import { Loader } from '../../../components/common/Loader';
import { Alert } from '../../../components/common/Alert';
import { RoadmapEmptyState, TrendCell } from './roadmapShared';
import { formatScore } from './roadmapFormat';
import { formatDate } from '../../../utils/formatDate';

const STATUS_OPTIONS = ['', 'candidate', 'scored', 'planned', 'in_production', 'rejected'];
const STREAM_OPTIONS = ['', 'marketing', 'product', 'problem_to_product'];

export function RoadmapCandidatesPage() {
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState('');
  const [stream, setStream] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    const params = {};
    if (status) params.status = status;
    if (stream) params.portfolio_stream = stream;

    blogCommandCentreService.getRoadmapCandidates(params)
      .then((data) => {
        setItems(data?.items ?? []);
        setTotal(data?.total ?? 0);
        setError('');
      })
      .catch((err) => {
        setError(err?.message || 'Unable to load topic candidates.');
        setItems([]);
        setTotal(0);
      })
      .finally(() => setLoading(false));
  }, [status, stream]);

  useEffect(load, [load]);

  return (
    <div>
      <div className="page-toolbar" style={{ display: 'flex', gap: '0.75rem', marginBottom: '1rem', flexWrap: 'wrap' }}>
        <label>
          <span className="text-muted" style={{ marginRight: '0.5rem' }}>Status</span>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            {STATUS_OPTIONS.map((option) => (
              <option key={option || 'all'} value={option}>{option || 'All statuses'}</option>
            ))}
          </select>
        </label>
        <label>
          <span className="text-muted" style={{ marginRight: '0.5rem' }}>Stream</span>
          <select value={stream} onChange={(e) => setStream(e.target.value)}>
            {STREAM_OPTIONS.map((option) => (
              <option key={option || 'all'} value={option}>{option ? option.replace(/_/g, ' ') : 'All streams'}</option>
            ))}
          </select>
        </label>
        <button type="button" className="btn btn--secondary" onClick={load}>Refresh</button>
      </div>

      {error && <Alert variant="error">{error}</Alert>}

      {loading ? <Loader label="Loading candidates…" /> : items.length === 0 ? (
        <RoadmapEmptyState
          title="No topic candidates yet"
          detail="Candidates appear once discovery work blocks run, or when an editor adds them manually."
        />
      ) : (
        <>
          <p className="text-muted">{total} candidate{total === 1 ? '' : 's'}</p>
          <table className="data-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Stream</th>
                <th>Status</th>
                <th>Score</th>
                <th>7d</th>
                <th>28d</th>
                <th>Scored for</th>
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.id}>
                  <td>
                    {row.title}
                    {row.is_human_pinned && <span className="badge badge--info" style={{ marginLeft: '0.5rem' }}>Pinned</span>}
                    {row.is_locked && <span className="badge badge--muted" style={{ marginLeft: '0.5rem' }}>Locked</span>}
                  </td>
                  <td>{row.portfolio_stream ? row.portfolio_stream.replace(/_/g, ' ') : '—'}</td>
                  <td>{row.status}</td>
                  <td>{formatScore(row.total_score)}</td>
                  <td><TrendCell value={row.trend_7d} /></td>
                  <td><TrendCell value={row.trend_28d} /></td>
                  <td>{formatDate(row.scored_for_date)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </div>
  );
}
