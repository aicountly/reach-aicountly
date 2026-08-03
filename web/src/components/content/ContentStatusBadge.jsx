const STATUS_STYLES = {
  idea:                  { bg: '#e0f2fe', color: '#0369a1' },
  brief:                 { bg: '#fef9c3', color: '#854d0e' },
  brief_draft:           { bg: '#fef9c3', color: '#854d0e' },
  brief_ready:           { bg: '#fef9c3', color: '#854d0e' },
  outline_draft:         { bg: '#fef3c7', color: '#92400e' },
  outline_ready:         { bg: '#fef3c7', color: '#92400e' },
  draft:                 { bg: '#f3f4f6', color: '#374151' },
  draft_generating:      { bg: '#f3f4f6', color: '#374151' },
  fact_verifying:        { bg: '#e0e7ff', color: '#3730a3' },
  fact_verified:         { bg: '#e0e7ff', color: '#3730a3' },
  seo_review:            { bg: '#fce7f3', color: '#9d174d' },
  internal_review:       { bg: '#dbeafe', color: '#1e40af' },
  validation_pending:    { bg: '#fef3c7', color: '#92400e' },
  review_pending:        { bg: '#dbeafe', color: '#1e40af' },
  changes_requested:     { bg: '#fce7f3', color: '#9d174d' },
  approved:              { bg: '#d1fae5', color: '#065f46' },
  scheduled:             { bg: '#e0e7ff', color: '#3730a3' },
  publish_queued:        { bg: '#e0e7ff', color: '#3730a3' },
  publishing:            { bg: '#e0e7ff', color: '#3730a3' },
  ready_for_publication: { bg: '#a7f3d0', color: '#064e3b' },
  published:             { bg: '#6ee7b7', color: '#064e3b' },
  verification_pending:  { bg: '#a7f3d0', color: '#064e3b' },
  live:                  { bg: '#34d399', color: '#064e3b' },
  refresh_due:           { bg: '#fed7aa', color: '#9a3412' },
  archived:              { bg: '#e5e7eb', color: '#6b7280' },
  rejected:              { bg: '#fee2e2', color: '#991b1b' },
  failed:                { bg: '#fee2e2', color: '#991b1b' },
  blocked:               { bg: '#fed7aa', color: '#9a3412' },
};

export function ContentStatusBadge({ status }) {
  const style = STATUS_STYLES[status] || { bg: '#e5e7eb', color: '#6b7280' };
  return (
    <span style={{
      background: style.bg,
      color: style.color,
      borderRadius: 4,
      padding: '2px 8px',
      fontSize: 11,
      fontWeight: 600,
      whiteSpace: 'nowrap',
    }}>
      {status?.replace(/_/g, ' ') || '—'}
    </span>
  );
}
