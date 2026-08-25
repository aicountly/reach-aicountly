import { useState } from 'react';
import { ChartFrame } from '../charts/ChartTooltip';
import { useChartTooltip } from '../charts/useChartTooltip';
import { useTweenedSeries } from '../charts/useChartAnimation';

/**
 * Circular completeness gauge with percentage label.
 *
 * The arc sweeps in on load and re-sweeps when the score changes; the printed
 * percentage always shows the real value, never a mid-animation frame.
 */
export function CompletenessGauge({ score, percent, size = 80, strokeWidth = 8, label = 'Completeness' }) {
  const { containerRef, tooltip, showTooltip, hideTooltip } = useChartTooltip();
  const [hovered, setHovered] = useState(false);

  const pct = Math.min(100, Math.max(0, percent ?? score ?? 0));
  const [drawnPct] = useTweenedSeries([pct]);

  const r = (size - strokeWidth) / 2;
  const circ = 2 * Math.PI * r;
  const dash = ((drawnPct ?? 0) / 100) * circ;
  const color = pct >= 80 ? '#10b981' : pct >= 50 ? '#f59e0b' : '#ef4444';

  const activate = (event) => {
    setHovered(true);
    showTooltip(event, { label, value: `${pct}%`, color });
  };
  const clear = () => {
    setHovered(false);
    hideTooltip();
  };

  return (
    <ChartFrame containerRef={containerRef} tooltip={tooltip} className="chart-frame--gauge">
      <div style={{ display: 'inline-flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
        <svg
          width={size}
          height={size}
          className="chart-hit"
          tabIndex={0}
          role="img"
          aria-label={`${label}: ${pct}%`}
          onMouseEnter={activate}
          onMouseMove={activate}
          onMouseLeave={clear}
          onFocus={activate}
          onBlur={clear}
        >
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
            strokeWidth={hovered ? strokeWidth + 2 : strokeWidth}
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
    </ChartFrame>
  );
}
