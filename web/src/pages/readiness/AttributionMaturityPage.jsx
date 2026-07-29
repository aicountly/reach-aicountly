import { useState } from 'react';

const MODELS = [
  {
    name: 'equal_weight',
    label: 'Equal Weight',
    formula: 'allocation = 1 / total_touchpoints',
    limitation: 'Treats all touchpoints equally regardless of position or recency.',
  },
  {
    name: 'position_based',
    label: 'Position Based',
    formula: 'first=40%, last=40%, middle=20% shared equally',
    limitation: 'Middle touchpoints may be underweighted in short journeys.',
  },
  {
    name: 'time_decay',
    label: 'Time Decay',
    formula: 'weight_i = e^(−λ × days_before_conversion), then normalised',
    limitation: 'May undervalue early brand-awareness content in long journeys.',
  },
];

export default function AttributionMaturityPage() {
  const [activeModel, setActiveModel] = useState('equal_weight');

  const model = MODELS.find((m) => m.name === activeModel);

  return (
    <div>
      <div className="page-header page-header--stack">
        <h1>Attribution Maturity</h1>
        <p className="page-header__subtitle">
          Multi-touch attribution models for understanding content contribution to conversions.
        </p>
      </div>

      <div className="alert alert-warning mb-4">
        <strong>Important:</strong> Attribution results represent a modelled allocation,
        not factual causation. No revenue is attributed. Observational data only.
      </div>

      <div className="btn-group mb-4">
        {MODELS.map((m) => (
          <button
            key={m.name}
            type="button"
            onClick={() => setActiveModel(m.name)}
            className={`btn btn--sm ${activeModel === m.name ? 'btn--primary' : 'btn--secondary'}`}
          >
            {m.label}
          </button>
        ))}
      </div>

      {model && (
        <div className="card mb-4">
          <div className="card__header">{model.label}</div>
          <div className="card__body">
            <dl className="definition-list">
              <dt>Formula</dt>
              <dd>
                <code style={{
                  display: 'block',
                  fontSize: '0.85rem',
                  background: 'var(--color-bg)',
                  padding: '0.5rem 0.75rem',
                  borderRadius: 'var(--radius)',
                }}>
                  {model.formula}
                </code>
              </dd>
              <dt>Limitation</dt>
              <dd className="text-sm">{model.limitation}</dd>
            </dl>
          </div>
        </div>
      )}

      <div className="card">
        <div className="card__body" style={{ textAlign: 'center', padding: '2rem 1.25rem' }}>
          <p className="text-sm text-muted" style={{ margin: 0 }}>
            Attribution calculations run automatically for each recorded conversion.
            Journey data will appear here once models are activated.
          </p>
        </div>
      </div>
    </div>
  );
}
