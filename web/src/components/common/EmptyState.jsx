import { Inbox } from 'lucide-react';

export function EmptyState({ message = 'No records to display yet.', icon }) {
  const IconComp = icon || Inbox;
  return (
    <div style={{
      padding: '2rem 1rem',
      textAlign: 'center',
      color: 'var(--color-text-muted)',
      background: 'var(--color-surface)',
      border: '1px dashed var(--color-border)',
      borderRadius: 'var(--radius)',
    }}>
      <IconComp size={28} style={{ opacity: 0.6 }} />
      <div className="text-sm mt-2">{message}</div>
    </div>
  );
}
