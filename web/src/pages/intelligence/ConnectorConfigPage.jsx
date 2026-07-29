import { useEffect, useId, useState } from 'react';
import { createPortal } from 'react-dom';
import { Plug, CheckCircle, XCircle, AlertCircle, Settings, X } from 'lucide-react';
import {
  listConnectors,
  upsertConnector,
  healthCheckConnector,
  disableConnector,
  enableConnector,
} from '../../services/intelligenceService.js';

const CATALOG = [
  {
    provider: 'gsc',
    display_name: 'Google Search Console',
    propertyLabel: 'Site property',
    propertyKey: 'site_property',
    propertyPlaceholder: 'sc-domain:example.com',
    credentialHint: 'GSC_CREDENTIAL_REF',
  },
  {
    provider: 'ga4',
    display_name: 'GA4 Content Analytics',
    propertyLabel: 'Property ID',
    propertyKey: 'property_id',
    propertyPlaceholder: 'properties/123456789',
    credentialHint: 'GA4_CREDENTIAL_REF',
  },
  {
    provider: 'indexnow',
    display_name: 'IndexNow',
    propertyLabel: null,
    propertyKey: null,
    propertyPlaceholder: null,
    credentialHint: 'INDEXNOW_API_KEY_REF',
  },
];

function HealthIcon({ status }) {
  const style = { flexShrink: 0 };
  if (status === 'healthy') return <CheckCircle size={16} style={{ ...style, color: 'var(--color-success)' }} />;
  if (status === 'failing' || status === 'unhealthy') {
    return <XCircle size={16} style={{ ...style, color: 'var(--color-danger)' }} />;
  }
  return <AlertCircle size={16} style={{ ...style, color: 'var(--color-warning)' }} />;
}

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function mergeCatalog(rows) {
  return CATALOG.map((catalog) => {
    const existing = rows.find((r) => String(r.provider || '').toLowerCase() === catalog.provider);
    if (!existing) {
      return {
        ...catalog,
        id: null,
        health_status: 'unknown',
        enabled: false,
        last_health_check_at: null,
        credential_reference: '',
        site_property: '',
        property_id: '',
        isStub: true,
      };
    }

    return {
      ...catalog,
      id: existing.id ?? null,
      display_name: existing.display_name || catalog.display_name,
      provider: catalog.provider,
      health_status: existing.health_status || 'unknown',
      enabled: Boolean(existing.enabled),
      last_health_check_at: existing.last_health_check_at || existing.last_health_check || null,
      credential_reference: existing.credential_reference || '',
      site_property: existing.site_property || '',
      property_id: existing.property_id || '',
      isStub: false,
    };
  });
}

function ConfigureModal({
  connector,
  form,
  setForm,
  busy,
  actionError,
  actionMsg,
  onClose,
  onSave,
  onHealthCheck,
  onDisable,
  onEnable,
}) {
  const titleId = useId();
  if (!connector || typeof document === 'undefined') return null;

  return createPortal(
    <div
      className="modal-backdrop"
      role="presentation"
      onClick={(e) => {
        if (e.target === e.currentTarget && !busy) onClose();
      }}
    >
      <div
        className="modal-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="modal-panel__header">
          <h2 id={titleId} style={{ margin: 0, fontSize: '1.05rem' }}>
            Configure {connector.display_name}
          </h2>
          <button
            type="button"
            className="btn btn--sm btn--secondary"
            onClick={onClose}
            disabled={busy}
            aria-label="Close configure dialog"
          >
            <X size={14} />
          </button>
        </div>

        <div className="modal-panel__body">
          <p className="text-sm text-muted" style={{ marginTop: 0 }}>
            Store only an environment variable name as the credential reference. Raw API keys are never saved.
          </p>

          {actionError && <div className="alert alert-danger mb-4">{actionError}</div>}
          {actionMsg && <div className="alert alert-success mb-4">{actionMsg}</div>}

          <form onSubmit={onSave}>
            <div style={{ display: 'grid', gap: '0.85rem' }}>
              <div>
                <label className="text-xs text-secondary" htmlFor="connector-display-name">Display name</label>
                <input
                  id="connector-display-name"
                  value={form.display_name}
                  onChange={(e) => setForm((f) => ({ ...f, display_name: e.target.value }))}
                  autoFocus
                />
              </div>

              {connector.propertyKey === 'site_property' && (
                <div>
                  <label className="text-xs text-secondary" htmlFor="connector-site-property">
                    {connector.propertyLabel}
                  </label>
                  <input
                    id="connector-site-property"
                    value={form.site_property}
                    onChange={(e) => setForm((f) => ({ ...f, site_property: e.target.value }))}
                    placeholder={connector.propertyPlaceholder}
                    required
                  />
                </div>
              )}

              {connector.propertyKey === 'property_id' && (
                <div>
                  <label className="text-xs text-secondary" htmlFor="connector-property-id">
                    {connector.propertyLabel}
                  </label>
                  <input
                    id="connector-property-id"
                    value={form.property_id}
                    onChange={(e) => setForm((f) => ({ ...f, property_id: e.target.value }))}
                    placeholder={connector.propertyPlaceholder}
                    required
                  />
                </div>
              )}

              <div>
                <label className="text-xs text-secondary" htmlFor="connector-credential-ref">
                  Credential env reference
                </label>
                <input
                  id="connector-credential-ref"
                  value={form.credential_reference}
                  onChange={(e) => setForm((f) => ({ ...f, credential_reference: e.target.value }))}
                  placeholder={connector.credentialHint}
                  required
                />
              </div>

              {connector.provider === 'indexnow' && (
                <p className="text-sm text-muted" style={{ margin: 0 }}>
                  After saving, submit URLs from Intelligence → IndexNow. The key itself stays in server env only.
                </p>
              )}
            </div>

            <div className="modal-panel__actions">
              <button type="submit" className="btn btn--sm btn--primary" disabled={busy}>
                {busy ? 'Working…' : (connector.id ? 'Save changes' : 'Save connector')}
              </button>
              {connector.id && (
                <>
                  <button type="button" className="btn btn--sm btn--secondary" onClick={onHealthCheck} disabled={busy}>
                    Run health check
                  </button>
                  {connector.enabled ? (
                    <button type="button" className="btn btn--sm btn--secondary" onClick={onDisable} disabled={busy}>
                      Disable
                    </button>
                  ) : (
                    <button type="button" className="btn btn--sm btn--secondary" onClick={onEnable} disabled={busy}>
                      Enable
                    </button>
                  )}
                </>
              )}
              <button type="button" className="btn btn--sm btn--secondary" onClick={onClose} disabled={busy}>
                Cancel
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>,
    document.body,
  );
}

