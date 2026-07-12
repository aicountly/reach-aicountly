/** Renders a coloured pill for knowledge entity status values. */
export function KnowledgeStatusBadge({ status }) {
  const map = {
    draft:        { label: 'Draft',        bg: '#e5e7eb', color: '#374151' },
    needs_review: { label: 'Needs Review', bg: '#fef3c7', color: '#92400e' },
    approved:     { label: 'Approved',     bg: '#d1fae5', color: '#065f46' },
    rejected:     { label: 'Rejected',     bg: '#fee2e2', color: '#991b1b' },
    deprecated:   { label: 'Deprecated',   bg: '#ede9fe', color: '#5b21b6' },
    archived:     { label: 'Archived',     bg: '#f3f4f6', color: '#6b7280' },
  };
  const s = map[status] || { label: status || '—', bg: '#f3f4f6', color: '#6b7280' };
  return (
    <span style={{
      display: 'inline-block',
      padding: '2px 8px',
      borderRadius: 12,
      fontSize: 11,
      fontWeight: 600,
      background: s.bg,
      color: s.color,
    }}>
      {s.label}
    </span>
  );
}
