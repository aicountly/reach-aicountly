export function PieChart({ data, labelKey = 'label', valueKey = 'value', size = 180 }) {
  if (!data || data.length === 0) return null;

  const total = data.reduce((sum, d) => sum + (d[valueKey] || 0), 0);
  if (total === 0) return <p className="text-sm text-muted text-center">No data</p>;

  const colors = ['#25b003', '#16a34a', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#ea580c'];
  const cx = size / 2;
  const cy = size / 2;
  const r = size * 0.35;

  const segments = [];
  {
    let angle = -90;
    for (let i = 0; i < data.length; i++) {
      const d = data[i];
      const pct = (d[valueKey] || 0) / total;
      const sweep = pct * 360;
      const endAngle = angle + sweep;
      const largeArc = sweep > 180 ? 1 : 0;
      const x1 = cx + r * Math.cos((angle * Math.PI) / 180);
      const y1 = cy + r * Math.sin((angle * Math.PI) / 180);
      const x2 = cx + r * Math.cos((endAngle * Math.PI) / 180);
      const y2 = cy + r * Math.sin((endAngle * Math.PI) / 180);
      const path = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`;
      segments.push({ path, color: colors[i % colors.length], label: d[labelKey], value: d[valueKey] });
      angle = endAngle;
    }
  }

  return (
    <div className="flex items-center gap-4">
      <svg width={size} height={size}>
        {segments.map((seg, i) => (
          <path key={i} d={seg.path} fill={seg.color} stroke="white" strokeWidth="2" />
        ))}
      </svg>
      <div className="flex-col gap-2">
        {segments.map((seg, i) => (
          <div key={i} className="flex items-center gap-2 text-sm" style={{ marginBottom: 4 }}>
            <span style={{ width: 10, height: 10, borderRadius: 2, background: seg.color, display: 'inline-block' }} />
            <span>{seg.label}: {seg.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
