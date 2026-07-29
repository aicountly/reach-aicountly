import { Plug, CheckCircle, XCircle, AlertCircle, Settings } from 'lucide-react';

const CONNECTORS = [
  { id: 1, provider: 'gsc', display_name: 'Google Search Console', health_status: 'healthy', enabled: true, last_health_check: '2026-07-15T07:00:00Z' },
  { id: 2, provider: 'ga4', display_name: 'GA4 Content Analytics', health_status: 'healthy', enabled: true, last_health_check: '2026-07-15T07:00:00Z' },
  { id: 3, provider: 'indexnow', display_name: 'IndexNow', health_status: 'unknown', enabled: false, last_health_check: null },
];

function HealthIcon({ status }) {
  const style = { flexShrink: 0 };
  if (status === 'healthy') return <CheckCircle size={16} style={{ ...style, color: 'var(--color-success)' }} />;
  if (status === 'failing') return <XCircle size={16} style={{ ...style, color: 'var(--color-danger)' }} />;
  return <AlertCircle size={16} style={{ ...style, color: 'var(--color-warning)' }} />;
}

export default function ConnectorConfigPage() {
  return (
    <div>
      <div className="page-header page-header--stack">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <Plug size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} aria-hidden="true" />
          <h1>Connectors</h1>
        </div>
        <p className="page-header__subtitle">
          Manage analytics and submission connector configurations
        </p>
      </div>

      <div className="alert alert-info mb-4">
        <strong>Security:</strong> Credentials are stored as environment references only. Raw API keys are never persisted in the database.
      </div>

      <div className="stack" style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
        {CONNECTORS.map((c) => (
          <div key={c.id} className="card">
            <div
              className="card__body"
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: '1rem',
                flexWrap: 'wrap',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.85rem', minWidth: 0 }}>
                <div
                  style={{
                    width: 40,
                    height: 40,
                    borderRadius: 'var(--radius)',
                    background: 'var(--color-bg)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    flexShrink: 0,
                  }}
                >
                  <Settings size={18} style={{ color: 'var(--color-text-muted)' }} aria-hidden="true" />
                </div>
                <div style={{ minWidth: 0 }}>
                  <p style={{ fontWeight: 600, margin: 0 }}>{c.display_name}</p>
                  <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0', fontFamily: 'var(--font-mono, monospace)' }}>
                    {c.provider}
                  </p>
                </div>
              </div>

              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.75rem',
                  flexWrap: 'wrap',
                  marginLeft: 'auto',
                }}
              >
                <div style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem' }} className="text-sm">
                  <HealthIcon status={c.health_status} />
                  <span style={{ textTransform: 'capitalize' }}>{c.health_status}</span>
                </div>
                <span className={`badge ${c.enabled ? 'badge--success' : 'badge--muted'}`}>
                  {c.enabled ? 'Enabled' : 'Disabled'}
                </span>
                {c.last_health_check && (
                  <span className="text-sm text-muted">
                    Checked {new Date(c.last_health_check).toLocaleTimeString()}
                  </span>
                )}
                <button type="button" className="btn btn--sm btn--secondary">Configure</button>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
