import { AlertTriangle } from 'lucide-react';

/** Renders a risk level pill for product claims. High/critical include a warning icon. */
export function ClaimRiskBadge({ risk, riskLevel }) {
  const map = {
    low:      { label: 'Low',      bg: '#d1fae5', color: '#065f46', icon: false },
    medium:   { label: 'Medium',   bg: '#fef3c7', color: '#92400e', icon: false },
    high:     { label: 'High',     bg: '#fee2e2', color: '#991b1b', icon: true },
    critical: { label: 'Critical', bg: '#7f1d1d', color: '#fef2f2', icon: true },
  };
  const level = risk ?? riskLevel;
  const s = map[level] || { label: level || '—', bg: '#f3f4f6', color: '#6b7280', icon: false };
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      padding: '2px 8px',
      borderRadius: 12,
      fontSize: 11,
      fontWeight: 600,
      background: s.bg,
      color: s.color,
    }}>
      {s.icon && <AlertTriangle size={10} />}
      {s.label}
    </span>
  );
}
