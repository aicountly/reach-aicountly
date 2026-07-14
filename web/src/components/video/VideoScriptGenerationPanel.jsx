import { useState } from 'react';
import api from '../../services/api';

/**
 * Generation panel for triggering AI script generation on a video project.
 *
 * This panel provides the controls for requesting a governed AI script draft.
 * All generated output is a DRAFT and requires editorial review + approval
 * before progressing to render. No auto-approval is performed.
 *
 * Props:
 *  - projectUuid {string} — The video project UUID.
 *  - onGenerated  {Function(result)} — Callback with generation result.
 *  - onError      {Function(msg)} — Callback on generation failure.
 */
export function VideoScriptGenerationPanel({ projectUuid, onGenerated, onError }) {
  const [targetDuration, setTargetDuration] = useState(120);
  const [styleNotes, setStyleNotes]         = useState('');
  const [loading, setLoading]               = useState(false);
  const [lastError, setLastError]           = useState(null);

  const handleGenerate = async () => {
    setLoading(true);
    setLastError(null);
    try {
      const res = await api.post(`/video/projects/${projectUuid}/script/generate`, {
        target_duration_seconds: targetDuration,
        style_notes: styleNotes,
      });
      onGenerated?.(res.data?.data ?? {});
    } catch (e) {
      const msg = e.response?.data?.message ?? e.message ?? 'Generation failed';
      setLastError(msg);
      onError?.(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="generation-panel card">
      <h3 className="card__title">Generate Script</h3>
      <p className="text-muted mb-4">
        AI will generate a governed script draft. The draft requires editorial review and human approval before progressing.
      </p>

      <div className="form-group mb-3">
        <label htmlFor="gen-duration" className="form-label">Target duration (seconds)</label>
        <input
          id="gen-duration"
          type="number"
          min={10}
          max={3600}
          step={10}
          value={targetDuration}
          onChange={e => setTargetDuration(Number(e.target.value))}
          className="form-input"
          disabled={loading}
        />
        <p className="form-hint">{Math.floor(targetDuration / 60)}m {targetDuration % 60}s</p>
      </div>

      <div className="form-group mb-4">
        <label htmlFor="gen-style" className="form-label">Style notes (optional)</label>
        <textarea
          id="gen-style"
          rows={3}
          value={styleNotes}
          onChange={e => setStyleNotes(e.target.value)}
          className="form-textarea"
          placeholder="e.g. Upbeat and professional, focus on problem-solution narrative…"
          disabled={loading}
        />
      </div>

      {lastError && (
        <div className="alert alert--error mb-3" role="alert">
          {lastError}
        </div>
      )}

      <button
        className="btn btn--primary"
        onClick={handleGenerate}
        disabled={loading}
        aria-busy={loading}
      >
        {loading ? 'Generating…' : 'Generate script draft'}
      </button>

      <p className="text-muted text-sm mt-2">
        Generation may take 30–60 seconds depending on provider load.
      </p>
    </div>
  );
}
