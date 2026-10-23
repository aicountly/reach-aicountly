import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { blogCommandCentreService } from '../../../services/blogCommandCentreService';
import { Loader } from '../../../components/common/Loader';
import { Alert } from '../../../components/common/Alert';
import { RoadmapEmptyState, TrendCell } from './roadmapShared';
import { formatScore } from './roadmapFormat';
import { formatDate } from '../../../utils/formatDate';
import { ROUTES } from '../../../constants/routes';
import { useUrlFilter } from '../../../components/drill';
import { Pagination } from '../../../components/common/Pagination';

const PAGE_SIZE = 25;
const articleHref = (id) => ROUTES.CONTENT_DETAIL.replace(':id', id);

const STATUS_OPTIONS = ['', 'candidate', 'scored', 'roadmap_selected', 'rejected'];

/** Postgres booleans arrive as "t"/"f" from older API builds; "f" is truthy in JS. */
function isTrue(value) {
  return value === true || value === 't' || value === 'true' || value === 1 || value === '1';
}

function writerCell(row) {
  if (row.content_item_id) {
    return (
      <Link to={articleHref(row.content_item_id)} onClick={(e) => e.stopPropagation()}>
        Article #{row.content_item_id}
      </Link>
    );
  }
  if (row.status === 'roadmap_selected') return 'In the blog routine queue';
  return '—';
}
const STREAM_OPTIONS = ['', 'marketing', 'product', 'problem_to_product'];

export function RoadmapCandidatesPage() {
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  // In the URL so plan tiles (?status=roadmap_selected) land on their rows.
  const [status, setStatus] = useUrlFilter('status');
  const [stream, setStream] = useUrlFilter('portfolio_stream');
  // Paging belongs to one filter set; a new filter starts again at page 1.
  const filterKey = `${status}|${stream}`;
  const [paging, setPaging] = useState({ key: filterKey, page: 1 });
  const page = paging.key === filterKey ? paging.page : 1;
  const nav = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    const params = { limit: PAGE_SIZE };
    if (page > 1) params.page = page;
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
  }, [status, stream, page]);

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
            {status && !STATUS_OPTIONS.includes(status) && <option value={status}>{status}</option>}
          </select>
        </label>
        <label>
          <span className="text-muted" style={{ marginRight: '0.5rem' }}>Stream</span>
          <select value={stream} onChange={(e) => setStream(e.target.value)}>
            {STREAM_OPTIONS.map((option) => (
              <option key={option || 'all'} value={option}>{option ? option.replace(/_/g, ' ') : 'All streams'}</option>
            ))}
            {stream && !STREAM_OPTIONS.includes(stream) && <option value={stream}>{stream.replace(/_/g, ' ')}</option>}
          </select>
        </label>
        <button type="button" className="btn btn--secondary" onClick={load}>Refresh</button>
      </div>

      {error && <Alert variant="error">{error}</Alert>}

      {loading ? <Loader label="Loading candidates…" /> : items.length === 0 ? (
        status || stream ? (
          <RoadmapEmptyState
            title="No topic candidates match this filter"
            detail="Clear the status or stream filter to see every candidate."
          />
        ) : (
          <RoadmapEmptyState
            title="No topic candidates yet"
            detail="Candidates appear once discovery work blocks run, or when an editor adds them manually."
          />
        )
      ) : (
        <>
          <p className="text-muted">
            {total} candidate{total === 1 ? '' : 's'}. Rescored every night; selected topics go first in the blog
            routine&apos;s queue (<code>v1/automation/content-base/next</code>). 7d / 28d = change against the score
            7 / 28 days earlier — empty until a candidate has that much score history.
          </p>
          <table className="data-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Stream</th>
                <th>Status</th>
                <th>Score</th>
                <th title="Score change against 7 days earlier">7d</th>
                <th title="Score change against 28 days earlier">28d</th>
                <th>Scored for</th>
                <th>Writer</th>
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr
                  key={row.id}
                  // A candidate the routine has written opens its article.
                  className={row.content_item_id ? 'is-drillable' : undefined}
                  onClick={row.content_item_id ? () => nav(articleHref(row.content_item_id)) : undefined}
                >
                  <td>
                    {row.title}
                    {isTrue(row.is_human_pinned) && <span className="badge badge--info" style={{ marginLeft: '0.5rem' }}>Pinned</span>}
                    {isTrue(row.is_locked) && <span className="badge badge--muted" style={{ marginLeft: '0.5rem' }}>Locked</span>}
                  </td>
                  <td>{row.portfolio_stream ? row.portfolio_stream.replace(/_/g, ' ') : '—'}</td>
                  <td>{row.status ? row.status.replace(/_/g, ' ') : '—'}</td>
                  <td>{formatScore(row.total_score)}</td>
                  <td><TrendCell value={row.trend_7d} /></td>
                  <td><TrendCell value={row.trend_28d} /></td>
                  <td>{formatDate(row.scored_for_date)}</td>
                  <td className="text-sm">{writerCell(row)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination page={page} limit={PAGE_SIZE} total={total} onPage={(p) => setPaging({ key: filterKey, page: p })} />
        </>
      )}
    </div>
  );
}
