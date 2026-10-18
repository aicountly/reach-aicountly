import { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';

/**
 * Campaign readiness — each channel's live status as computed by the API
 * (GET v1/distribution/readiness). The page shows what the server reports and
 * nothing more: no channel is shown as ready unless the API says a real
 * provider is configured.
 */

const STATE_META = {
  ready:          { label: 'Ready',          badge: 'badge--success' },
  test_mode:      { label: 'Test mode',      badge: 'badge--warning' },
  not_configured: { label: 'Not configured', badge: 'badge--danger' },
  blocked:        { label: 'Blocked',        badge: 'badge--warning' },
  not_supported:  { label: 'Not supported',  badge: 'badge--neutral' },
};

const GROUPS = [
  { key: 'messaging', title: 'Messaging' },
  { key: 'social',    title: 'Social' },
  { key: 'ads',       title: 'Paid ads' },
];

function StateBadge({ state }) {
  const meta = STATE_META[state] ?? { label: state ?? 'unknown', badge: 'badge--neutral' };
  return <span className={`badge ${meta.badge}`}>{meta.label}</span>;
}

function ChannelCard({ channel }) {
  const failing = (channel.checks ?? []).filter((c) => !c.ok);
  return (
    <div className="card" data-testid={`readiness-${channel.channel}`}>
      <div className="card__header">
        <h3>{channel.label}</h3>
        <StateBadge state={channel.state} />
      </div>
      <div className="card__body">
        {Array.isArray(channel.providers) && channel.providers.length > 0 ? (
          <p className="muted" data-testid={`providers-${channel.channel}`}>
            Providers (in send order):{' '}
            {channel.providers.map((p, i) => (
              <span key={p.code}>{i > 0 ? ', ' : ''}<code>{p.code}</code> ({p.role})</span>
            ))}
            {channel.provider_source && channel.provider_source !== 'console' ? ` — source: ${channel.provider_source}` : ''}
          </p>
        ) : (
          channel.provider && <p className="muted">Provider: {channel.provider}</p>
        )}
        {(channel.reasons ?? []).length > 0 && (
          <ul>
            {channel.reasons.map((r) => <li key={r}>{r}</li>)}
          </ul>
        )}
        {(channel.checks ?? []).length > 0 && (
          <ul aria-label={`${channel.label} checks`}>
            {channel.checks.map((c) => (
              <li key={c.key}>
                <span aria-hidden="true">{c.ok ? '✓' : '✗'}</span>{' '}
                <code>{c.key}</code> {c.ok ? 'set' : 'missing'}
              </li>
            ))}
          </ul>
        )}
        {typeof channel.opted_in_leads === 'number' && (
          <p className="muted">Leads with a recorded opt-in: {channel.opted_in_leads}</p>
        )}
        {(channel.manual ?? []).length > 0 && (
          <details>
            <summary>Outside Reach (verify by hand)</summary>
            <ul>
              {channel.manual.map((m) => <li key={m}>{m}</li>)}
            </ul>
          </details>
        )}
        {failing.length === 0 && channel.state === 'ready' && (
          <p className="muted">All checks Reach can verify pass.</p>
        )}
      </div>
    </div>
  );
}

export default function CampaignReadinessPage() {
  const [report, setReport]   = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    api.get('v1/distribution/readiness')
      .then((r) => setReport(r))
      .catch((e) => setError(e?.message || 'Could not load channel readiness.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const channels = report?.channels ?? [];

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Campaign readiness</h1>
        <p className="page-header__subtitle">
          Live status of every channel, computed by the API. A channel only shows Ready when a real provider is configured.
        </p>
      </div>

      <div className="mb-4">
        <button type="button" className="btn btn--sm btn--secondary" onClick={load} disabled={loading}>
          Refresh
        </button>
      </div>

      {error && <p className="text-error">{error}</p>}
      {loading && <p className="muted">Checking channels…</p>}

      {!loading && report && (
        <>
          <p className="muted">
            Environment: {report.environment}{report.production ? ' (production)' : ''} · checked {report.generated_at}
          </p>
          {report.queue && (
            <p className={report.queue.stalled ? 'text-error' : 'muted'}>
              Queue <code>{report.queue.name}</code>: {report.queue.pending ?? '—'} pending. {report.queue.note}
            </p>
          )}
          {GROUPS.map((g) => {
            const list = channels.filter((c) => c.group === g.key);
            if (list.length === 0) return null;
            return (
              <section key={g.key} className="mb-6">
                <h2>{g.title}</h2>
                <div className="cards-grid">
                  {list.map((c) => <ChannelCard key={c.channel} channel={c} />)}
                </div>
              </section>
            );
          })}
        </>
      )}
    </div>
  );
}
