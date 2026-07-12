/** Renders authority score and source type for a source record. */
export function SourceAuthorityBadge({ source }) {
  const score = source?.authority_score;
  const color = score >= 80 ? '#065f46' : score >= 50 ? '#92400e' : '#6b7280';
  const bg    = score >= 80 ? '#d1fae5' : score >= 50 ? '#fef3c7' : '#f3f4f6';

  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      fontSize: 11, fontWeight: 600,
    }}>
      <span style={{ background: '#e0f2fe', color: '#0369a1', borderRadius: 6, padding: '1px 6px' }}>
        {source?.source_type?.replace('_', ' ') || 'unknown'}
      </span>
      {score != null && (
        <span style={{ background: bg, color, borderRadius: 6, padding: '1px 6px' }}>
          Auth: {score}
        </span>
      )}
    </span>
  );
}
