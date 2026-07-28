import { useEffect, useState } from 'react';
import { BookOpen, CheckCircle, Circle } from 'lucide-react';
import { listVisibilityPrompts } from '../../services/intelligenceService.js';

export default function VisibilityPromptLibraryPage() {
  const [prompts, setPrompts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listVisibilityPrompts()
      .then((data) => {
        if (cancelled) return;
        const rows = Array.isArray(data) ? data : (data?.prompts || data?.data || []);
        setPrompts(rows);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load prompts');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return (
    <div style={{ padding: '1.5rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <BookOpen size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Visibility Prompt Library</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Governed AI visibility monitoring prompts
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading prompts…</p>}

      {!loading && prompts.length === 0 && !error && (
        <div className="card" style={{ marginBottom: '1rem' }}>
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No visibility prompts configured yet.
            </p>
          </div>
        </div>
      )}

      {!loading && prompts.length > 0 && (
        <div className="table-wrap" style={{ marginBottom: '1rem' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Prompt Name</th>
                <th>Topic</th>
                <th>Status</th>
                <th>Schedule</th>
                <th style={{ textAlign: 'center' }}>Active Version</th>
              </tr>
            </thead>
            <tbody>
              {prompts.map((p) => {
                const status = (p.status || 'draft').toLowerCase();
                return (
                  <tr key={p.id}>
                    <td style={{ fontWeight: 600 }}>{p.name || p.title || `Prompt #${p.id}`}</td>
                    <td>{p.topic || p.purpose || '—'}</td>
                    <td>
                      <span className={`badge ${status === 'active' ? 'badge-success' : status === 'paused' ? 'badge-warning' : 'badge-secondary'}`}>
                        {status}
                      </span>
                    </td>
                    <td style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>{p.schedule_cron || 'manual'}</td>
                    <td style={{ textAlign: 'center' }}>
                      {status === 'active' || p.has_active_version
                        ? <CheckCircle size={16} style={{ color: 'var(--color-success)' }} />
                        : <Circle size={16} style={{ color: 'var(--color-border)' }} />}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <div className="alert alert-info" style={{ marginBottom: 0 }}>
        <strong>Immutability guarantee:</strong> Once a prompt version is approved, its text cannot be modified.
        Changes create a new version requiring fresh approval.
      </div>
    </div>
  );
}
