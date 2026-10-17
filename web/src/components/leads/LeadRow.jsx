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
      <td>
        <div>{lead.source_kind || '—'}</div>
        {(lead.utm_source || lead.utm_campaign) && (
          <div className="text-xs text-muted">utm: {[lead.utm_source, lead.utm_campaign].filter(Boolean).join(' / ')}</div>
        )}
        {lead.ref_code && <div className="text-xs text-muted">ref: {lead.ref_code}</div>}
      </td>
      <td><StatusBadge status={lead.engage_push_status} /></td>
      <td className="text-xs text-muted">
        <div>{lead.engage_lead_code || '—'}</div>
        {lead.engage_stage && <div>stage: {lead.engage_stage.replace(/_/g, ' ')}</div>}
      </td>
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
