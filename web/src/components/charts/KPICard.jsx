export function KPICard({ title, value, subtitle, icon: Icon, color = 'var(--color-primary)' }) {
  return (
    <div className="card" style={{ padding: '1.25rem' }}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted" style={{ marginBottom: '0.25rem' }}>{title}</p>
          <p style={{ fontSize: '1.75rem', fontWeight: 700, color }}>{value}</p>
          {subtitle && <p className="text-sm text-muted" style={{ marginTop: '0.25rem' }}>{subtitle}</p>}
        </div>
        {Icon && (
          <div style={{
            width: 48, height: 48, borderRadius: 12,
            background: `${color}15`, display: 'flex', alignItems: 'center', justifyContent: 'center',
          }}>
            <Icon size={24} style={{ color }} />
          </div>
        )}
      </div>
    </div>
  );
}
