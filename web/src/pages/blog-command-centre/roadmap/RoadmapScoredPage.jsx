import { Fragment, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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

const FACTORS = [
  ['search_opportunity', 'Search'],
  ['product_priority', 'Product'],
  ['audience_problem', 'Audience'],
  ['conversion_potential', 'Conversion'],
  ['content_gap', 'Gap'],
  ['seasonality', 'Season'],
  ['internal_link_value', 'Links'],
  ['evidence_readiness', 'Evidence'],
];

function parseDeductions(raw) {
  if (!raw) return [];
  const parsed = typeof raw === 'string' ? safeParse(raw) : raw;
  if (!parsed || typeof parsed !== 'object') return [];
  return Object.entries(parsed).filter(([, value]) => Number(value) > 0);
}

function safeParse(value) {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

export function RoadmapScoredPage() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expanded, setExpanded] = useState(null);
  // An optimiser run's "Scored" count links here with its date.
  const [scoredFor, setScoredFor] = useUrlFilter('scored_for_date');
  const [paging, setPaging] = useState({ key: scoredFor, page: 1 });
  const page = paging.key === scoredFor ? paging.page : 1;
  const [total, setTotal] = useState(0);

  useEffect(() => {
    const params = { limit: PAGE_SIZE };
    if (page > 1) params.page = page;
    if (scoredFor) params.scored_for_date = scoredFor;
    blogCommandCentreService.getRoadmapScored(params)
      .then((data) => {
        setItems(data?.items ?? []);
        setTotal(data?.total ?? 0);
        setError('');
      })
      .catch((err) => {
        setError(err?.message || 'Unable to load scored topics.');
        setItems([]);
      })
      .finally(() => setLoading(false));
  }, [scoredFor, page]);

  if (loading) return <Loader label="Loading scores…" />;

  return (
    <div>
      {error && <Alert variant="error">{error}</Alert>}

      {scoredFor && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          <button type="button" className="badge badge-secondary" onClick={() => setScoredFor('')}
                  aria-label="Clear scored-for date filter">
            Scored for {formatDate(scoredFor)} ✕
          </button>
          <span className="text-xs text-muted">{total} score{total === 1 ? '' : 's'}</span>
        </div>
      )}

      {items.length === 0 ? (
        <RoadmapEmptyState
          title={scoredFor ? 'Nothing was scored for this date' : 'Nothing scored yet'}
          detail="Run the daily optimiser (reach:blog-optimize-roadmap) to produce ranked scores."
        />
      ) : (
        <table className="data-table">
          <thead>
            <tr>
              <th style={{ width: '3rem' }}>#</th>
              <th>Topic</th>
              <th>Stream</th>
              <th>Total</th>
              <th>Deductions</th>
              <th>7d</th>
              <th>Scored for</th>
              <th aria-label="Expand" />
            </tr>
          </thead>
          <tbody>
            {items.map((row, index) => {
              const deductions = parseDeductions(row.deductions_json);
              const isOpen = expanded === row.id;

              return (
                <Fragment key={row.id}>
                  <tr>
                    <td>{(page - 1) * PAGE_SIZE + index + 1}</td>
                    <td>
                      {row.content_item_id
                        ? <Link to={ROUTES.CONTENT_DETAIL.replace(':id', row.content_item_id)}>{row.title}</Link>
                        : row.title}
                    </td>
                    <td>{row.portfolio_stream ? row.portfolio_stream.replace(/_/g, ' ') : '—'}</td>
                    <td><strong>{formatScore(row.total_score)}</strong></td>
                    <td>
                      {deductions.length === 0
                        ? <span className="text-muted">None</span>
                        : <span className="badge badge--danger">-{formatScore(row.deduction_total)}</span>}
                    </td>
                    <td><TrendCell value={row.trend_7d} /></td>
                    <td>{formatDate(row.scored_for_date)}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn--ghost btn--sm"
                        onClick={() => setExpanded(isOpen ? null : row.id)}
                      >
                        {isOpen ? 'Hide' : 'Breakdown'}
                      </button>
                    </td>
                  </tr>
                  {isOpen && (
                    <tr>
                      <td colSpan={8}>
                        <div style={{ display: 'flex', gap: '1.25rem', flexWrap: 'wrap', padding: '0.5rem 0' }}>
                          {FACTORS.map(([key, label]) => (
                            <div key={key}>
                              <div className="text-muted" style={{ fontSize: '0.8rem' }}>{label}</div>
                              <div>{formatScore(row[key])}</div>
                            </div>
                          ))}
                        </div>
                        {deductions.length > 0 && (
                          <div style={{ paddingTop: '0.5rem' }}>
                            <span className="text-muted" style={{ marginRight: '0.5rem' }}>Deductions:</span>
                            {deductions.map(([key, value]) => (
                              <span key={key} className="badge badge--danger" style={{ marginRight: '0.35rem' }}>
                                {key.replace(/_/g, ' ')} -{value}
                              </span>
                            ))}
                          </div>
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      )}
      <Pagination page={page} limit={PAGE_SIZE} total={total} onPage={(p) => setPaging({ key: scoredFor, page: p })} />
    </div>
  );
}
