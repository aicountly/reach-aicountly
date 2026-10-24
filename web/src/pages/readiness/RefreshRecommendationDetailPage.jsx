import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { DrillLink, drillHref } from '../../components/drill';
import { formatDate, formatDateTime } from '../../utils/formatDate';
import { getRefreshRecommendation } from '../../services/readinessService.js';
import ContentCell from './RefreshContentCell.jsx';
import { RISK_BADGE, confidence, label, score } from './refreshShared.js';

function JsonList({ items }) {
  if (!Array.isArray(items) || items.length === 0) return <span className="text-muted">—</span>;
  return (
    <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
      {items.map((x, i) => <li key={i}>{typeof x === 'string' ? x : JSON.stringify(x)}</li>)}
    </ul>
  );
}

function ScoreComponents({ components, total }) {
  if (!components.length) {
    return <p className="text-sm text-muted" style={{ margin: 0 }}>No score components were stored for this recommendation.</p>;
  }
  const max = Math.max(...components.map((c) => Number(c.contribution) || 0), 0.000001);
  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="data-table">
        <thead>
          <tr>
            <th>Factor</th>
            <th style={{ textAlign: 'right' }}>Signal</th>
            <th style={{ textAlign: 'right' }}>Weight</th>
            <th style={{ textAlign: 'right' }}>Contribution</th>
            <th>Source</th>
            <th>Period</th>
          </tr>
        </thead>
        <tbody>
          {components.map((c) => (
            <tr key={c.id}>
              <td style={{ textTransform: 'capitalize' }}>{label(c.factor)}</td>
              <td style={{ textAlign: 'right' }}>{c.raw_value ?? '—'}</td>
              <td style={{ textAlign: 'right' }}>{c.weight}</td>
              <td style={{ textAlign: 'right', minWidth: 140 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', justifyContent: 'flex-end' }}>
                  <span aria-hidden="true" style={{ display: 'inline-block', height: 6, borderRadius: 3, background: 'var(--color-primary)', width: `${Math.round((Number(c.contribution) / max) * 60)}px` }} />
                  {score(c.contribution)}
                </div>
              </td>
              <td className="text-sm text-muted">{label(c.evidence_source) || '—'}</td>
              <td className="text-sm text-muted">{c.evidence_period || '—'}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <th colSpan={3} style={{ textAlign: 'right' }}>Total score</th>
            <th style={{ textAlign: 'right' }}>{score(total)}</th>
            <th colSpan={2} />
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

function Workflow({ wf }) {
  const brief = wf.brief;
  const links = wf.publication_links || [];
  return (
    <section id={`workflow-${wf.id}`} className="card mb-4" aria-label={`Workflow ${wf.id}`}>
      <div className="card__header">
        <h2 className="card__title" style={{ margin: 0 }}>Workflow #{wf.id}</h2>
        <span className="badge badge--info">{label(wf.status)}</span>
      </div>
      <div className="card__body">
        <dl className="definition-list">
          <dt>Objective</dt><dd>{wf.refresh_objective || '—'}</dd>
          <dt>Risk</dt><dd>{wf.risk_classification || '—'}</dd>
          <dt>Due</dt><dd>{wf.due_date ? formatDate(wf.due_date) : '—'}</dd>
          {wf.approved_at && (<><dt>Approved</dt><dd>{formatDateTime(wf.approved_at)}</dd></>)}
          {wf.cancelled_at && (<><dt>Cancelled</dt><dd>{formatDateTime(wf.cancelled_at)}{wf.cancel_reason ? ` — ${wf.cancel_reason}` : ''}</dd></>)}
          <dt>Updated</dt><dd>{formatDateTime(wf.updated_at)}</dd>
        </dl>

        <h3 className="text-sm" style={{ margin: '1rem 0 0.4rem' }}>Brief</h3>
        {brief ? (
          <dl className="definition-list">
            <dt>Objective</dt><dd>{brief.refresh_objective}</dd>
            <dt>Key changes</dt><dd><JsonList items={brief.key_changes} /></dd>
            <dt>Target sections</dt><dd><JsonList items={brief.target_sections} /></dd>
            <dt>Source requirements</dt><dd><JsonList items={brief.source_requirements} /></dd>
            <dt>Created</dt><dd>{formatDateTime(brief.created_at)}</dd>
          </dl>
        ) : <p className="text-sm text-muted" style={{ margin: 0 }}>No brief prepared yet.</p>}

        {wf.version_links?.length > 0 && (
          <>
            <h3 className="text-sm" style={{ margin: '1rem 0 0.4rem' }}>Draft versions</h3>
            <table className="data-table data-table--compact">
              <thead><tr><th>#</th><th>Status</th><th>Content version</th><th>Created</th></tr></thead>
              <tbody>
                {wf.version_links.map((v) => (
                  <tr key={v.id}>
                    <td>{v.id}</td>
                    <td>{label(v.version_status)}</td>
                    <td>{v.content_version_id || v.blog_version_id || v.community_answer_version_id || v.video_script_version_id || '—'}</td>
                    <td>{formatDateTime(v.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        )}

        <h3 className="text-sm" style={{ margin: '1rem 0 0.4rem' }}>Publication &amp; outcome windows</h3>
        {links.length === 0 ? (
          <p className="text-sm text-muted" style={{ margin: 0 }}>Not published yet — outcome windows open on publication.</p>
        ) : (
          <table className="data-table data-table--compact">
            <thead><tr><th>Publication</th><th>Delivery</th><th>Published</th><th>Outcome windows</th></tr></thead>
            <tbody>
              {links.map((p) => (
                <tr key={p.id}>
                  <td>#{p.id}{Number(p.retry_count) > 0 ? <span className="text-xs text-muted"> · {p.retry_count} retries</span> : null}</td>
                  <td>{label(p.delivery_status)}</td>
                  <td>{p.published_at ? formatDateTime(p.published_at) : '—'}</td>
                  <td>
                    {(p.outcome_windows || []).length === 0 ? '—' : p.outcome_windows.map((w) => (
                      <div key={w.id}>
                        <DrillLink to={drillHref('/readiness/outcomes', { window_id: w.id, content_identity_id: w.content_identity_id })}>
                          Window #{w.id}
                        </DrillLink>
                        <span className="text-xs text-muted"> {formatDate(w.post_from)} → {formatDate(w.post_to)} · {label(w.measurement_status)}</span>
                      </div>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}

/** One refresh recommendation: why it scored (components + evidence) and what happened next. */
export default function RefreshRecommendationDetailPage() {
  const { id } = useParams();
  const location = useLocation();
  const [state, setState] = useState({ loading: true, error: null, rec: null });

  useEffect(() => {
    let cancelled = false;
    setState({ loading: true, error: null, rec: null });
    getRefreshRecommendation(id)
      .then((rec) => { if (!cancelled) setState({ loading: false, error: null, rec }); })
      .catch((e) => { if (!cancelled) setState({ loading: false, error: e.message || 'Failed to load recommendation', rec: null }); });
    return () => { cancelled = true; };
  }, [id]);

  // Workflows list rows link to #workflow-<id>.
  useEffect(() => {
    if (!state.rec || !location.hash) return;
    document.getElementById(location.hash.slice(1))?.scrollIntoView?.({ block: 'start' });
  }, [state.rec, location.hash]);

  const { loading, error, rec } = state;
  if (loading) return <p className="muted">Loading recommendation…</p>;
  if (error || !rec) {
    return (
      <div>
        <div className="alert alert-danger mb-4">{error || 'Recommendation not found.'}</div>
        <Link to="/readiness/recommendations">← All recommendations</Link>
      </div>
    );
  }

  const evidence = rec.evidence;
  const identity = rec.content_identity_id;
  const content = { content_identity_id: identity, ...(rec.content || {}), content_title: rec.content?.title };

  return (
    <div>
      <p className="text-sm" style={{ margin: '0 0 0.5rem' }}>
        <Link to="/readiness/recommendations">← Recommendations</Link>
      </p>
      <div className="page-header page-header--stack">
        <h1 style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          Recommendation #{rec.id}
          <span className={`badge ${RISK_BADGE[rec.risk_classification] ?? 'badge--neutral'}`}>{rec.risk_classification}</span>
          <span className="badge badge--neutral">{label(rec.status)}</span>
        </h1>
      </div>

      <section className="card mb-4">
        <div className="card__body">
          <dl className="definition-list">
            <dt>Content</dt><dd><ContentCell row={content} /></dd>
            <dt>Total score</dt><dd>{score(rec.total_score)}</dd>
            <dt>Confidence</dt><dd>{confidence(rec.confidence)} <span className="text-xs text-muted">(evidence completeness)</span></dd>
            <dt>Effort</dt><dd>{rec.effort_estimate || '—'}</dd>
            <dt>Cooldown until</dt><dd>{rec.cooldown_until ? formatDateTime(rec.cooldown_until) : '—'}</dd>
            {rec.triage_notes && (<><dt>Triage notes</dt><dd>{rec.triage_notes}</dd></>)}
            {rec.superseded_by && (<><dt>Superseded by</dt><dd><DrillLink to={`/readiness/recommendations/${rec.superseded_by}`}>#{rec.superseded_by}</DrillLink></dd></>)}
            <dt>Created</dt><dd>{formatDateTime(rec.created_at)}</dd>
          </dl>
          <p className="text-sm" style={{ margin: '0.75rem 0 0' }}>
            <DrillLink to={drillHref('/readiness/recommendations', { content_identity_id: identity })}>All recommendations for this content</DrillLink>
            {' · '}
            <DrillLink to={drillHref('/readiness/outcomes', { content_identity_id: identity })}>Its refresh outcomes</DrillLink>
          </p>
        </div>
      </section>

      <section className="card mb-4" aria-label="Score components">
        <div className="card__header"><h2 className="card__title" style={{ margin: 0 }}>Score components</h2></div>
        <div className="card__body"><ScoreComponents components={rec.score_components || []} total={rec.total_score} /></div>
      </section>

      <section className="card mb-4" aria-label="Evidence snapshot">
        <div className="card__header"><h2 className="card__title" style={{ margin: 0 }}>Evidence snapshot</h2></div>
        <div className="card__body">
          {!evidence ? (
            <p className="text-sm text-muted" style={{ margin: 0 }}>The evidence snapshot is not available.</p>
          ) : (
            <>
              <dl className="definition-list">
                <dt>Evidence date</dt><dd>{formatDate(evidence.evidence_date)} ({evidence.window_days}-day window)</dd>
                <dt>Completeness</dt><dd>{confidence(evidence.completeness_score)}</dd>
                <dt>Missing domains</dt><dd><JsonList items={evidence.missing_domains} /></dd>
              </dl>
              <details style={{ marginTop: '0.75rem' }}>
                <summary className="text-sm">Evidence packet</summary>
                <pre className="text-xs" style={{ whiteSpace: 'pre-wrap', margin: '0.5rem 0 0' }}>{JSON.stringify(evidence.evidence_packet, null, 2)}</pre>
              </details>
            </>
          )}
        </div>
      </section>

      {(rec.workflows || []).length === 0 ? (
        <section className="card mb-4"><div className="card__body">
          <p className="text-sm text-muted" style={{ margin: 0 }}>No workflow yet — one starts when the recommendation is accepted.</p>
        </div></section>
      ) : rec.workflows.map((wf) => <Workflow key={wf.id} wf={wf} />)}

      {(rec.related || []).length > 0 && (
        <section className="card" aria-label="Other recommendations for this content">
          <div className="card__header"><h2 className="card__title" style={{ margin: 0 }}>Other recommendations for this content</h2></div>
          <div className="card__body">
            <ul style={{ margin: 0, paddingLeft: '1.1rem' }}>
              {rec.related.map((r) => (
                <li key={r.id}>
                  <DrillLink to={`/readiness/recommendations/${r.id}`}>#{r.id}</DrillLink>{' '}
                  <span className="text-sm text-muted">{label(r.status)} · {r.risk_classification} · {formatDate(r.created_at)}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>
      )}
    </div>
  );
}
