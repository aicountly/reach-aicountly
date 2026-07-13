import React from 'react';

/**
 * Phase 3 — Displays the current status of an AI generation request as a badge.
 */
const STATUS_CONFIG = {
  pending:    { label: 'Pending',    bg: 'bg-yellow-100', text: 'text-yellow-800' },
  grounding:  { label: 'Grounding', bg: 'bg-blue-100',   text: 'text-blue-800' },
  queued:     { label: 'Queued',    bg: 'bg-gray-100',   text: 'text-gray-800' },
  processing: { label: 'Generating', bg: 'bg-purple-100', text: 'text-purple-800' },
  validating: { label: 'Validating', bg: 'bg-indigo-100', text: 'text-indigo-800' },
  completed:  { label: 'Completed', bg: 'bg-green-100',  text: 'text-green-800' },
  failed:     { label: 'Failed',    bg: 'bg-red-100',    text: 'text-red-800' },
  cancelled:  { label: 'Cancelled', bg: 'bg-gray-100',   text: 'text-gray-500' },
  blocked:    { label: 'Budget Blocked', bg: 'bg-orange-100', text: 'text-orange-800' },
};

export default function AiGenerationBadge({ status, className = '' }) {
  const config = STATUS_CONFIG[status] || { label: status, bg: 'bg-gray-100', text: 'text-gray-800' };

  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${config.bg} ${config.text} ${className}`}
      title={`AI generation status: ${config.label}`}
    >
      {['pending', 'grounding', 'processing', 'validating', 'queued'].includes(status) && (
        <span className="animate-pulse w-1.5 h-1.5 rounded-full bg-current" aria-hidden="true" />
      )}
      {config.label}
    </span>
  );
}
