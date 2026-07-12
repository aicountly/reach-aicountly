/** Renders a pill for feature availability. Planned features are clearly flagged. */
export function FeatureAvailabilityBadge({ availability }) {
  const map = {
    available:  { label: 'Available',   bg: '#d1fae5', color: '#065f46' },
    limited:    { label: 'Limited',     bg: '#fef3c7', color: '#92400e' },
    beta:       { label: 'Beta',        bg: '#dbeafe', color: '#1e40af' },
    planned:    { label: 'Planned',     bg: '#f0f9ff', color: '#0369a1', border: '1px dashed #0369a1' },
    deprecated: { label: 'Deprecated',  bg: '#ede9fe', color: '#5b21b6' },
    unknown:    { label: 'Unknown',     bg: '#f3f4f6', color: '#6b7280' },
  };
  const s = map[availability] || { label: availability || '—', bg: '#f3f4f6', color: '#6b7280' };
  return (
    <span style={{
      display: 'inline-block',
      padding: '2px 8px',
      borderRadius: 12,
      fontSize: 11,
      fontWeight: 600,
      background: s.bg,
      color: s.color,
      border: s.border || 'none',
    }}>
      {s.label}
    </span>
  );
}
