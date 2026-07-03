import { X } from 'lucide-react';

export function Modal({ open, onClose, title, children, footer, width = 480 }) {
  if (!open) return null;
  return (
    <div style={{
      position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.35)',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      zIndex: 400, padding: 16,
    }} onClick={onClose}>
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width, maxWidth: '100%', background: 'var(--color-surface)',
          borderRadius: 'var(--radius)', boxShadow: 'var(--shadow-md)',
          maxHeight: '90vh', display: 'flex', flexDirection: 'column',
        }}
      >
        <div style={{ padding: '0.85rem 1rem', borderBottom: '1px solid var(--color-border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ fontWeight: 600 }}>{title}</div>
          <button onClick={onClose} className="link-btn" aria-label="Close" style={{ color: 'var(--color-text-muted)' }}>
            <X size={16} />
          </button>
        </div>
        <div style={{ padding: '1rem', overflow: 'auto' }}>{children}</div>
        {footer && <div style={{ padding: '0.75rem 1rem', borderTop: '1px solid var(--color-border)', display: 'flex', justifyContent: 'flex-end', gap: 8 }}>{footer}</div>}
      </div>
    </div>
  );
}
