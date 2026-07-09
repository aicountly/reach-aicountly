export function LineChart({ data, labelKey = 'label', valueKey = 'value', color = 'var(--color-primary)', height = 200 }) {
  if (!data || data.length < 2) return <p className="text-sm text-muted text-center">Not enough data</p>;

  const max = Math.max(...data.map((d) => d[valueKey] || 0)) || 1;
  const width = 600;
  const padding = 40;
  const chartW = width - padding * 2;
  const chartH = height - padding * 2;

  const points = data.map((d, i) => {
    const x = padding + (i / (data.length - 1)) * chartW;
    const y = padding + chartH - ((d[valueKey] || 0) / max) * chartH;
    return `${x},${y}`;
  });

  return (
    <svg viewBox={`0 0 ${width} ${height}`} style={{ width: '100%', height: 'auto' }}>
      <polyline
        fill="none"
        stroke={color}
        strokeWidth="2"
        points={points.join(' ')}
      />
      {data.map((d, i) => {
        const x = padding + (i / (data.length - 1)) * chartW;
        const y = padding + chartH - ((d[valueKey] || 0) / max) * chartH;
        return <circle key={i} cx={x} cy={y} r="3" fill={color} />;
      })}
    </svg>
  );
}
