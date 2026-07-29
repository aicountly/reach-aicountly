import { useEffect, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import { listAttributionConversions } from '../../services/intelligenceService.js';

export default function UnattributedLeadsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listAttributionConversions()
      .then((data) => {
        if (cancelled) return;
        const all = Array.isArray(data) ? data : (data?.conversions || data?.data || []);
        const unattributed = all.filter((c) =>
          c.confidence_state === 'unattributed'
          || c.matching_method === 'unattributed'
          || (!c.first_touchpoint_id && !c.last_touchpoint_id)
        );
        setRows(unattributed);
      })
      .catch((e) => {
        if (!cancelled) setError(e.message || 'Failed to load conversions');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <AlertCircle size={26} style={{ color: 'var(--color-danger)' }} />
        <div>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Unattributed Leads</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Conversions with no attributable touchpoint
          </p>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {loading && <p className="text-sm text-muted">Loading…</p>}

      {!loading && rows.length === 0 && !error && (
        <div className="card">
          <div className="card-body" style={{ textAlign: 'center' }}>
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No unattributed conversions found in live data.
            </p>
          </div>
        </div>
      )}

      {!loading && rows.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>UUID</th>
                <th>Lead ID</th>
                <th>Conversion Date</th>
                <th>Confidence</th>
                <th>Method</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((l) => (
                <tr key={l.id || l.uuid}>
                  <td style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>{l.uuid?.slice?.(0, 8) || l.id}</td>
                  <td>{l.lead_id ?? '—'}</td>
                  <td>{l.converted_at?.slice?.(0, 10) || '—'}</td>
                  <td>{l.confidence_state || '—'}</td>
                  <td>{l.matching_method || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="text-xs text-muted" style={{ marginTop: '1rem' }}>
        Email addresses are stored as one-way hashes. Raw PII is never retained in the attribution system.
      </p>
    </div>
  );
}
