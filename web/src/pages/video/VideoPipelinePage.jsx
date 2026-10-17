import { Fragment, useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Youtube, RefreshCw, Settings, ExternalLink, ChevronDown, ChevronRight } from 'lucide-react';
import videoService from '../../services/videoService';
import { useAuth } from '../../context/AuthContext';
import { ROUTES } from '../../constants/routes';
import { formatDate } from '../../utils/formatDate';

const STATUS_BADGE = {
  pending_vetting: 'badge--warning',
  rejected: 'badge--muted',
  in_progress: 'badge--info',
  blocked: 'badge--warning',
  failed: 'badge--error',
  published: 'badge--success',
  cancelled: 'badge--muted',
};

const STEP_LABEL = {
  vet: 'Vetting',
  tts: 'Voice-over',
  visuals: 'Visuals',
  render_submit: 'Render submit',
  render_poll: 'Rendering',
  youtube_upload: 'YouTube upload',
  youtube_extras: 'Thumbnail & captions',
  social: 'Social offer',
  done: 'Done',
};

const CHECK_LABEL = {
  automation_enabled: 'Automation switched on',
  vetting: 'Gemini vetting key',
  voice_over: 'OpenAI key (voice-over, stills)',
  rendering: 'Shotstack API key',
  media_signing: 'MEDIA_SIGNING_KEY (renderer fetches voice-overs)',
  screenshots: 'Screenshot worker (optional)',
  youtube_client: 'YouTube OAuth client',
  youtube_channel: 'YouTube channel connected',
};

const CONNECT_ERRORS = {
  invalid_state: 'The connection link expired or was already used. Start again.',
  consent_denied: 'Google consent was cancelled.',
  missing_code: 'Google did not return an authorisation code.',
  connect_failed: 'Google refused the connection — see the error below.',
};

function statusLabel(status) {
  return String(status ?? '').replace(/_/g, ' ');
}

