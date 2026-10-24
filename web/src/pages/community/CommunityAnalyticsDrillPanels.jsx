import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import { DrillPath, DrillLink } from '../../components/drill';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { formatDateTime, formatDate } from '../../utils/formatDate';

const LIMIT = 25;
const label = (s) => (s ? String(s).replace(/_/g, ' ') : '—');

/**
 * One page of a drill list. `params` is the filter; a new filter starts at
 * page 1. Loads nothing while `enabled` is false.
 */
function useDrillList(path, params, enabled) {
  const key = JSON.stringify(params);
  const [page, setPage] = useState(1);
  const [lastKey, setLastKey] = useState(key);
  const [state, setState] = useState({ items: [], total: 0, source: null, loading: false, error: null });
  if (key !== lastKey) {
    setLastKey(key);
    setPage(1);
  }

  useEffect(() => {
    if (!enabled) return undefined;
    let live = true;
    setState((s) => ({ ...s, loading: true, error: null }));
    api.get(path, { ...JSON.parse(key), page, limit: LIMIT })
      .then((d) => { if (live) setState({ items: d?.items || [], total: d?.total ?? 0, source: d?.source ?? null, loading: false, error: null }); })
      .catch((e) => { if (live) setState((s) => ({ ...s, loading: false, error: e.message })); });
    return () => { live = false; };
  }, [path, key, page, enabled]);

  return { ...state, page, setPage };
}

function QuestionLink({ uuid, title }) {
  if (!uuid) return <span className="text-muted">—</span>;
  return <Link to={`/community/questions/${uuid}`} className="drill-link">{title || 'Question'}</Link>;
}

/**
 * Engagement event-type (› day) → the validated events counted, each opening
 * the question / official answer it was recorded on.
 * GET v1/community/analytics/engagement/events uses the counts' predicate.
 */
export function EngagementEventsPanel({ drill, days, engagement }) {
  const { event_type: eventType, day } = drill.filters;
  const open = !!(eventType || day);
  const list = useDrillList('v1/community/analytics/engagement/events', { event_type: eventType, day, days }, open);
  if (!open) return null;

  // Days with events of the chosen type, from the counts already on the page.
  const perDay = engagement
    .filter((r) => (!eventType || r.event_type === eventType) && Number(r.cnt) > 0)
    .reduce((acc, r) => ({ ...acc, [r.day]: (acc[r.day] ?? 0) + Number(r.cnt) }), {});

  const columns = [
    { key: 'event_timestamp', label: 'When', render: (r) => formatDateTime(r.event_timestamp) },
    { key: 'event_type', label: 'Type', render: (r) => label(r.event_type) },
    { key: 'question', label: 'Question', render: (r) => <QuestionLink uuid={r.question_uuid} title={r.question_title} /> },
    { key: 'answer', label: 'Official answer', render: (r) => (r.answer_uuid
      ? <Link to={`/community/answers/${r.answer_uuid}`} className="drill-link">Answer · {label(r.answer_status)}</Link>
      : <span className="text-muted">—</span>) },
    { key: 'source', label: 'Recorded by', render: (r) => label(r.source) },
  ];

  return (
    <section className="card mt-4" data-testid="engagement-events" aria-label="Engagement events">
      <h2 className="card__title">Engagement events (last {days}d) — {list.total}</h2>
      <DrillPath drill={drill} rootLabel="All event types" labels={{ event_type: 'Type', day: 'Day' }}
                 format={(k, v) => (k === 'day' ? formatDate(v) : label(v))} />
      {!day && Object.keys(perDay).length > 1 && (
        <div className="flex gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {Object.entries(perDay).map(([d, n]) => (
            <span key={d} className="text-xs">{formatDate(d)}: <strong>
              <DrillLink onClick={() => drill.drill({ day: d })} label={`Show ${n} events on ${d}`}>{n}</DrillLink>
            </strong></span>
          ))}
        </div>
      )}
      {list.error && <p className="text-error">{list.error}</p>}
      {list.loading && list.items.length === 0 ? <p className="muted">Loading events…</p> : (
        <DataTable columns={columns} rows={list.items} emptyMessage="No validated events for this filter." />
      )}
      <Pagination page={list.page} total={list.total} limit={LIMIT} onPage={list.setPage} />
    </section>
  );
}

/**
 * A "Top grounding sources" row → the answer versions grounded on it
 * (reach_community_source_coverage), with the claims it backs.
 */
export function GroundedAnswersPanel({ drill }) {
  const { source_type: sourceType, source_id: sourceId } = drill.filters;
  const open = !!(sourceType && sourceId);
  const list = useDrillList('v1/community/analytics/coverage/answers', { source_type: sourceType, source_id: sourceId }, open);
  if (!open) return null;

  const columns = [
    { key: 'question', label: 'Question', render: (r) => <QuestionLink uuid={r.question_uuid} title={r.question_title} /> },
    { key: 'answer', label: 'Answer version', render: (r) => (
      <Link to={`/community/answers/${r.answer_uuid}`} className="drill-link">v{r.version_number} · {label(r.answer_status)}</Link>
    ) },
    { key: 'claims', label: 'Claims grounded on this source', render: (r) => (
      <ul className="text-sm" style={{ margin: 0, paddingLeft: 16 }}>
        {(r.claims || []).map((c, i) => (
          <li key={i}>{c.claim || <span className="text-muted">(no claim reference)</span>} <span className="text-xs text-muted">· {label(c.coverage_status)}</span></li>
        ))}
      </ul>
    ) },
    { key: 'version_created_at', label: 'Version created', render: (r) => formatDateTime(r.version_created_at) },
  ];
  const src = list.source;

  return (
    <section className="card mt-4" data-testid="grounded-answers" aria-label="Answers grounded on this source">
      <h2 className="card__title">Answers grounded on {src?.title || `${label(sourceType)} ${sourceId === 'none' ? '(no id)' : `#${sourceId}`}`} — {list.total}</h2>
      <button type="button" className="badge badge-secondary mb-2" onClick={() => drill.upTo(-1)} aria-label="Close the grounded answers">
        {label(sourceType)} {sourceId === 'none' ? '(no id)' : `#${sourceId}`} ✕
      </button>
      {src?.url && (
        <p className="text-xs"><a href={src.url} target="_blank" rel="noopener noreferrer">Open the source{src.version ? ` (version ${src.version})` : ''}</a></p>
      )}
      {list.error && <p className="text-error">{list.error}</p>}
      {list.loading && list.items.length === 0 ? <p className="muted">Loading answers…</p> : (
        <DataTable columns={columns} rows={list.items} rowKey={(r) => r.answer_version_id} emptyMessage="No answers are grounded on this source." />
      )}
      <Pagination page={list.page} total={list.total} limit={LIMIT} onPage={list.setPage} />
    </section>
  );
}
