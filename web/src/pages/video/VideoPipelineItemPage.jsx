import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ExternalLink, RefreshCw } from 'lucide-react';
import videoService from '../../services/videoService';
import { useAuth } from '../../context/AuthContext';
import { DrillLink, drillHref } from '../../components/drill';
import { ROUTES } from '../../constants/routes';
import { formatDateTime } from '../../utils/formatDate';
import { STATUS_BADGE, STEP_LABEL, safeUrl, statusLabel } from './videoPipelineShared';

const STEPS = Object.keys(STEP_LABEL);
const JOB_BADGE = { completed: 'badge--success', failed: 'badge--error', dead_letter: 'badge--error', processing: 'badge--info', pending: 'badge--warning', skipped: 'badge--muted', cancelled: 'badge--muted' };
const jobHref = (id) => `${ROUTES.JOBS}/${id}`;

/**
 * Where a step stands: the item's current step is "current" (or failed /
 * blocked with the item); steps before it are done; after it, not reached.
 * The step's queue jobs show what actually ran.
 */
function stepState(step, item) {
  const at = STEPS.indexOf(item.step);
  const i = STEPS.indexOf(step);
  if (item.status === 'published' || i < at) return 'done';
  if (i === at) return ['failed', 'blocked'].includes(item.status) ? item.status : (item.status === 'rejected' ? 'rejected' : 'current');
  return 'not reached';
}

