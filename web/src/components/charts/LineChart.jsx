import { useId, useState } from 'react';
import { ChartFrame } from './ChartTooltip';
import { useChartTooltip } from './useChartTooltip';
import { useTweenedSeries } from './useChartAnimation';

const WIDTH = 600;
const PADDING = 40;
const GRID_LINES = 4;

/**
 * Sessions-style trend line.
 *
 * The line and the area under it tween whenever the data loads or changes, and
 * a full-height hover band sits over every step so the readout appears anywhere
 * along the chart — not only when the pointer lands exactly on a 3px dot.
 */
export function LineChart({
  data,
  labelKey = 'label',
  valueKey = 'value',
  color = 'var(--color-primary)',
  height = 200,
}) {
  const { containerRef, tooltip, showTooltip, hideTooltip } = useChartTooltip();
  const [activeIndex, setActiveIndex] = useState(null);
  const gradientId = useId();

  const rows = Array.isArray(data) ? data : [];
  const series = rows.map((d) => Number(d?.[valueKey]) || 0);
  const tweened = useTweenedSeries(series);

  if (rows.length < 2) {
    return <p className="text-sm text-muted text-center">Not enough data</p>;
  }

  const chartW = WIDTH - PADDING * 2;
  const chartH = height - PADDING * 2;
  const max = Math.max(...series, 0) || 1;
  const step = chartW / (rows.length - 1);

  const xAt = (i) => PADDING + i * step;
  const yAt = (i) => PADDING + chartH - ((tweened[i] ?? 0) / max) * chartH;

  const points = rows.map((_, i) => `${xAt(i)},${yAt(i)}`).join(' ');
  const areaPath = `M ${xAt(0)} ${PADDING + chartH} L ${points.split(' ').join(' L ')} L ${xAt(rows.length - 1)} ${PADDING + chartH} Z`;

  const clear = () => {
    setActiveIndex(null);
    hideTooltip();
  };

  const activate = (event, i) => {
    setActiveIndex(i);
    showTooltip(event, {
      label: rows[i]?.[labelKey] ?? `Point ${i + 1}`,
      value: series[i].toLocaleString(),
      color,
    });
  };

  return (
    <ChartFrame containerRef={containerRef} tooltip={tooltip} className="chart-frame--line">
      <svg
        viewBox={`0 0 ${WIDTH} ${height}`}
        style={{ width: '100%', height: 'auto', display: 'block' }}
        role="group"
        aria-label="Trend chart"
        onMouseLeave={clear}
      >
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity="0.22" />
            <stop offset="100%" stopColor={color} stopOpacity="0" />
          </linearGradient>
        </defs>

        {Array.from({ length: GRID_LINES + 1 }, (_, i) => {
          const y = PADDING + (chartH / GRID_LINES) * i;
          return (
            <line
              key={i}
              x1={PADDING}
              x2={WIDTH - PADDING}
              y1={y}
              y2={y}
              stroke="var(--color-border)"
              strokeWidth="1"
            />
          );
        })}

        <path d={areaPath} fill={`url(#${gradientId})`} />
        <polyline
          className="chart-line"
          fill="none"
          stroke={color}
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          points={points}
        />

        {activeIndex !== null && (
          <line
            className="chart-line__guide"
            x1={xAt(activeIndex)}
            x2={xAt(activeIndex)}
            y1={PADDING}
            y2={PADDING + chartH}
            stroke={color}
            strokeWidth="1"
            strokeDasharray="3 3"
          />
        )}

        {rows.map((_, i) => (
          <circle
            key={`dot-${i}`}
            className="chart-line__dot"
            cx={xAt(i)}
            cy={yAt(i)}
            r={activeIndex === i ? 5 : 3}
            fill={color}
            stroke="var(--color-surface)"
            strokeWidth={activeIndex === i ? 2 : 0}
          />
        ))}

        {/* Full-height bands: hovering anywhere in a step reads out that step. */}
        {rows.map((row, i) => {
          const bandX = Math.max(0, xAt(i) - step / 2);
          const bandW = Math.min(WIDTH, xAt(i) + step / 2) - bandX;
          return (
          <rect
            key={`band-${i}`}
            className="chart-hit"
            x={bandX}
            y={PADDING}
            width={bandW}
            height={chartH}
            fill="transparent"
            tabIndex={0}
            role="img"
            aria-label={`${row?.[labelKey] ?? `Point ${i + 1}`}: ${series[i]}`}
            onMouseEnter={(e) => activate(e, i)}
            onMouseMove={(e) => activate(e, i)}
            onFocus={(e) => activate(e, i)}
            onBlur={clear}
          />
          );
        })}
      </svg>
    </ChartFrame>
  );
}
