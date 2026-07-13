import React, { useState } from 'react';

/**
 * Phase 3 — Preview of the approved knowledge grounding context.
 * Shows product, features, claims, and conflict warnings.
 * Read-only: no editing of grounding from this component.
 */
export default function GroundingPreview({ groundingContext }) {
  const [section, setSection] = useState('overview');

  if (!groundingContext) {
    return (
      <div className="text-sm text-gray-500 italic py-2">
        No grounding context available.
      </div>
    );
  }

  const {
    product,
    features = [],
    claims = [],
    brand_rules = [],
    content_policies = [],
    __conflicts = [],
    __token_estimate,
    __truncated = [],
  } = groundingContext;

  const sections = [
    { id: 'overview', label: 'Overview' },
    { id: 'features', label: `Features (${features.length})` },
    { id: 'claims', label: `Claims (${claims.length})` },
    { id: 'brand', label: `Brand Rules (${brand_rules.length})` },
  ];

  return (
    <div className="border border-gray-200 rounded-lg overflow-hidden text-sm">
      <div className="bg-gray-50 px-3 py-2 flex items-center justify-between border-b border-gray-200">
        <h4 className="font-semibold text-gray-700 text-xs uppercase tracking-wide">Approved Grounding Context</h4>
        <div className="flex items-center gap-2">
          {__token_estimate && (
            <span className="text-xs text-gray-400">~{__token_estimate.toLocaleString()} tokens</span>
          )}
          {__truncated.length > 0 && (
            <span className="text-xs px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700" title={`Truncated: ${__truncated.join(', ')}`}>
              Truncated
            </span>
          )}
          {__conflicts.length > 0 && (
            <span className="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700">
              {__conflicts.length} conflict{__conflicts.length !== 1 ? 's' : ''}
            </span>
          )}
        </div>
      </div>

      <div className="flex gap-0 border-b border-gray-200 overflow-x-auto">
        {sections.map(s => (
          <button
            key={s.id}
            onClick={() => setSection(s.id)}
            className={`px-3 py-1.5 text-xs font-medium whitespace-nowrap border-r border-gray-200 ${
              section === s.id
                ? 'bg-white text-blue-700 border-b-2 border-b-blue-500'
                : 'bg-gray-50 text-gray-600 hover:bg-white'
            }`}
          >
            {s.label}
          </button>
        ))}
      </div>

      <div className="p-3 max-h-64 overflow-y-auto">
        {section === 'overview' && (
          <div className="space-y-2">
            {product ? (
              <div>
                <p className="font-medium text-gray-800">{product.name}</p>
                {product.tagline && <p className="text-xs text-gray-500">{product.tagline}</p>}
                <div className="mt-1 flex flex-wrap gap-1">
                  <span className="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded">
                    {features.length} features
                  </span>
                  <span className="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">
                    {claims.length} claims
                  </span>
                  <span className="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">
                    {brand_rules.length} brand rules
                  </span>
                  <span className="text-xs bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded">
                    {content_policies.length} content policies
                  </span>
                </div>
              </div>
            ) : (
              <p className="text-gray-500 text-xs">No product context.</p>
            )}
            {__conflicts.length > 0 && (
              <div className="mt-2">
                <p className="text-xs font-medium text-red-700 mb-1">Conflicts detected:</p>
                {__conflicts.map((c, i) => (
                  <p key={i} className="text-xs text-red-600">⚠ {c.message}</p>
                ))}
              </div>
            )}
          </div>
        )}

        {section === 'features' && (
          <div className="space-y-1">
            {features.length === 0 && <p className="text-xs text-gray-400">No features in context.</p>}
            {features.map((f, i) => (
              <div key={f.id || i} className="flex items-center gap-2">
                <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${
                  f.availability === 'available' ? 'bg-green-500' :
                  f.availability === 'beta' ? 'bg-yellow-500' :
                  f.availability === 'limited' ? 'bg-blue-500' : 'bg-gray-400'
                }`} />
                <span className="text-xs text-gray-700">{f.name || f.slug}</span>
                {f.availability && f.availability !== 'available' && (
                  <span className="text-xs text-gray-400">({f.availability})</span>
                )}
              </div>
            ))}
          </div>
        )}

        {section === 'claims' && (
          <div className="space-y-1">
            {claims.length === 0 && <p className="text-xs text-gray-400">No claims in context.</p>}
            {claims.map((c, i) => (
              <div key={c.id || i} className="flex items-start gap-2">
                <span className="text-xs text-gray-400 shrink-0">#{c.id}</span>
                <span className="text-xs text-gray-700">{c.claim_text || c.body || JSON.stringify(c).slice(0, 80)}</span>
              </div>
            ))}
          </div>
        )}

        {section === 'brand' && (
          <div className="space-y-1">
            {brand_rules.length === 0 && <p className="text-xs text-gray-400">No brand rules in context.</p>}
            {brand_rules.map((r, i) => (
              <div key={r.id || i} className="flex items-center gap-2">
                <span className="text-xs bg-gray-100 text-gray-600 px-1 rounded">{r.rule_type}</span>
                <span className="text-xs text-gray-700">{r.rule_value || r.name}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
