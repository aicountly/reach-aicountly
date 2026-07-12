import { Check, X, RotateCcw, AlertTriangle } from 'lucide-react';
import { ContentRiskBadge } from './ContentRiskBadge';
import { ContentStatusBadge } from './ContentStatusBadge';

export function ApprovalCard({ item, onApprove, onReject, onReturn, canApprove = false, canReview = false }) {
  const isHighRisk = ['high', 'critical'].includes(item.risk_level);

  return (
    <div style={{
      background: '#fff',
      border: '1px solid ' + (isHighRisk ? '#fed7aa' : '#e5e7eb'),
      borderRadius: 8,
      padding: 16,
      display: 'flex',
      flexDirection: 'column',
      gap: 10,
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div>
          <div style={{ fontWeight: 700, fontSize: 14 }}>{item.title}</div>
          <div style={{ fontSize: 11, color: '#6b7280', marginTop: 2 }}>
            {item.content_type?.replace(/_/g, ' ')} · #{item.id}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <ContentRiskBadge level={item.risk_level} />
          <ContentStatusBadge status={item.workflow_status} />
        </div>
      </div>

      {item.summary && (
        <div style={{ fontSize: 12, color: '#374151', lineHeight: 1.5 }}>{item.summary}</div>
      )}

      <div style={{ display: 'flex', gap: 12, fontSize: 11, color: '#6b7280' }}>
        {item.review_due_at && (
          <span style={{ color: new Date(item.review_due_at) < new Date() ? '#ef4444' : undefined }}>
            Due: {new Date(item.review_due_at).toLocaleDateString()}
          </span>
        )}
        {item.validation_status && <span>Validation: {item.validation_status}</span>}
        {item.primary_product_id && <span>Product: #{item.primary_product_id}</span>}
      </div>

      {isHighRisk && (
        <div style={{ fontSize: 11, color: '#f97316', display: 'flex', alignItems: 'center', gap: 4 }}>
          <AlertTriangle size={12} /> High-risk content — individual approval required, bulk disabled
        </div>
      )}

      {(canApprove || canReview) && item.workflow_status === 'review_pending' && (
        <div style={{ display: 'flex', gap: 8, marginTop: 4 }}>
          {canApprove && (
            <button className="btn btn-primary btn-sm" onClick={() => onApprove?.(item)}>
              <Check size={13} /> Approve
            </button>
          )}
          {canReview && (
            <>
              <button className="btn btn-warning btn-sm" onClick={() => onReturn?.(item)}>
                <RotateCcw size={13} /> Return
              </button>
              <button className="btn btn-danger btn-sm" onClick={() => onReject?.(item)}>
                <X size={13} /> Reject
              </button>
            </>
          )}
        </div>
      )}
    </div>
  );
}
