import { useCallback, useEffect, useState } from 'react';
import { BookOpen, Plus } from 'lucide-react';
import {
  listAllVisibilityPrompts,
  createVisibilityPrompt,
  updateVisibilityPrompt,
  createVisibilityPromptVersion,
  approveVisibilityPromptVersion,
  queueVisibilityPromptRun,
} from '../../services/growthIntelService.js';
import { usePermission } from '../../hooks/usePermission';

const EMPTY = { name: '', question: '', persona: 'India', locale: 'en-IN', intent: 'category' };

/**
 * The buyer questions AI-visibility checks ask every day. Editing a question
 * creates a new version and approves it, so past answers stay tied to the
 * exact wording that produced them.
 */
export default function VisibilityPromptLibraryPage() {
  const { has } = usePermission();
  const canManage = has('visibility.manage');
  const canApprove = has('visibility.review');
  const canRun = has('visibility.execute');
  const [prompts, setPrompts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [draft, setDraft] = useState(EMPTY);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState(null); // { id, text }
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    return listAllVisibilityPrompts()
      .then((rows) => setPrompts(Array.isArray(rows) ? rows : []))
      .catch((e) => setError(e.message || 'Failed to load prompts'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const act = async (fn, ok) => {
    setBusy(true);
    setNotice(null);
    setError(null);
    try {
      await fn();
      if (ok) setNotice(ok);
      await load();
    } catch (e) {
      setError(e.message || 'Request failed');
    } finally {
      setBusy(false);
    }
  };

  const publishText = async (promptId, text) => {
    const version = await createVisibilityPromptVersion(promptId, text);
    if (canApprove && version?.id) await approveVisibilityPromptVersion(promptId, version.id);
  };

  const add = () => act(async () => {
    const created = await createVisibilityPrompt({
      tenant_id: 1,
      name: draft.name.trim(),
      topic: draft.question.trim().slice(0, 240),
      persona: draft.persona.trim(),
      locale: draft.locale.trim() || 'en',
      intent: draft.intent,
      status: 'active',
      schedule_cron: 'daily',
    });
    await publishText(created.id, draft.question.trim());
    setDraft(EMPTY);
    setAdding(false);
  }, canApprove ? 'Prompt added. It is asked in the next daily pass.' : 'Prompt added; its question needs approval (visibility.review) before it is asked.');

  const saveText = () => act(async () => {
    await publishText(editing.id, editing.text.trim());
    setEditing(null);
  }, canApprove ? 'New version approved. Earlier answers stay linked to the old wording.' : 'New version saved; it needs approval before it is asked.');

  return (
    <div>
      <div className="flex items-center justify-between flex-wrap gap-3 mb-4">
        <div className="flex items-center gap-3">
          <BookOpen size={26} style={{ color: 'var(--color-primary)' }} aria-hidden="true" />
          <div>
            <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Visibility Prompt Library</h1>
            <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
              Buyer questions asked to AI assistants every day (cap: Settings → AI &amp; Automation → Visibility prompts per day)
            </p>
          </div>
        </div>
        {canManage && (
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setAdding((v) => !v)}>
            <Plus size={14} aria-hidden="true" /> Add prompt
          </button>
        )}
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {notice && <div className="alert alert-success">{notice}</div>}

      {adding && (
        <div className="card mb-3">
          <div className="card-body form-grid">
            <label className="form-label">Short name (unique)
              <input className="form-input" value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} placeholder="ae-free-invoicing" />
            </label>
            <label className="form-label">Market
              <input className="form-input" value={draft.persona} onChange={(e) => setDraft({ ...draft, persona: e.target.value })} />
            </label>
            <label className="form-label">Locale
              <input className="form-input" value={draft.locale} onChange={(e) => setDraft({ ...draft, locale: e.target.value })} />
            </label>
            <label className="form-label">Intent
              <select className="form-select" value={draft.intent} onChange={(e) => setDraft({ ...draft, intent: e.target.value })}>
                <option value="category">category</option>
                <option value="alternative">alternative</option>
                <option value="comparison">comparison</option>
                <option value="brand">brand</option>
              </select>
            </label>
            <label className="form-label" style={{ gridColumn: '1 / -1' }}>Question, exactly as a buyer would ask it
              <textarea className="form-textarea" rows={2} value={draft.question} onChange={(e) => setDraft({ ...draft, question: e.target.value })} />
            </label>
            <div>
              <button type="button" className="btn btn-primary btn-sm" disabled={busy || !draft.name.trim() || draft.question.trim().length < 10} onClick={add}>Save prompt</button>
            </div>
          </div>
        </div>
      )}

      {loading && <p className="text-sm text-muted">Loading prompts…</p>}
      {!loading && prompts.length === 0 && !error && (
        <div className="card mb-3"><div className="card-body text-sm text-muted">No visibility prompts configured yet.</div></div>
      )}

      {prompts.length > 0 && (
        <div className="table-wrap mb-3">
          <table className="data-table">
            <thead>
              <tr><th>Question (active version)</th><th>Market</th><th>Status</th><th style={{ width: 220 }}>Actions</th></tr>
            </thead>
            <tbody>
              {prompts.map((p) => {
                const status = (p.status || 'draft').toLowerCase();
                const v = p.active_version;
                return (
                  <tr key={p.id}>
                    <td>
                      {editing?.id === p.id ? (
                        <div>
                          <textarea className="form-textarea" rows={2} value={editing.text} onChange={(e) => setEditing({ ...editing, text: e.target.value })} aria-label="Question text" />
                          <div className="flex gap-2 mt-1">
                            <button type="button" className="btn btn-primary btn-sm" disabled={busy || editing.text.trim().length < 10 || editing.text.trim() === v?.prompt_text} onClick={saveText}>Save version</button>
                            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setEditing(null)}>Cancel</button>
                          </div>
                        </div>
                      ) : (
                        <>
                          <div>{v?.prompt_text || <span className="text-muted">No approved version — not asked</span>}</div>
                          <div className="text-xs text-muted">{p.name}{v ? ` · v${v.version_number}` : ''} · {p.intent || '—'} · {p.locale}</div>
                        </>
                      )}
                    </td>
                    <td>{p.persona || '—'}</td>
                    <td>
                      <span className={`badge ${status === 'active' ? 'badge-success' : status === 'paused' ? 'badge-warning' : 'badge-secondary'}`}>{status}</span>
                    </td>
                    <td>
                      <div className="flex flex-wrap gap-1">
                        {canManage && editing?.id !== p.id && (
                          <button type="button" className="btn btn-secondary btn-sm" onClick={() => setEditing({ id: p.id, text: v?.prompt_text || p.topic || '' })}>Edit</button>
                        )}
                        {canManage && (
                          <button type="button" className="btn btn-secondary btn-sm" disabled={busy}
                            onClick={() => act(() => updateVisibilityPrompt(p.id, { status: status === 'active' ? 'paused' : 'active' }))}>
                            {status === 'active' ? 'Pause' : 'Activate'}
                          </button>
                        )}
                        {canRun && v && status === 'active' && (
                          <button type="button" className="btn btn-secondary btn-sm" disabled={busy}
                            onClick={() => act(() => queueVisibilityPromptRun(v.id), 'Queued on every configured assistant.')}>
                            Ask now
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <div className="alert alert-info" style={{ marginBottom: 0 }}>
        <strong>Versioned wording:</strong> an approved question is never edited in place. A change creates a new version, so
        each stored answer stays linked to the exact question that produced it.
      </div>
    </div>
  );
}