/** One automated video: its pipeline steps and the jobs that ran them, history, assets and YouTube link. */
export default function VideoPipelineItemPage() {
  const { uuid } = useParams();
  const { hasPermission } = useAuth();
  const [item, setItem] = useState(null);
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    videoService.pipelineItem(uuid)
      .then((r) => { setItem(r); setError(null); })
      .catch((e) => setError(e.message || 'Failed to load this video.'))
      .finally(() => setLoading(false));
  }, [uuid]);
  useEffect(() => { load(); }, [load]);

  const retry = async () => {
    setBusy(true); setError(null); setNotice(null);
    try { await videoService.retry(uuid); setNotice('Retry queued.'); load(); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  if (loading && !item) return <p className="muted">Loading…</p>;
  if (!item) {
    return (
      <div>
        <p className="text-error">{error || 'Video not found.'}</p>
        <Link to="/video">← Pipeline</Link>
      </div>
    );
  }

  const jobs = item.jobs || [];
  const jobsByStep = jobs.reduce((m, j) => ({ ...m, [j.step]: [...(m[j.step] || []), j] }), {});
  const youtube = safeUrl(item.youtube_url);
  const preview = safeUrl(item.preview_url);
  const canRetry = hasPermission('video.retry') && ['blocked', 'failed', 'pending_vetting'].includes(item.status);

  return (
    <div>
      <p className="text-sm" style={{ margin: '0 0 0.5rem' }}><Link to="/video">← Pipeline</Link></p>
      <div className="page-header page-header--stack">
        <h1 style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', flexWrap: 'wrap' }}>
          {item.title}
          <span className={`badge ${STATUS_BADGE[item.status] ?? 'badge--neutral'}`}>{statusLabel(item.status)}</span>
        </h1>
        <p className="page-header__subtitle">
          <DrillLink to={drillHref('/video', { format: item.format })} title="All videos of this format">{item.format === 'short' ? 'Short' : 'Vlog'}</DrillLink>
          {' · '}step: {STEP_LABEL[item.step] ?? item.step}
          {item.step_detail ? ` — ${item.step_detail}` : ''}
        </p>
      </div>

      {error && <p className="text-error">{error}</p>}
      {notice && <div className="alert alert-success mb-4">{notice}</div>}

      <section className="card mb-4" aria-label="Video">
        <div className="card__body">
          <dl className="definition-list" style={{ margin: 0 }}>
            <dt>YouTube</dt>
            <dd>
              {youtube
                ? <a href={youtube} target="_blank" rel="noreferrer">Watch on YouTube <ExternalLink size={12} aria-hidden="true" /></a>
                : 'Not uploaded yet'}
              {item.youtube_privacy && <span className="muted"> · {item.youtube_privacy}</span>}
              {item.upload_progress != null && item.status === 'in_progress' && <span className="muted"> · upload {item.upload_progress}%</span>}
            </dd>
            {preview && (<><dt>Render preview</dt><dd><a href={preview} target="_blank" rel="noreferrer">Preview</a> <span className="muted">(hosted by Shotstack ~24 h)</span></dd></>)}
            <dt>Vetting</dt><dd>{item.vetting_decision ?? 'pending'}{item.vetting_model ? ` · ${item.vetting_model}` : ''}</dd>
            <dt>Render</dt><dd>{item.render_status ?? '—'}</dd>
            <dt>Thumbnail / captions</dt><dd>{item.thumbnail_status ?? '—'} / {item.captions_status ?? '—'}</dd>
            {item.product_slug && (<><dt>Product</dt><dd>{item.product_slug}</dd></>)}
            <dt>Created</dt><dd>{formatDateTime(item.created_at)}</dd>
            <dt>Approved</dt><dd>{formatDateTime(item.approved_at)}</dd>
            <dt>Published</dt><dd>{formatDateTime(item.published_at)}</dd>
          </dl>
          {item.last_error && <p className="text-error mt-2">{item.last_error}</p>}
          {item.vetting_reasons?.length > 0 && (
            <><h4>Vetting reasons</h4><ul>{item.vetting_reasons.map((r) => <li key={r}>{r}</li>)}</ul></>
          )}
          {item.warnings?.length > 0 && (
            <><h4>Warnings</h4><ul>{item.warnings.map((w) => <li key={w}>{w}</li>)}</ul></>
          )}
        </div>
        {canRetry && (
          <div className="card__footer">
            <button type="button" className="btn btn-secondary btn-sm" onClick={retry} disabled={busy}>
              <RefreshCw size={14} aria-hidden="true" /> {item.status === 'pending_vetting' ? 'Vet now' : 'Retry'}
            </button>
          </div>
        )}
      </section>

      <section className="card mb-4" aria-label="Pipeline steps">
        <div className="card__header">
          <h2 className="card__title">Pipeline steps</h2>
          <Link to={drillHref(ROUTES.JOBS, { job_type: 'reach.video_pipeline_step', queue: 'video' })} className="text-sm">All pipeline jobs →</Link>
        </div>
        <div className="card__body">
          <table className="data-table">
            <thead><tr><th>Step</th><th>State</th><th>Jobs (oldest first)</th></tr></thead>
            <tbody>
              {STEPS.filter((s) => s !== 'done').map((s) => {
                const state = stepState(s, item);
                const stepJobs = jobsByStep[s] || [];
                return (
                  <tr key={s} aria-current={item.step === s ? 'step' : undefined}>
                    <td style={{ fontWeight: item.step === s ? 600 : undefined }}>{STEP_LABEL[s]}</td>
                    <td><span className={`badge ${state === 'done' ? 'badge--success' : state === 'current' ? 'badge--info' : state === 'not reached' ? 'badge--neutral' : 'badge--error'}`}>{state}</span></td>
                    <td>
                      {stepJobs.length === 0 ? <span className="muted">—</span> : stepJobs.map((j) => (
                        <span key={j.id} style={{ marginRight: '0.6rem', whiteSpace: 'nowrap' }}>
                          <DrillLink to={jobHref(j.id)} title={j.error_message || `Job ${j.id}`}>#{j.id}</DrillLink>{' '}
                          <span className={`badge ${JOB_BADGE[j.status] ?? 'badge--neutral'}`}>{statusLabel(j.status)}</span>
                          <span className="text-xs muted"> {j.attempts}/{j.max_attempts}</span>
                        </span>
                      ))}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {jobs.length === 0 && <p className="muted mt-2">No queue jobs recorded for this video yet.</p>}
        </div>
      </section>

      <section className="card mb-4" aria-label="Job history">
        <div className="card__header"><h2 className="card__title">Job history</h2></div>
        <div className="card__body">
          {jobs.length === 0 ? <p className="muted">No jobs yet.</p> : (
            <table className="data-table">
              <thead><tr><th>Job</th><th>Step</th><th>Status</th><th>Attempts</th><th>Started</th><th>Completed</th><th>Error</th></tr></thead>
              <tbody>
                {jobs.map((j) => (
                  <tr key={j.id}>
                    <td><DrillLink to={jobHref(j.id)}>#{j.id}</DrillLink></td>
                    <td>{STEP_LABEL[j.step] ?? j.step}</td>
                    <td><span className={`badge ${JOB_BADGE[j.status] ?? 'badge--neutral'}`}>{statusLabel(j.status)}</span></td>
                    <td>{j.attempts} / {j.max_attempts}</td>
                    <td>{formatDateTime(j.started_at)}</td>
                    <td>{formatDateTime(j.completed_at)}</td>
                    <td className="text-sm">{j.error_message || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>

      <section className="card mb-4" aria-label="Staff actions">
        <div className="card__header"><h2 className="card__title">Staff actions</h2></div>
        <div className="card__body">
          {(item.history || []).length === 0 ? <p className="muted">No retries or re-vets recorded.</p> : (
            <ul className="plain-list">
              {item.history.map((h) => (
                <li key={h.id}>
                  {formatDateTime(h.created_at)} · <code>{h.action}</code>
                  {h.new_value?.status ? <span className="muted"> (was {statusLabel(h.new_value.status)}{h.new_value.step ? ` at ${STEP_LABEL[h.new_value.step] ?? h.new_value.step}` : ''})</span> : null}
                  {h.user_id ? <span className="muted"> · user #{h.user_id}</span> : null}
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>

      {item.assets?.length > 0 && (
        <section className="card" aria-label="Assets">
          <div className="card__header"><h2 className="card__title">Assets</h2></div>
          <div className="card__body">
            <table className="data-table">
              <thead><tr><th>Asset</th><th>Scene</th><th>Source</th><th>Status</th><th>Detail</th></tr></thead>
              <tbody>
                {item.assets.map((a) => (
                  <tr key={`${a.kind}-${a.scene_order}`}>
                    <td>{a.kind}</td>
                    <td>{a.scene_order || '—'}</td>
                    <td>{statusLabel(a.source)}</td>
                    <td>{a.status}</td>
                    <td>{a.preview_url ? <a href={a.preview_url} target="_blank" rel="noreferrer">open</a> : null} {a.detail}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}
