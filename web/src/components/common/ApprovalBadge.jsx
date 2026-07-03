const APPROVAL_COLOR = {
  pending:      'warning',
  approved:     'success',
  rejected:     'danger',
  not_required: 'secondary',
};

export function ApprovalBadge({ status }) {
  const color = APPROVAL_COLOR[status] || 'secondary';
  const label = status ? String(status).replace(/_/g, ' ') : 'unknown';
  return <span className={`badge badge-${color}`}>Approval: {label}</span>;
}
