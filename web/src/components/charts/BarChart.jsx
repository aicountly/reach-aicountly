export function BarChart({ data, labelKey = 'label', valueKey = 'value', color = 'var(--color-primary)' }) {
  if (!data || data.length === 0) return null;
  const max = Math.max(...data.map((d) => d[valueKey] || 0));

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
      {data.map((item, i) => (
        <div key={i} className="flex items-center gap-4">
          <span className="text-sm" style={{ minWidth: 100 }}>{item[labelKey]}</span>
          <div style={{ flex: 1, background: 'var(--color-bg)', borderRadius: 4, height: 24, position: 'relative' }}>
            <div
              style={{
                width: max > 0 ? `${(item[valueKey] / max) * 100}%` : '0%',
                background: color, borderRadius: 4, height: '100%',
                transition: 'width 0.3s ease',
              }}
            />
          </div>
          <span className="text-sm" style={{ fontWeight: 600, minWidth: 40, textAlign: 'right' }}>
            {item[valueKey]}
          </span>
        </div>
      ))}
    </div>
  );
}