function ChannelCard({ status, canManage, onChanged }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const connect = async () => {
    setBusy(true); setError(null);
    try {
      const res = await videoService.youtubeConnect();
      if (res?.consent_url) window.location.assign(res.consent_url);
    } catch (e) {
      setError(e.message);
      setBusy(false);
    }
  };

  const disconnect = async () => {
    if (!window.confirm('Disconnect the YouTube channel? Videos waiting to upload will be blocked until it is connected again.')) return;
    setBusy(true); setError(null);
    try {
      await videoService.youtubeDisconnect();
      onChanged();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="card mb-4" aria-label="YouTube channel">
      <div className="card__header">
        <h2 className="card__title"><Youtube size={16} aria-hidden="true" /> YouTube channel</h2>
      </div>
      <div className="card__body">
        {!status && <p className="muted">Loading channel status…</p>}
        {status && (
          <dl className="definition-list" style={{ margin: 0 }}>
            <dt>Status</dt>
            <dd>
              <span className={`badge ${status.connected ? 'badge--success' : 'badge--warning'}`}>
                {status.connected ? 'connected' : statusLabel(status.status || 'not connected')}
              </span>
            </dd>
            {status.channel_title && (<><dt>Channel</dt><dd>{status.channel_title}</dd></>)}
            {status.connected_at && (<><dt>Connected</dt><dd>{formatDate(status.connected_at)}</dd></>)}
            <dt>Upload privacy</dt><dd>{status.privacy}</dd>
            <dt>Redirect URI</dt><dd><code>{status.redirect_uri}</code></dd>
            {status.last_error && (<><dt>Last error</dt><dd className="text-error">{status.last_error}</dd></>)}
          </dl>
        )}
        {status && !status.client_configured && (
          <p className="muted mt-2">Set the YouTube OAuth client ID and secret in <Link to={ROUTES.AUTOMATION_SETTINGS}>Settings → AI &amp; Automation → Video</Link> first.</p>
        )}
        {error && <p className="text-error mt-2">{error}</p>}
      </div>
      {canManage && status && (
        <div className="card__footer">
          {!status.connected && (
            <button type="button" className="btn btn-primary btn-sm" onClick={connect} disabled={busy || !status.client_configured}>
              {busy ? 'Opening Google…' : 'Connect YouTube channel'}
            </button>
          )}
          {status.connected && (
            <button type="button" className="btn btn-secondary btn-sm" onClick={disconnect} disabled={busy}>Disconnect</button>
          )}
        </div>
      )}
    </section>
  );
}

function ReadinessCard({ status }) {
  if (!status) return null;
  return (
    <section className="card mb-4" aria-label="Pipeline readiness">
      <div className="card__header">
        <h2 className="card__title">Pipeline readiness</h2>
        <span className={`badge ${status.ready ? 'badge--success' : 'badge--warning'}`}>{status.ready ? 'ready' : 'not ready'}</span>
      </div>
      <div className="card__body">
        <ul className="plain-list">
          {Object.entries(status.checks || {}).map(([key, ok]) => (
            <li key={key}>
              <span className={`badge ${ok ? 'badge--success' : 'badge--neutral'}`}>{ok ? 'ok' : 'missing'}</span>{' '}
              {CHECK_LABEL[key] ?? key}
            </li>
          ))}
        </ul>
        <p className="muted mt-2">
          Renderer: {status.render_provider} · Voice: {status.tts?.model} / {status.tts?.voice} · Per day: {status.daily?.vlog} vlog, {status.daily?.short} Short
        </p>
        {(status.notes || []).map((n) => <p key={n} className="muted">{n}</p>)}
      </div>
    </section>
  );
}

function ItemDetail({ uuid, canRetry, onRetried }) {
  const [item, setItem] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    videoService.pipelineItem(uuid)
      .then((r) => { if (!cancelled) setItem(r); })
      .catch((e) => { if (!cancelled) setError(e.message); });
    return () => { cancelled = true; };
  }, [uuid]);

  const retry = async () => {
    setBusy(true); setError(null);
    try {
      await videoService.retry(uuid);
      onRetried();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  if (error) return <p className="text-error">{error}</p>;
  if (!item) return <p className="muted">Loading…</p>;

  return (
    <div className="video-pipeline__detail">
      {item.vetting_reasons?.length > 0 && (
        <>
          <h4>Vetting ({item.vetting_decision ?? 'pending'}{item.vetting_model ? ` · ${item.vetting_model}` : ''})</h4>
          <ul>{item.vetting_reasons.map((r) => <li key={r}>{r}</li>)}</ul>
        </>
      )}
      {item.last_error && <p className="text-error">{item.last_error}</p>}
      {item.warnings?.length > 0 && (
        <>
          <h4>Warnings</h4>
          <ul>{item.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
        </>
      )}
      <p>
        {item.youtube_url && /^https:\/\//.test(item.youtube_url) && (
          <a href={item.youtube_url} target="_blank" rel="noreferrer">Watch on YouTube <ExternalLink size={12} aria-hidden="true" /></a>
        )}
        {item.preview_url && /^https:\/\//.test(item.preview_url) && (
          <> <a href={item.preview_url} target="_blank" rel="noreferrer">Preview render</a> <span className="muted">(hosted by Shotstack ~24 h)</span></>
        )}
        {item.youtube_privacy && <span className="muted"> · YouTube privacy: {item.youtube_privacy}</span>}
        {item.upload_progress !== null && item.upload_progress !== undefined && item.status === 'in_progress' && <span className="muted"> · upload {item.upload_progress}%</span>}
      </p>
      {item.assets?.length > 0 && (
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
      )}
      {canRetry && ['blocked', 'failed', 'pending_vetting'].includes(item.status) && (
        <button type="button" className="btn btn-secondary btn-sm mt-2" onClick={retry} disabled={busy}>
          <RefreshCw size={14} aria-hidden="true" /> {item.status === 'pending_vetting' ? 'Vet now' : 'Retry'}
        </button>
      )}
    </div>
  );
}

