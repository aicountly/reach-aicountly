import { useState } from 'react';
import { CheckCircle, XCircle, AlertTriangle, ShieldOff } from 'lucide-react';
import { contentService } from '../../services/contentService';

const STATUS_ICONS = {
  passed:  <CheckCircle size={14} color="#10b981" />,
  failed:  <XCircle size={14} color="#ef4444" />,
  warning: <AlertTriangle size={14} color="#f59e0b" />,
  waived:  <ShieldOff size={14} color="#8b5cf6" />,
  pending: <AlertTriangle size={14} color="#6b7280" />,
};

export function ValidationPanel({ contentItemId, validations = [], onRefresh, canWaive = false }) {
  const [waivingId, setWaivingId] = useState(null);
  const [error, setError] = useState(null);

  const handleWaive = async (v) => {
    const reason = window.prompt('Waiver reason (required):');
    if (!reason) return;
    setWaivingId(v.id);
    try {
      await contentService.waiveValidation(contentItemId, v.id, reason);
      onRefresh?.();
    } catch (e) {
      setError(e.message);
    } finally {
      setWaivingId(null);
    }
  };

  if (validations.length === 0) {
    return <div style={{ color: '#9ca3af', fontSize: 13 }}>No validation results yet.</div>;
  }

  return (
    <div>
      {error && <div style={{ color: '#ef4444', fontSize: 12, marginBottom: 8 }}>{error}</div>}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        {validations.map((v) => (
          <div key={v.id} style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '8px 12px',
            borderRadius: 6,
            background: v.validation_status === 'failed' && !v.waived_at ? '#fff1f2' : '#f9fafb',
            border: '1px solid ' + (v.validation_status === 'failed' && !v.waived_at ? '#fecdd3' : '#e5e7eb'),
          }}>
            {STATUS_ICONS[v.waived_at ? 'waived' : v.validation_status] || STATUS_ICONS.pending}
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, fontSize: 12 }}>
                {v.validation_type?.replace(/_/g, ' ')}
              </div>
              {v.message && <div style={{ fontSize: 11, color: '#6b7280' }}>{v.message}</div>}
              {v.waived_at && <div style={{ fontSize: 10, color: '#8b5cf6' }}>Waived: {v.waiver_reason}</div>}
            </div>
            {v.score !== null && v.score !== undefined && (
              <span style={{ fontSize: 11, color: '#6b7280' }}>{v.score}%</span>
            )}
            {canWaive && v.validation_status === 'failed' && !v.waived_at && (
              <button
                className="btn btn-ghost btn-sm"
                onClick={() => handleWaive(v)}
                disabled={waivingId === v.id}
                style={{ fontSize: 11 }}
              >
                Waive
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
