/**
 * The readout every chart shows on hover or keyboard focus, and the positioning
 * context it lives in. See `useChartTooltip` for the state that drives it.
 */
export function ChartTooltip({ tooltip }) {
  if (!tooltip) return null;

  const { x, y, align, flip, label, value, hint, color } = tooltip;

  return (
    <div
      className={`chart-tooltip chart-tooltip--${align}${flip ? ' chart-tooltip--below' : ''}`}
      role="tooltip"
      style={{ left: `${x}px`, top: `${y}px` }}
    >
      {color && <span className="chart-tooltip__dot" style={{ background: color }} />}
      <span className="chart-tooltip__label">{label}</span>
      <span className="chart-tooltip__value">{value}</span>
      {hint && <span className="chart-tooltip__hint">{hint}</span>}
    </div>
  );
}

/**
 * Positioning context every chart renders into, so the readout behaves the same
 * whether it is drawn over an SVG line, a pie slice or an HTML bar.
 */
export function ChartFrame({ containerRef, tooltip, className, children }) {
  return (
    <div ref={containerRef} className={`chart-frame ${className || ''}`.trim()}>
      {children}
      <ChartTooltip tooltip={tooltip} />
    </div>
  );
}
