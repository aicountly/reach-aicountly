import { useState } from 'react';
import { ChartFrame } from './ChartTooltip';
import { useChartTooltip } from './useChartTooltip';
import { useTweenedSeries } from './useChartAnimation';

/**
 * Horizontal bar list (lead events, keyword counts).
 *
 * Bars grow from zero on load and re-length when the data changes; hovering a
 * row anywhere — label, track or count — shows the same readout the SVG charts
 * use.
 */
export function BarChart({
  data,
  labelKey = 'label',
  valueKey = 'value',
  color = 'var(--color-primary)',
}) {
  const { containerRef, tooltip, showTooltip, hideTooltip } = useChartTooltip();
  const [activeIndex, setActiveIndex] = useState(null);

  const rows = Array.isArray(data) ? data : [];
  const series = rows.map((d) => Number(d?.[valueKey]) || 0);
  const tweened = useTweenedSeries(series);

  if (rows.length === 0) return null;

  const max = Math.max(...series, 0);

  const clear = () => {
    setActiveIndex(null);
    hideTooltip();
  };

  const activate = (event, i) => {
    setActiveIndex(i);
    showTooltip(event, {
      label: rows[i]?.[labelKey] ?? `Item ${i + 1}`,
      value: series[i].toLocaleString(),
      color,
    });
  };

  return (
    <ChartFrame containerRef={containerRef} tooltip={tooltip} className="chart-frame--bar">
      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
        {rows.map((item, i) => (
          <div
            key={i}
            className="chart-bar-row chart-hit flex items-center gap-4"
            style={{ opacity: activeIndex === null || activeIndex === i ? 1 : 0.6 }}
            tabIndex={0}
            role="img"
            aria-label={`${item?.[labelKey] ?? `Item ${i + 1}`}: ${series[i]}`}
            onMouseEnter={(e) => activate(e, i)}
            onMouseMove={(e) => activate(e, i)}
            onMouseLeave={clear}
            onFocus={(e) => activate(e, i)}
            onBlur={clear}
          >
            <span className="text-sm" style={{ minWidth: 100 }}>{item?.[labelKey]}</span>
            <div className="chart-bar-track" style={{ flex: 1 }}>
              <div
                className="chart-bar-fill"
                style={{
                  width: max > 0 ? `${((tweened[i] ?? 0) / max) * 100}%` : '0%',
                  background: color,
                }}
              />
            </div>
            <span className="text-sm" style={{ fontWeight: 600, minWidth: 40, textAlign: 'right' }}>
              {series[i].toLocaleString()}
            </span>
          </div>
        ))}
      </div>
    </ChartFrame>
  );
}
