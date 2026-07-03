import { StatusBadge } from '../common/StatusBadge';

export function EngagePushStatus({ lead }) {
  return (
    <div className="flex flex-col gap-1">
      <StatusBadge status={lead.engage_push_status} />
      {lead.engage_lead_code && (
        <div className="text-xs text-muted">Engage code: {lead.engage_lead_code}</div>
      )}
      {lead.last_push_at && (
        <div className="text-xs text-muted">Last push: {new Date(lead.last_push_at).toLocaleString()}</div>
      )}
      {lead.last_push_error && (
        <div className="text-xs text-danger" style={{ overflowWrap: 'anywhere' }}>{lead.last_push_error}</div>
      )}
    </div>
  );
}
