import { StatusBadge } from '../common/StatusBadge';
import { ApprovalBadge } from '../common/ApprovalBadge';

function formatDate(v) {
  if (!v) return '';
  try { return new Date(v).toLocaleString(); } catch { return String(v); }
}

export function BotReportTimeline({ reports = [] }) {
  if (!reports.length) {
    return <div className="text-sm text-muted">No bot reports yet.</div>;
  }
  return (
    <div className="bot-timeline">
      {reports.map((r) => (
        <div className="bot-timeline__item" key={r.id}>
          <div className="bot-timeline__meta">
            {formatDate(r.created_at)} • mode: {r.mode}
          </div>
          <div className="bot-timeline__title">
            {String(r.action || '').replace(/_/g, ' ')}
          </div>
          <div className="flex gap-2 mt-1 flex-wrap">
            <ApprovalBadge status={r.approval_status} />
            <StatusBadge status={r.publishing_status} />
          </div>
          {r.understanding && (
            <div className="bot-timeline__body mt-2"><strong>Understood:</strong> {r.understanding}</div>
          )}
          {r.recommended_action && (
            <div className="bot-timeline__body mt-1"><strong>Recommended:</strong> {r.recommended_action}</div>
          )}
          {r.action_taken && (
            <div className="bot-timeline__body mt-1"><strong>Action taken:</strong> {r.action_taken}</div>
          )}
          {r.next_recommended_action && (
            <div className="bot-timeline__body mt-1"><strong>Next:</strong> {r.next_recommended_action}</div>
          )}
        </div>
      ))}
    </div>
  );
}
