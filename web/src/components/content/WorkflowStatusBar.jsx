import { ContentStatusBadge } from './ContentStatusBadge';

const ORDERED_STATES = [
  'idea', 'brief', 'draft', 'validation_pending', 'review_pending',
  'approved', 'scheduled', 'ready_for_publication', 'published',
];

export function WorkflowStatusBar({ currentStatus, nextStatuses = [] }) {
  const currentIndex = ORDERED_STATES.indexOf(currentStatus);

  return (
    <div style={{ overflowX: 'auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 0, minWidth: 600 }}>
        {ORDERED_STATES.map((state, idx) => {
          const isCurrent  = state === currentStatus;
          const isPast     = idx < currentIndex;
          const isNext     = nextStatuses.includes(state);

          return (
            <div key={state} style={{ display: 'flex', alignItems: 'center' }}>
              <div style={{
                padding: '5px 12px',
                borderRadius: 4,
                fontSize: 11,
                fontWeight: isCurrent ? 700 : 500,
                background: isCurrent ? '#3b82f6' : isPast ? '#d1fae5' : isNext ? '#fef9c3' : '#f3f4f6',
                color: isCurrent ? '#fff' : isPast ? '#065f46' : isNext ? '#854d0e' : '#9ca3af',
                border: isCurrent ? '2px solid #2563eb' : '1px solid transparent',
                whiteSpace: 'nowrap',
              }}>
                {state.replace(/_/g, ' ')}
              </div>
              {idx < ORDERED_STATES.length - 1 && (
                <div style={{ width: 12, height: 2, background: isPast ? '#10b981' : '#e5e7eb' }} />
              )}
            </div>
          );
        })}
        {['changes_requested', 'archived', 'rejected'].includes(currentStatus) && (
          <div style={{ marginLeft: 16 }}>
            <ContentStatusBadge status={currentStatus} />
          </div>
        )}
      </div>
    </div>
  );
}
