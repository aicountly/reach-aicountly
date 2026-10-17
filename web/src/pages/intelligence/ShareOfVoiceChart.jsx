import { useState } from 'react';
import { ChartFrame } from '../../components/charts/ChartTooltip';
import { useChartTooltip } from '../../components/charts/useChartTooltip';

// Categorical slots in fixed order (validated: adjacent CVD ΔE ≥ 9.1). The
// brand always takes slot 1; competitors keep their slot by name order in
// `entities`, so a filter never repaints a survivor. Slots 3–4 sit below 3:1
// on white, so every line is direct-labelled and the table below is the
// accessible view.
const SERIES_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];

const WIDTH = 640;
const PAD_L = 44;
const PAD_R = 110;
const PAD_T = 16;
const PAD_B = 28;

const pct = (v) => `${Math.round((Number(v) || 0) * 1000) / 10}%`;

/**
 * Share of voice per day for one assistant: AICOUNTLY vs up to three
 * competitors. `points` = [{ date, sov: { name: 0..1 } }].
 */
export default function ShareOfVoiceChart({ points, entities, height = 220 }) {
  const { containerRef, tooltip, showTooltip, hideTooltip } = useChartTooltip();
  const [active, setActive] = useState(null);
  const rows = Array.isArray(points) ? points : [];
  const names = (entities || []).slice(0, SERIES_COLORS.length);

  if (rows.length === 0 || names.length === 0) {
    return <p className="text-sm text-muted" style={{ margin: 0 }}>No answers recorded for this assistant yet.</p>;
  }

  const chartW = WIDTH - PAD_L - PAD_R;
  const chartH = height - PAD_T - PAD_B;
  const max = Math.max(0.1, ...rows.flatMap((r) => names.map((n) => Number(r.sov?.[n]) || 0)));
  const top = Math.min(1, Math.ceil(max * 10) / 10);
  const step = rows.length > 1 ? chartW / (rows.length - 1) : 0;
  const xAt = (i) => PAD_L + (rows.length > 1 ? i * step : chartW / 2);
  const yAt = (v) => PAD_T + chartH - ((Number(v) || 0) / top) * chartH;
  const ticks = [0, top / 2, top];

  const activate = (event, i) => {
    setActive(i);
    const r = rows[i];
    showTooltip(event, {
      label: r.date,
      value: `${names[0]} ${pct(r.sov?.[names[0]])}`,
      hint: names.slice(1).map((n) => `${n} ${pct(r.sov?.[n])}`).join(' · '),
      color: SERIES_COLORS[0],
    });
  };
  const clear = () => {
    setActive(null);
    hideTooltip();
  };

  const last = rows.length - 1;
  // End labels, nudged apart so equal values do not overprint.
  const labelY = {};
  names
    .map((n) => ({ n, y: yAt(rows[last].sov?.[n]) + 4 }))
    .sort((a, b) => a.y - b.y)
    .forEach((l, i, arr) => {
      const prev = i > 0 ? labelY[arr[i - 1].n] : -Infinity;
      labelY[l.n] = Math.max(l.y, prev + 13);
    });

  return (
    <div>
      <div className="flex flex-wrap gap-3 mb-2" aria-label="Legend">
        {names.map((n, i) => (
          <span key={n} className="chart-legend-item text-xs" style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <span style={{ width: 14, height: 2, background: SERIES_COLORS[i], display: 'inline-block', borderRadius: 2 }} />
            {n}
          </span>
        ))}
      </div>
      <ChartFrame containerRef={containerRef} tooltip={tooltip} className="chart-frame--line">
        <svg viewBox={`0 0 ${WIDTH} ${height}`} style={{ width: '100%', height: 'auto', display: 'block' }} role="img" aria-label="Share of voice by day" onMouseLeave={clear}>
          {ticks.map((t) => (
            <g key={t}>
              <line x1={PAD_L} x2={PAD_L + chartW} y1={yAt(t)} y2={yAt(t)} stroke="var(--color-border)" strokeWidth="1" />
              <text x={PAD_L - 6} y={yAt(t) + 4} textAnchor="end" fontSize="11" fill="var(--color-text-muted)">{pct(t)}</text>
            </g>
          ))}
          <text x={PAD_L} y={height - 8} fontSize="11" fill="var(--color-text-muted)">{rows[0].date}</text>
          {rows.length > 1 && <text x={PAD_L + chartW} y={height - 8} textAnchor="end" fontSize="11" fill="var(--color-text-muted)">{rows[last].date}</text>}

          {active !== null && <line className="chart-line__guide" x1={xAt(active)} x2={xAt(active)} y1={PAD_T} y2={PAD_T + chartH} stroke="var(--color-text-muted)" strokeWidth="1" />}

          {names.map((n, si) => {
            const d = rows.map((r, i) => `${i === 0 ? 'M' : 'L'} ${xAt(i)} ${yAt(r.sov?.[n])}`).join(' ');
            return (
              <g key={n}>
                {rows.length > 1 && <path d={d} fill="none" stroke={SERIES_COLORS[si]} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />}
                {rows.map((r, i) => (
                  <circle key={r.date} className="chart-line__dot" cx={xAt(i)} cy={yAt(r.sov?.[n])} r={active === i || rows.length === 1 ? 4 : 0} fill={SERIES_COLORS[si]} stroke="var(--color-surface)" strokeWidth="2" />
                ))}
                <text x={xAt(last) + 8} y={labelY[n]} fontSize="11" fill="var(--color-text)">{`${n} ${pct(rows[last].sov?.[n])}`}</text>
              </g>
            );
          })}

          {rows.map((r, i) => {
            const half = rows.length > 1 ? step / 2 : chartW / 2;
            return (
              <rect
                key={r.date}
                className="chart-hit"
                x={xAt(i) - half}
                y={PAD_T}
                width={half * 2}
                height={chartH}
                fill="transparent"
                tabIndex={0}
                aria-label={`${r.date}: ${names.map((n) => `${n} ${pct(r.sov?.[n])}`).join(', ')}`}
                onMouseMove={(e) => activate(e, i)}
                onFocus={(e) => activate(e, i)}
                onBlur={clear}
              />
            );
          })}
        </svg>
      </ChartFrame>
    </div>
  );
}
