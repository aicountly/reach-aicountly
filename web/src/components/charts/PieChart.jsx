import { useState } from 'react';
import { ChartFrame } from './ChartTooltip';
import { useChartTooltip } from './useChartTooltip';
import { useTweenedSeries } from './useChartAnimation';

const PIE_COLORS = ['#25b003', '#16a34a', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#ea580c'];

const polar = (cx, cy, r, deg) => [
  cx + r * Math.cos((deg * Math.PI) / 180),
  cy + r * Math.sin((deg * Math.PI) / 180),
];

/**
 * Traffic-sources donut-less pie.
 *
 * Slices sweep out from twelve o'clock on load and re-sweep when the numbers
 * change. Both the slice and its legend row are hover targets for the same
 * readout, so the value is reachable however the pointer arrives.
 */
export function PieChart({ data, labelKey = 'label', valueKey = 'value', size = 180 }) {
  const { containerRef, tooltip, showTooltip, hideTooltip } = useChartTooltip();
  const [activeIndex, setActiveIndex] = useState(null);

  const rows = Array.isArray(data) ? data : [];
  const series = rows.map((d) => Number(d?.[valueKey]) || 0);
  const tweened = useTweenedSeries(series);

  const total = series.reduce((sum, v) => sum + v, 0);
  if (rows.length === 0) return null;
  if (total === 0) return <p className="text-sm text-muted text-center">No data</p>;

  const cx = size / 2;
  const cy = size / 2;
  const r = size * 0.35;

  // Sweeps are driven by the tweened numbers but sized against the real total,
  // so the wedges grow into place instead of all being full-circle at frame one.
  let cursor = -90;
  const segments = rows.map((row, i) => {
    const sweep = ((tweened[i] ?? 0) / total) * 360;
    const start = cursor;
    const end = start + sweep;
    cursor = end;

    const [x1, y1] = polar(cx, cy, r, start);
    const [x2, y2] = polar(cx, cy, r, end);
    const largeArc = sweep > 180 ? 1 : 0;

    return {
      // A wedge with no sweep has no path; a lone 100% wedge is drawn as a full
      // circle, because its start and end points coincide and the arc collapses.
      path: sweep <= 0.01 ? '' : `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`,
      full: sweep >= 359.99,
      mid: (start + end) / 2,
      color: PIE_COLORS[i % PIE_COLORS.length],
      label: row?.[labelKey] ?? `Slice ${i + 1}`,
      value: series[i],
      percent: Math.round((series[i] / total) * 1000) / 10,
    };
  });

  const clear = () => {
    setActiveIndex(null);
    hideTooltip();
  };

  const activate = (event, i) => {
    setActiveIndex(i);
    showTooltip(event, {
      label: segments[i].label,
      value: segments[i].value.toLocaleString(),
      hint: `${segments[i].percent}%`,
      color: segments[i].color,
    });
  };

  return (
    <ChartFrame containerRef={containerRef} tooltip={tooltip} className="chart-frame--pie">
      <div className="flex items-center gap-4">
        <svg width={size} height={size} role="group" aria-label="Share by category" onMouseLeave={clear}>
          {segments.map((seg, i) => {
            // Nudge the active wedge outwards along its own bisector.
            const [ox, oy] = activeIndex === i ? polar(0, 0, size * 0.03, seg.mid) : [0, 0];
            if (!seg.path) return null;

            const shared = {
              className: 'chart-slice chart-hit',
              fill: seg.color,
              stroke: 'var(--color-surface)',
              strokeWidth: 2,
              transform: `translate(${ox} ${oy})`,
              opacity: activeIndex === null || activeIndex === i ? 1 : 0.55,
              tabIndex: 0,
              role: 'img',
              'aria-label': `${seg.label}: ${seg.value} (${seg.percent}%)`,
              onMouseEnter: (e) => activate(e, i),
              onMouseMove: (e) => activate(e, i),
              onFocus: (e) => activate(e, i),
              onBlur: clear,
            };

            return seg.full
              ? <circle key={i} cx={cx} cy={cy} r={r} {...shared} />
              : <path key={i} d={seg.path} {...shared} />;
          })}
        </svg>

        <div className="flex-col gap-2">
          {segments.map((seg, i) => (
            <div
              key={i}
              className="chart-legend-item flex items-center gap-2 text-sm"
              style={{ marginBottom: 4, opacity: activeIndex === null || activeIndex === i ? 1 : 0.55 }}
              tabIndex={0}
              role="img"
              aria-label={`${seg.label}: ${seg.value} (${seg.percent}%)`}
              onMouseEnter={(e) => activate(e, i)}
              onMouseMove={(e) => activate(e, i)}
              onMouseLeave={clear}
              onFocus={(e) => activate(e, i)}
              onBlur={clear}
            >
              <span
                style={{ width: 10, height: 10, borderRadius: 2, background: seg.color, display: 'inline-block' }}
              />
              <span>{seg.label}: {seg.value}</span>
            </div>
          ))}
        </div>
      </div>
    </ChartFrame>
  );
}
