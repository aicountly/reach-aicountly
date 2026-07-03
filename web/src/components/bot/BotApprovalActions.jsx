import { useState } from 'react';
import { Check, X } from 'lucide-react';

export function BotApprovalActions({ onApprove, onReject, disabled }) {
  const [rejectNote, setRejectNote] = useState('');
  const [showReject, setShowReject] = useState(false);
  return (
    <div className="flex flex-col gap-2">
      <div className="flex gap-2">
        <button
          className="btn btn-primary btn-sm"
          disabled={disabled}
          onClick={onApprove}
        >
          <Check size={14} /> Approve
        </button>
        <button
          className="btn btn-danger btn-sm"
          disabled={disabled}
          onClick={() => setShowReject((v) => !v)}
        >
          <X size={14} /> Reject
        </button>
      </div>
      {showReject && (
        <div className="flex flex-col gap-2">
          <textarea
            rows={2}
            placeholder="Reason for rejection (optional)"
            value={rejectNote}
            onChange={(e) => setRejectNote(e.target.value)}
          />
          <div className="flex gap-2 justify-end">
            <button className="btn btn-secondary btn-sm" onClick={() => setShowReject(false)}>Cancel</button>
            <button className="btn btn-danger btn-sm" onClick={() => { onReject(rejectNote); setShowReject(false); setRejectNote(''); }}>
              Confirm reject
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