export default function VideoPipelinePage() {
  const { hasPermission } = useAuth();
  const [searchParams] = useSearchParams();
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState(null);
  const [channel, setChannel] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [open, setOpen] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.allSettled([videoService.pipeline({ limit: 50 }), videoService.status(), videoService.youtubeStatus()])
      .then(([list, st, yt]) => {
        if (list.status === 'fulfilled') {
          setItems(list.value?.items ?? []);
          setTotal(list.value?.total ?? 0);
          setError(null);
        } else {
          setError(list.reason?.message || 'Failed to load the video pipeline.');
        }
        if (st.status === 'fulfilled') setStatus(st.value);
        if (yt.status === 'fulfilled') setChannel(yt.value);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const connectResult = searchParams.get('youtube');
  const connectReason = searchParams.get('reason');

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Automated pipeline</h1>
        <p className="page-header__subtitle">
          The reach-video routine writes a daily vlog and Short; Gemini vets each script (its decision is final),
          then Reach voices, renders and uploads it to YouTube. <Link to={ROUTES.AUTOMATION_SETTINGS}><Settings size={12} aria-hidden="true" /> Video settings</Link>
        </p>
      </div>

      {connectResult === 'connected' && <div className="alert alert-success mb-4">YouTube channel connected.</div>}
      {connectResult === 'error' && <div className="alert alert-danger mb-4">Could not connect YouTube: {CONNECT_ERRORS[connectReason] ?? connectReason}</div>}

      <div className="two-col-grid">
        <ChannelCard status={channel} canManage={hasPermission('video_connections.manage')} onChanged={load} />
        <ReadinessCard status={status} />
      </div>

      <section className="card" aria-label="Videos">
        <div className="card__header">
          <h2 className="card__title">Videos ({total})</h2>
          <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
            <RefreshCw size={14} aria-hidden="true" /> Refresh
          </button>
        </div>
        <div className="card__body">
          {error && <p className="text-error">{error}</p>}
          {loading && items.length === 0 && <p className="muted">Loading…</p>}
          {!loading && !error && items.length === 0 && <p className="muted">No videos yet. Scripts arrive from the reach-video routine every morning.</p>}
          {items.length > 0 && (
            <table className="data-table">
              <thead>
                <tr><th aria-label="Expand" /><th>Title</th><th>Format</th><th>Status</th><th>Step</th><th>Created</th><th>YouTube</th></tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <Fragment key={item.uuid}>
                    <tr>
                      <td>
                        <button type="button" className="btn btn-link btn-sm" aria-label={`Details for ${item.title}`} aria-expanded={open === item.uuid} onClick={() => setOpen(open === item.uuid ? null : item.uuid)}>
                          {open === item.uuid ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                        </button>
                      </td>
                      <td>{item.title}</td>
                      <td>{item.format === 'short' ? 'Short' : 'Vlog'}</td>
                      <td><span className={`badge ${STATUS_BADGE[item.status] ?? 'badge--neutral'}`}>{statusLabel(item.status)}</span></td>
                      <td>{STEP_LABEL[item.step] ?? item.step}{item.step_detail ? <div className="muted text-sm">{item.step_detail}</div> : null}</td>
                      <td>{item.created_at ? formatDate(item.created_at) : '—'}</td>
                      <td>{item.youtube_url && /^https:\/\//.test(item.youtube_url) ? <a href={item.youtube_url} target="_blank" rel="noreferrer">Open</a> : '—'}</td>
                    </tr>
                    {open === item.uuid && (
                      <tr>
                        <td />
                        <td colSpan={6}><ItemDetail uuid={item.uuid} canRetry={hasPermission('video.retry')} onRetried={load} /></td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </section>
    </div>
  );
}
