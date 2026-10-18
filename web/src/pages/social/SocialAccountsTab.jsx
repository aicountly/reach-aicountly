import { useCallback, useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import { PlugZap, RefreshCw } from 'lucide-react';
import api from '../../services/api';
import { adminService } from '../../services/adminService';
import { usePermission } from '../../hooks/usePermission';
import { formatDateTime } from '../../utils/formatDate';
import { networkMeta, readinessMeta } from './socialAdsModel';

/** /social → the first tab this user may open. */
export function SocialHubIndex() {
  const { has } = usePermission();
  return <Navigate to={has('distribution.read') ? 'accounts' : 'calendar'} replace />;
}

function Dot({ kind }) {
  return <span className={`as-dot${kind && kind !== 'none' ? ` as-dot--${kind}` : ''}`} aria-hidden="true" />;
}

function AccountCard({ channel }) {
  const net = networkMeta(channel.channel);
  const meta = readinessMeta(channel.state);
  const acct = channel.account;
  const missing = (channel.checks ?? []).filter((c) => !c.ok);
  return (
    <article className="sa-card" data-testid={`account-${channel.channel}`}>
      <header className="sa-card__head">
        <net.icon size={18} aria-hidden="true" className="sa-card__icon" />
        <h3 className="sa-card__title">{channel.label || net.label}</h3>
        <span className={`badge ${meta.badge}`}><Dot kind={meta.dot} /> {meta.label}</span>
      </header>
      {acct ? (
        <p className="sa-card__line">
          Posts go to <strong>{acct.name || acct.username || acct.id}</strong>
          {acct.username && acct.name ? <span className="text-muted"> @{String(acct.username).replace(/^@/, '')}</span> : null}
          <span className="sa-card__meta"> · id <code>{acct.id}</code> · {acct.source === 'setting' ? 'set in settings' : 'auto: the only active account'}</span>
        </p>
      ) : (
        <p className="sa-card__line text-muted">No account resolved for this network.</p>
      )}
      {channel.youtube_publisher && (
        <p className="sa-card__meta">Videos: <code>video.youtube.publisher = {channel.youtube_publisher}</code></p>
      )}
      {(channel.reasons ?? []).length > 0 && (
        <ul className="sa-card__reasons">
          {channel.reasons.map((r) => <li key={r}>{r}</li>)}
        </ul>
      )}
      {missing.length > 0 && (
        <p className="sa-card__meta">Missing: {missing.map((c) => <code key={c.key} className="sa-code">{c.key}</code>)}</p>
      )}
    </article>
  );
}

function LiveCheckResult({ result }) {
  if (!result) return null;
  const accounts = result.detail?.accounts;
  const targets = result.detail?.targets;
  return (
    <div className={`sa-live ${result.ok ? 'sa-live--ok' : 'sa-live--fail'}`} role="status" data-testid="social-live-check">
      <p><strong>{result.ok ? 'social-api.ai answered' : 'Live check failed'}:</strong> {result.message}</p>
      {Array.isArray(accounts) && accounts.length > 0 && (
        <details>
          <summary>Connected accounts ({accounts.length})</summary>
          <ul>{accounts.map((a) => <li key={a}><code>{a}</code></li>)}</ul>
        </details>
      )}
      {targets && typeof targets === 'object' && (
        <details>
          <summary>Where each network would post</summary>
          <ul>{Object.entries(targets).map(([k, v]) => <li key={k}><strong>{k}</strong>: {String(v)}</li>)}</ul>
        </details>
      )}
    </div>
  );
}

/**
 * Accounts — per network: is a social-api.ai account connected and which one
 * Reach posts to (GET v1/distribution/readiness, computed live; no secrets).
 * "Run live check" asks social-api.ai directly (never posts).
 */
export default function SocialAccountsTab() {
  const { has } = usePermission();
  const canCheck = has('settings.manage');
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [checking, setChecking] = useState(false);
  const [live, setLive] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/distribution/readiness')
      .then((r) => setReport(r ?? null))
      .catch((e) => setError(e?.status === 403
        ? 'You need the distribution.read permission to see channel readiness.'
        : (e?.message || 'Could not load account readiness.')))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const runLive = async () => {
    setChecking(true);
    setLive(null);
    try {
      const r = await adminService.runLiveChecks(['social_api']);
      const res = (r?.results ?? []).find((x) => x.target === 'social_api') ?? { ok: false, message: 'The live check returned no social-api.ai result.' };
      setLive(res);
    } catch (e) {
      setLive({ ok: false, message: e?.message || 'Live check failed.' });
    } finally {
      setChecking(false);
      load();
    }
  };

  const social = (report?.channels ?? []).filter((c) => c.group === 'social');
  const ready = social.filter((c) => c.state === 'ready').length;

  return (
    <section aria-labelledby="sa-accounts-title">
      <div className="sa-toolbar">
        <div className="sa-toolbar__text">
          <h2 id="sa-accounts-title" className="sa-h2">Connected accounts</h2>
          <p className="text-sm text-muted">
            A network shows <em>Connected</em> only when the social-api.ai key is set and an account for it resolves.
          </p>
        </div>
        <div className="sa-toolbar__actions">
          <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
            <RefreshCw size={14} aria-hidden="true" /> Refresh
          </button>
          {canCheck && (
            <button type="button" className="btn btn-primary btn-sm" onClick={runLive} disabled={checking}>
              <PlugZap size={14} aria-hidden="true" /> {checking ? 'Checking…' : 'Run live check'}
            </button>
          )}
        </div>
      </div>

      <LiveCheckResult result={live} />

      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {loading && !report && <p className="muted">Checking social accounts…</p>}

      {report && (
        <>
          <p className="sa-summary" data-testid="accounts-summary">
            <strong>{ready} of {social.length}</strong> networks ready
            {report.generated_at ? <> · checked {formatDateTime(report.generated_at)}</> : null}
            {report.production === false ? <> · environment <code>{report.environment}</code></> : null}
          </p>
          {social.length === 0 ? (
            <div className="as-empty"><p>The readiness report lists no social networks.</p></div>
          ) : (
            <div className="sa-grid">
              {social.map((c) => <AccountCard key={c.channel} channel={c} />)}
            </div>
          )}
          {ready < social.length && has('settings.view') && (
            <p className="text-sm sa-hint">
              Connect accounts in social-api.ai, then set the key or a per-network account id in{' '}
              <Link to="/admin/ai-automation#social">AI &amp; Automation → Social</Link>.
            </p>
          )}
        </>
      )}
    </section>
  );
}
