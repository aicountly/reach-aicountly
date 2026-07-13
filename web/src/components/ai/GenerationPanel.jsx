import React, { useState, useCallback } from 'react';
import { requestGeneration, getGeneration } from '../../services/aiService.js';
import AiGenerationBadge from './AiGenerationBadge.jsx';
import ValidationFindings from './ValidationFindings.jsx';

/**
 * Phase 3 — AI Draft Generation Panel.
 *
 * Allows authorised users to request AI-generated draft content.
 * AI-generated content MUST be approved by a human before publication.
 * This panel displays status only — it does NOT publish or approve content.
 */
export default function GenerationPanel({
  contentItemId,
  contentType,
  taskType = 'draft_generation',
  onDraftReady = null,
  disabled = false,
}) {
  const [status, setStatus] = useState('idle');
  const [requestUuid, setRequestUuid] = useState(null);
  const [request, setRequest] = useState(null);
  const [findings, setFindings] = useState([]);
  const [error, setError] = useState(null);

  const pollUntilComplete = useCallback(async (uuid) => {
    let attempts = 0;

    const poll = async () => {
      if (attempts > 60) {
        setError('Generation timed out. Please check the AI Control Centre for status.');
        return;
      }

      try {
        const data = await getGeneration(uuid);
        const req  = data.request || data;
        setRequest(req);
        setStatus(req.status);

        if (['completed', 'failed', 'cancelled', 'blocked'].includes(req.status)) {
          if (req.status === 'completed' && onDraftReady) {
            onDraftReady(req);
          }
          if (data.artifact?.schema_validation_status === 'failed') {
            setError('AI output failed schema validation and was not saved as a draft.');
          }
          return;
        }

        attempts++;
        setTimeout(poll, 3000);
      } catch (e) {
        setError(e.message);
      }
    };

    await poll();
  }, [onDraftReady]);

  const handleGenerate = useCallback(async () => {
    if (disabled) return;

    setError(null);
    setStatus('requesting');
    setFindings([]);
    setRequest(null);

    try {
      const data = await requestGeneration({
        task_type:       taskType,
        content_type:    contentType,
        content_item_id: contentItemId,
        parameters:      { instructions: `Generate a draft ${contentType}.` },
      });

      const req = data.request || data;
      setRequestUuid(req.uuid);
      setRequest(req);
      setStatus(req.status);

      await pollUntilComplete(req.uuid);
    } catch (e) {
      setError(e.message);
      setStatus('failed');
    }
  }, [contentItemId, contentType, taskType, disabled, pollUntilComplete]);

  const isActive = ['requesting', 'pending', 'grounding', 'queued', 'processing', 'validating'].includes(status);

  return (
    <div className="border border-gray-200 rounded-lg p-4 bg-gray-50 space-y-3">
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-sm font-semibold text-gray-800">AI Draft Generation</h4>
          <p className="text-xs text-gray-500 mt-0.5">
            AI generates a draft for human review and approval. AI content is never auto-published.
          </p>
        </div>
        {request && <AiGenerationBadge status={request.status} />}
      </div>

      {error && (
        <div className="text-xs text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2" role="alert">
          {error}
        </div>
      )}

      {request?.status === 'completed' && (
        <div className="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">
          Draft generated and saved for review. A human approver must review and approve before publication.
        </div>
      )}

      {findings.length > 0 && (
        <ValidationFindings findings={findings} runStatus={request?.status} />
      )}

      <div className="flex items-center gap-2">
        <button
          onClick={handleGenerate}
          disabled={disabled || isActive}
          className={`px-4 py-1.5 text-sm rounded font-medium transition-colors ${
            disabled || isActive
              ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
              : 'bg-blue-600 text-white hover:bg-blue-700'
          }`}
          aria-label="Generate AI draft"
        >
          {isActive ? (
            <span className="flex items-center gap-1.5">
              <svg className="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              Generating…
            </span>
          ) : 'Generate Draft'}
        </button>

        {requestUuid && (
          <a
            href={`/ai/generations/${requestUuid}`}
            className="text-xs text-blue-600 hover:text-blue-800 underline"
            target="_blank"
            rel="noopener noreferrer"
          >
            View in AI Centre
          </a>
        )}
      </div>

      <p className="text-xs text-gray-400">
        Requires <code>ai.generate</code> permission. Uses only approved product knowledge.
      </p>
    </div>
  );
}
