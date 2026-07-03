export function Card({ title, actions, children, footer, padding = true }) {
  return (
    <div className="card">
      {(title || actions) && (
        <div className="card-header">
          <div>{title}</div>
          {actions && <div className="flex gap-2">{actions}</div>}
        </div>
      )}
      <div className={padding ? 'card-body' : undefined}>{children}</div>
      {footer && (
        <div style={{ padding: '0.75rem 1rem', borderTop: '1px solid var(--color-border)' }}>{footer}</div>
      )}
    </div>
  );
}
