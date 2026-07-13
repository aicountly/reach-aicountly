import React, { useState } from 'react';

/**
 * Phase 3 — Displays AI validation findings for a content item.
 * Human review is always required; findings cannot be dismissed automatically.
 */
const SEVERITY_CONFIG = {
  critical: { bg: 'bg-red-50', border: 'border-red-200', badge: 'bg-red-100 text-red-800', icon: '🚫' },
  high:     { bg: 'bg-orange-50', border: 'border-orange-200', badge: 'bg-orange-100 text-orange-800', icon: '⚠️' },
  warning:  { bg: 'bg-yellow-50', border: 'border-yellow-200', badge: 'bg-yellow-100 text-yellow-800', icon: '⚡' },
  info:     { bg: 'bg-blue-50', border: 'border-blue-200', badge: 'bg-blue-100 text-blue-800', icon: 'ℹ️' },
};

const STATUS_COLORS = {
  failed:   'text-red-700',
  warning:  'text-yellow-700',
  passed:   'text-green-700',
  not_applicable: 'text-gray-500',
  waived:   'text-gray-500 line-through',
};

export default function ValidationFindings({ findings = [], runStatus = null, onWaive = null }) {
  const [expandedId, setExpandedId] = useState(null);

  if (!findings.length && !runStatus) return null;

  const blocking = findings.filter(f => f.status === 'failed' && ['critical', 'high'].includes(f.severity));
  const warnings = findings.filter(f => f.status === 'warning' || (f.status === 'failed' && f.severity === 'warning'));
  const passed   = findings.filter(f => f.status === 'passed');

  return (
    <div className="space-y-3" data-testid="validation-findings">
      {runStatus && (
        <div className="flex items-center gap-2 text-sm text-gray-600 mb-2">
          <span>Validation run:</span>
          <span className={`font-medium ${runStatus === 'completed' ? 'text-green-700' : 'text-yellow-700'}`}>
            {runStatus}
          </span>
          {blocking.length > 0 && <span className="px-2 py-0.5 rounded bg-red-100 text-red-800 text-xs">{blocking.length} blocking</span>}
          {warnings.length > 0 && <span className="px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 text-xs">{warnings.length} warnings</span>}
          {passed.length > 0 && <span className="px-2 py-0.5 rounded bg-green-100 text-green-800 text-xs">{passed.length} passed</span>}
        </div>
      )}

      {findings
        .filter(f => f.status !== 'passed' && f.status !== 'not_applicable')
        .map(finding => {
          const cfg = SEVERITY_CONFIG[finding.severity] || SEVERITY_CONFIG.info;
          const isExpanded = expandedId === finding.id;

          return (
            <div key={finding.id || finding.validator_type} className={`rounded-lg border ${cfg.border} ${cfg.bg} p-3`}>
              <div className="flex items-start justify-between gap-2">
                <div className="flex items-start gap-2 flex-1 min-w-0">
                  <span className="text-base shrink-0" aria-hidden="true">{cfg.icon}</span>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className={`text-sm font-semibold ${STATUS_COLORS[finding.status] || 'text-gray-700'}`}>
                        {finding.title}
                      </span>
                      <span className={`text-xs px-1.5 py-0.5 rounded ${cfg.badge}`}>{finding.severity}</span>
                      {finding.is_ai_assisted && (
                        <span className="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700">AI assisted</span>
                      )}
                      {finding.affected_field && (
                        <code className="text-xs bg-white/60 px-1 rounded">{finding.affected_field}</code>
                      )}
                    </div>
                    <p className="mt-1 text-xs text-gray-700">{finding.message}</p>

                    {finding.suggested_fix && (
                      <p className="mt-1 text-xs text-blue-700">
                        <strong>Fix:</strong> {finding.suggested_fix}
                      </p>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-1 shrink-0">
                  {finding.details && (
                    <button
                      onClick={() => setExpandedId(isExpanded ? null : (finding.id || finding.validator_type))}
                      className="text-xs text-gray-500 hover:text-gray-700 px-2 py-0.5 rounded border border-gray-200 bg-white"
                    >
                      {isExpanded ? 'Hide' : 'Details'}
                    </button>
                  )}
                  {onWaive && finding.status === 'failed' && finding.status !== 'waived' && (
                    <button
                      onClick={() => onWaive(finding)}
                      className="text-xs text-gray-500 hover:text-gray-700 px-2 py-0.5 rounded border border-gray-200 bg-white"
                    >
                      Waive
                    </button>
                  )}
                </div>
              </div>

              {isExpanded && finding.details && (
                <pre className="mt-2 text-xs bg-white/60 rounded p-2 overflow-auto max-h-40">
                  {JSON.stringify(finding.details, null, 2)}
                </pre>
              )}
            </div>
          );
        })}

      {passed.length > 0 && (
        <details className="group">
          <summary className="text-xs text-gray-500 cursor-pointer list-none flex items-center gap-1">
            <span className="group-open:rotate-90 inline-block transition-transform">▶</span>
            {passed.length} passed checks
          </summary>
          <div className="mt-2 space-y-1">
            {passed.map(f => (
              <div key={f.id || f.validator_type} className="text-xs text-green-700 flex items-center gap-1">
                <span>✓</span> {f.title}
              </div>
            ))}
          </div>
        </details>
      )}
    </div>
  );
}