export default function ConnectorConfigPage() {
  const [connectors, setConnectors] = useState(() => mergeCatalog([]));
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [configuringProvider, setConfiguringProvider] = useState(null);
  const [form, setForm] = useState({
    display_name: '',
    site_property: '',
    property_id: '',
    credential_reference: '',
  });
  const [busy, setBusy] = useState(false);
  const [actionMsg, setActionMsg] = useState(null);
  const [actionError, setActionError] = useState(null);

  const active = connectors.find((c) => c.provider === configuringProvider) || null;

  const load = () => {
    setLoading(true);
    setError(null);
    listConnectors()
      .then((data) => setConnectors(mergeCatalog(normalizeList(data))))
      .catch((e) => {
        setError(e.message || 'Failed to load connectors');
        setConnectors(mergeCatalog([]));
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  useEffect(() => {
    if (!configuringProvider) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape' && !busy) setConfiguringProvider(null);
    };
    window.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [configuringProvider, busy]);

  const openConfigure = (connector) => {
    setConfiguringProvider(connector.provider);
    setActionMsg(null);
    setActionError(null);
    const redacted = !connector.credential_reference
      || connector.credential_reference === '[REDACTED]';
    setForm({
      display_name: connector.display_name || '',
      site_property: connector.site_property || '',
      property_id: connector.property_id || '',
      credential_reference: redacted ? (connector.credentialHint || '') : connector.credential_reference,
    });
  };

  const closeConfigure = () => {
    if (busy) return;
    setConfiguringProvider(null);
    setActionMsg(null);
    setActionError(null);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    if (!active) return;

    const credential = form.credential_reference.trim();
    if (!credential || credential === '[REDACTED]') {
      setActionError('Enter a credential environment reference (not a raw API key).');
      return;
    }

    const body = {
      tenant_id: 1,
      provider: active.provider,
      display_name: form.display_name.trim() || active.display_name,
      credential_reference: credential,
      enabled: true,
      health_status: active.health_status || 'unknown',
    };

    if (active.propertyKey === 'site_property') {
      body.site_property = form.site_property.trim();
      if (!body.site_property) {
        setActionError('Site property is required');
        return;
      }
    }
    if (active.propertyKey === 'property_id') {
      body.property_id = form.property_id.trim();
      if (!body.property_id) {
        setActionError('Property ID is required');
        return;
      }
    }
    if (active.id) body.id = active.id;

    setBusy(true);
    setActionError(null);
    setActionMsg(null);
    try {
      await upsertConnector(body);
      setActionMsg('Connector configuration saved');
      setConfiguringProvider(null);
      load();
    } catch (err) {
      setActionError(err.message || 'Unable to save connector');
    } finally {
      setBusy(false);
    }
  };

  const runAction = async (fn, successMsg, { close = false } = {}) => {
    if (!active?.id) {
      setActionError('Save the connector first, then retry this action');
      return;
    }
    setBusy(true);
    setActionError(null);
    setActionMsg(null);
    try {
      await fn(active.id);
      setActionMsg(successMsg);
      if (close) setConfiguringProvider(null);
      load();
    } catch (err) {
      setActionError(err.message || 'Action failed');
    } finally {
      setBusy(false);
    }
  };

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

      {error && <div className="alert alert-danger mb-4">{error}</div>}
      {loading && <p className="muted">Loading connectors…</p>}

      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
        {connectors.map((c) => (
          <div key={c.provider} className="card">
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
                {c.last_health_check_at && (
                  <span className="text-sm text-muted">
                    Checked {new Date(c.last_health_check_at).toLocaleTimeString()}
                  </span>
                )}
                <button
                  type="button"
                  className="btn btn--sm btn--primary"
                  onClick={() => openConfigure(c)}
                  aria-haspopup="dialog"
                >
                  Configure
                </button>
              </div>
            </div>
          </div>
        ))}
      </div>

      {active && (
        <ConfigureModal
          connector={active}
          form={form}
          setForm={setForm}
          busy={busy}
          actionError={actionError}
          actionMsg={actionMsg}
          onClose={closeConfigure}
          onSave={handleSave}
          onHealthCheck={() => runAction(healthCheckConnector, 'Health check recorded')}
          onDisable={() => runAction(disableConnector, 'Connector disabled', { close: true })}
          onEnable={() => runAction(enableConnector, 'Connector enabled')}
        />
      )}
    </div>
  );
}
