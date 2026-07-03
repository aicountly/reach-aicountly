import { StatusBadge } from '../common/StatusBadge';

export function LeadRow({ lead, onPush }) {
  return (
    <tr>
      <td>
        <div style={{ fontWeight: 600 }}>{lead.name}</div>
        {lead.organization && <div className="text-xs text-muted">{lead.organization}</div>}
      </td>
      <td>
        <div>{lead.email || '—'}</div>
        <div className="text-xs text-muted">{lead.mobile || lead.whatsapp || ''}</div>
      </td>
      <td>{lead.source_kind || '—'}</td>
      <td><StatusBadge status={lead.engage_push_status} /></td>
      <td className="text-xs text-muted">{lead.engage_lead_code || '—'}</td>
      <td>
        {onPush && (
          <button className="btn btn-secondary btn-sm" onClick={() => onPush(lead)}>
            Push to Engage
          </button>
        )}
      </td>
    </tr>
  );
}
