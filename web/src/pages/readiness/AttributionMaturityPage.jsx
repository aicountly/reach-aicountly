import { useEffect, useState } from 'react';
import { BarChart3 } from 'lucide-react';
import {
  listAttributionModels,
  registerAttributionModel,
  activateAttributionModel,
} from '../../services/readinessService.js';

const FALLBACK_MODELS = [
  {
    model_name: 'equal_weight',
    label: 'Equal Weight',
    description: 'Each touchpoint receives equal credit',
    formula: 'allocation = 1 / total_touchpoints',
    limitations: 'Treats all touchpoints equally regardless of position or recency.',
    lookback_window_days: 30,
    is_active: false,
    registered: false,
  },
  {
    model_name: 'position_based',
    label: 'Position Based',
    description: 'First and last touchpoints receive elevated credit; middle touchpoints share the remainder',
    formula: 'first=40%, last=40%, middle=20% shared equally',
    limitations: 'Middle touchpoints may be underweighted in short journeys.',
    lookback_window_days: 30,
    is_active: false,
    registered: false,
  },
  {
    model_name: 'time_decay',
    label: 'Time Decay',
    description: 'More recent touchpoints receive greater credit, decaying exponentially backwards',
    formula: 'weight_i = e^(-lambda * days_before_conversion), then normalised',
    limitations: 'May undervalue early brand-awareness content in long journeys.',
    lookback_window_days: 30,
    is_active: false,
    registered: false,
  },
];

function normalizeList(payload) {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
}

function statusMeta(model) {
  if (model.is_active) return { label: 'Active', className: 'badge--success' };
  if (model.registered) return { label: 'Registered', className: 'badge--info' };
  return { label: 'Defined', className: 'badge--muted' };
}

export default function AttributionMaturityPage() {
  const [models, setModels] = useState(FALLBACK_MODELS);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busyKey, setBusyKey] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [actionMsg, setActionMsg] = useState(null);

  const load = () => {
    setLoading(true);
    setError(null);
    listAttributionModels()
      .then((data) => {
        const rows = normalizeList(data);
        setModels(rows.length > 0 ? rows : FALLBACK_MODELS);
      })
      .catch((e) => {
        setError(e.message || 'Failed to load attribution models');
        setModels(FALLBACK_MODELS);
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
  }, []);

  const handleRegister = async (model) => {
    setBusyKey(model.model_name);
    setActionError(null);
    setActionMsg(null);
    try {
      await registerAttributionModel({
        model_name: model.model_name,
        lookback_window_days: model.lookback_window_days || 30,
        tenant_id: 1,
      });
      setActionMsg(`${model.label || model.model_name} registered`);
      load();
    } catch (err) {
      setActionError(err.message || 'Unable to register model');
    } finally {
      setBusyKey(null);
    }
  };

  const handleActivate = async (model) => {
    if (!model.id) return;
    setBusyKey(model.model_name);
    setActionError(null);
    setActionMsg(null);
    try {
      await activateAttributionModel(model.id);
      setActionMsg(`${model.label || model.model_name} activated`);
      load();
    } catch (err) {
      setActionError(err.message || 'Unable to activate model');
    } finally {
      setBusyKey(null);
    }
  };

  const activeCount = models.filter((m) => m.is_active).length;
  const registeredCount = models.filter((m) => m.registered).length;

  return (
    <div>
      <div className="page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
          <BarChart3 size={22} style={{ color: 'var(--color-primary)', flexShrink: 0 }} aria-hidden="true" />
          <div>
            <h1 style={{ margin: 0 }}>Attribution Maturity</h1>
            <p className="page-header__subtitle" style={{ marginTop: '0.15rem' }}>
              Multi-touch attribution models for understanding content contribution to conversions.
            </p>
          </div>
        </div>
      </div>

      <div className="alert alert-warning mb-4">
        <strong>Important:</strong> Attribution results represent a modelled allocation,
        not factual causation. No revenue is attributed. Observational data only.
      </div>

      <div className="stat-grid mb-4">
        <div className="stat-card">
          <div className="stat-card__value">3</div>
          <div className="stat-card__label">Model definitions</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{registeredCount}</div>
          <div className="stat-card__label">Registered</div>
        </div>
        <div className="stat-card">
          <div className="stat-card__value">{activeCount}</div>
          <div className="stat-card__label">Active</div>
        </div>
      </div>

      {error && <div className="alert alert-danger mb-4">{error}</div>}
      {actionError && <div className="alert alert-danger mb-4">{actionError}</div>}
      {actionMsg && <div className="alert alert-success mb-4">{actionMsg}</div>}
      {loading && <p className="muted">Loading attribution models…</p>}

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))',
          gap: '0.85rem',
          marginBottom: '1rem',
        }}
      >
        {models.map((model) => {
          const status = statusMeta(model);
          const busy = busyKey === model.model_name;
          return (
            <div key={model.model_name} className="card" style={{ height: '100%' }}>
              <div className="card__header" style={{ gap: '0.5rem' }}>
                <span>{model.label || model.model_name}</span>
                <span className={`badge ${status.className}`}>{status.label}</span>
              </div>
              <div className="card__body" style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                <p className="text-sm" style={{ margin: 0 }}>{model.description}</p>

                <div>
                  <div className="text-xs text-secondary" style={{ marginBottom: '0.35rem' }}>Formula</div>
                  <code
                    style={{
                      display: 'block',
                      fontSize: '0.8rem',
                      background: 'var(--color-bg)',
                      padding: '0.55rem 0.7rem',
                      borderRadius: 'var(--radius)',
                      lineHeight: 1.45,
                      wordBreak: 'break-word',
                    }}
                  >
                    {model.formula}
                  </code>
                </div>

                <div>
                  <div className="text-xs text-secondary" style={{ marginBottom: '0.35rem' }}>Limitation</div>
                  <p className="text-sm text-muted" style={{ margin: 0 }}>{model.limitations}</p>
                </div>

                <div className="text-sm text-muted">
                  Lookback: {model.lookback_window_days || 30} days
                </div>

                <div style={{ marginTop: 'auto', display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                  {!model.registered && (
                    <button
                      type="button"
                      className="btn btn--sm btn--primary"
                      disabled={busy}
                      onClick={() => handleRegister(model)}
                    >
                      {busy ? 'Working…' : 'Register'}
                    </button>
                  )}
                  {model.registered && !model.is_active && (
                    <button
                      type="button"
                      className="btn btn--sm btn--primary"
                      disabled={busy}
                      onClick={() => handleActivate(model)}
                    >
                      {busy ? 'Working…' : 'Activate'}
                    </button>
                  )}
                  {model.is_active && (
                    <span className="text-sm text-muted">Ready for journey calculations</span>
                  )}
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div className="card">
        <div className="card__header">Governance</div>
        <div className="card__body">
          <ul className="text-sm" style={{ margin: 0, paddingLeft: '1.1rem', lineHeight: 1.7 }}>
            <li>Every allocation includes a mandatory limitations note</li>
            <li>Models allocate conversion credit only — never revenue</li>
            <li>Identity confidence and journey completeness are disclosed with results</li>
            <li>Journey calculations appear after conversions and touchpoints are recorded</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
