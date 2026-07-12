import { ContentStatusBadge } from './ContentStatusBadge';
import { ContentTypeBadge } from './ContentTypeBadge';
import { Plus } from 'lucide-react';

const SLOT_COLORS = {
  planned:             '#e0e7ff',
  drafted:             '#fef9c3',
  validation_pending:  '#fef3c7',
  review_pending:      '#dbeafe',
  approved:            '#d1fae5',
  scheduled:           '#e0e7ff',
  blocked:             '#fee2e2',
  completed:           '#a7f3d0',
};

export function DailyPackSlot({ slot, onAssign, canManage = false }) {
  const bgColor = SLOT_COLORS[slot.content_item?.workflow_status] || '#f9fafb';
  const isPlaceholder = slot.is_placeholder || !slot.content_item_id;

  return (
    <div style={{
      background: isPlaceholder ? '#f9fafb' : bgColor,
      border: `2px ${isPlaceholder ? 'dashed' : 'solid'} ` + (isPlaceholder ? '#d1d5db' : '#e5e7eb'),
      borderRadius: 8,
      padding: 12,
      minHeight: 80,
      display: 'flex',
      flexDirection: 'column',
      gap: 6,
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <ContentTypeBadge type={slot.slot_type} />
        {slot.priority && (
          <span style={{ fontSize: 10, color: '#9ca3af' }}>P{slot.priority}</span>
        )}
      </div>

      {isPlaceholder ? (
        <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 6 }}>
          <div style={{ color: '#9ca3af', fontSize: 12 }}>Missing slot</div>
          {canManage && (
            <button className="btn btn-ghost btn-sm" onClick={() => onAssign?.(slot)}>
              <Plus size={12} /> Assign content
            </button>
          )}
        </div>
      ) : (
        <>
          <div style={{ fontWeight: 600, fontSize: 12 }}>
            {slot.slot_label || `Content #${slot.content_item_id}`}
          </div>
          {slot.content_item && (
            <ContentStatusBadge status={slot.content_item.workflow_status} />
          )}
        </>
      )}
    </div>
  );
}
