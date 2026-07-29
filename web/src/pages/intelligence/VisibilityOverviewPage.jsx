import { useEffect, useState } from 'react';
import { Eye, CheckCircle, XCircle } from 'lucide-react';
import {
  listVisibilityPrompts,
  listVisibilityRuns,
  listVisibilityObservations,
} from '../../services/intelligenceService.js';

export default function VisibilityOverviewPage() {
  const [cards, setCards] = useState([
    { label: 'Active Prompts', value: '—' },
    { label: 'Runs loaded', value: '—' },
    { label: 'Brand Mentions', value: '—' },
    { label: 'Not Mentioned', value: '—' },
  ]);
  const [recentRuns, setRecentRuns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    Promise.allSettled([
      listVisibilityPrompts(),
      listVisibilityRuns(),
      listVisibilityObservations(),
    ]).then(([promptsRes, runsRes, obsRes]) => {
      if (cancelled) return;

      const prompts = promptsRes.status === 'fulfilled'
        ? (Array.isArray(promptsRes.value) ? promptsRes.value : (promptsRes.value?.prompts || promptsRes.value?.data || []))
        : [];
      const runs = runsRes.status === 'fulfilled'
        ? (Array.isArray(runsRes.value) ? runsRes.value : (runsRes.value?.runs || runsRes.value?.data || []))
        : [];
      const observations = obsRes.status === 'fulfilled'
        ? (Array.isArray(obsRes.value) ? obsRes.value : (obsRes.value?.observations || obsRes.value?.data || []))
        : [];

      const activePrompts = prompts.filter((p) => (p.status || '').toLowerCase() === 'active').length || prompts.length;
      const mentioned = observations.filter((o) => o.brand_mentioned === true || o.mentioned === true).length;
      const notMentioned = observations.filter((o) => o.brand_mentioned === false || o.mentioned === false).length;

      setCards([
        { label: 'Active Prompts', value: String(activePrompts) },
        { label: 'Runs loaded', value: String(runs.length) },
        { label: 'Brand Mentions', value: String(mentioned) },
        { label: 'Not Mentioned', value: String(notMentioned) },
      ]);
      setRecentRuns(runs.slice(0, 20));

      if (promptsRes.status === 'rejected' && runsRes.status === 'rejected' && obsRes.status === 'rejected') {
        setError(promptsRes.reason?.message || 'Failed to load visibility data');
      }
    }).finally(() => {
      if (!cancelled) setLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <Eye size={26} style={{ color: 'var(--color-primary)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>AI Visibility Monitoring</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Track how AI assistants mention your brand
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      <div className="grid grid-4" style={{ marginBottom: '1.25rem' }}>
        {cards.map((c) => (
          <div key={c.label} className="stat-tile" style={{ textAlign: 'center' }}>
            <div className="stat-tile__value">{loading ? '…' : c.value}</div>
            <div className="stat-tile__label">{c.label}</div>
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: '1rem' }}>
        <div className="card-header">Recent Visibility Runs</div>
        <div className="card-body" style={{ padding: 0 }}>
          {!loading && recentRuns.length === 0 ? (
            <p className="text-sm text-muted" style={{ margin: 0, padding: '1.25rem', textAlign: 'center' }}>
              No visibility runs recorded yet.
            </p>
          ) : (
            <div className="table-wrap" style={{ border: 'none', borderRadius: 0 }}>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Prompt / Run</th>
                    <th>Status</th>
                    <th>Brand Mentioned</th>
                    <th>Ran At</th>
                  </tr>
                </thead>
                <tbody>
                  {recentRuns.map((r) => {
                    const mentioned = r.brand_mentioned ?? r.mentioned ?? null;
                    return (
                      <tr key={r.id || r.uuid}>
                        <td>{r.prompt_name || r.prompt || `Run #${r.id}`}</td>
                        <td>
                          <span className={`badge ${r.status === 'completed' ? 'badge-success' : r.status === 'failed' ? 'badge-danger' : 'badge-secondary'}`}>
                            {r.status || 'unknown'}
                          </span>
                        </td>
                        <td>
                          {mentioned === true && <CheckCircle size={16} style={{ color: 'var(--color-success)' }} />}
                          {mentioned === false && <XCircle size={16} style={{ color: 'var(--color-danger)' }} />}
                          {mentioned == null && <span className="text-muted text-xs">—</span>}
                        </td>
                        <td className="text-xs text-muted">
                          {r.ran_at || r.created_at ? new Date(r.ran_at || r.created_at).toLocaleString() : '—'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
