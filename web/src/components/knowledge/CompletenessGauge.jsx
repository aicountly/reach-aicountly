/** Circular or bar completeness gauge with percentage label. */
export function CompletenessGauge({ score, percent, size = 80, strokeWidth = 8 }) {
  const pct   = Math.min(100, Math.max(0, percent ?? score ?? 0));
  const r     = (size - strokeWidth) / 2;
  const circ  = 2 * Math.PI * r;
  const dash  = (pct / 100) * circ;
  const color = pct >= 80 ? '#10b981' : pct >= 50 ? '#f59e0b' : '#ef4444';

  return (
    <div style={{ display: 'inline-flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
      <svg width={size} height={size} aria-label={`Completeness: ${pct}%`}>
        <circle
          cx={size / 2} cy={size / 2} r={r}
          fill="none"
          stroke="var(--color-border, #e5e7eb)"
          strokeWidth={strokeWidth}
        />
        <circle
          cx={size / 2} cy={size / 2} r={r}
          fill="none"
          stroke={color}
          strokeWidth={strokeWidth}
          strokeDasharray={`${dash} ${circ - dash}`}
          strokeLinecap="round"
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
        />
        <text
          x={size / 2} y={size / 2 + 5}
          textAnchor="middle"
          fontSize={size * 0.22}
          fontWeight="700"
          fill={color}
        >
          {pct}%
        </text>
      </svg>
    </div>
  );
}
