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
    <div className="p-6">
      <h1 className="text-2xl font-semibold text-gray-900 mb-2">Attribution Maturity</h1>
      <p className="text-sm text-gray-500 mb-6">
        Multi-touch attribution models for understanding content contribution to conversions.
      </p>

      <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-800">
        <strong>Important:</strong> Attribution results represent a modelled allocation,
        not factual causation. No revenue is attributed. Observational data only.
      </div>

      <div className="flex gap-2 mb-6">
        {MODELS.map((m) => (
          <button
            key={m.name}
            onClick={() => setActiveModel(m.name)}
            className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
              activeModel === m.name
                ? 'bg-blue-600 text-white'
                : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'
            }`}
          >
            {m.label}
          </button>
        ))}
      </div>

      {model && (
        <div className="bg-white border border-gray-200 rounded-lg p-6 mb-4">
          <h2 className="font-medium text-gray-900 mb-3">{model.label}</h2>
          <div className="space-y-3">
            <div>
              <p className="text-xs text-gray-500 uppercase tracking-wide mb-1">Formula</p>
              <p className="font-mono text-sm text-gray-700 bg-gray-50 px-3 py-2 rounded">{model.formula}</p>
            </div>
            <div>
              <p className="text-xs text-gray-500 uppercase tracking-wide mb-1">Limitation</p>
              <p className="text-sm text-gray-600">{model.limitation}</p>
            </div>
          </div>
        </div>
      )}

      <div className="bg-white border border-gray-200 rounded-lg p-8 text-center">
        <p className="text-gray-500 text-sm">
          Attribution calculations run automatically for each recorded conversion.
          Journey data will appear here once models are activated.
        </p>
      </div>
    </div>
  );
}
