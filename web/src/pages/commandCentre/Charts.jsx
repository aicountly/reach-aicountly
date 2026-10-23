import { DrillLink } from '../../components/drill';

/**
 * Dependency-free SVG charts (no chart library is installed). Every chart has
 * a text alternative via role="img" + aria-label.
 */

export function Sparkline({ values = [], width = 160, height = 36, label, stroke = 'var(--color-primary)' }) {
  if (!values.length) return null;
  const max = Math.max(...values, 1);
  const step = values.length > 1 ? width / (values.length - 1) : width;
  const pts = values.map((v, i) => `${(i * step).toFixed(1)},${(height - 2 - (v / max) * (height - 4)).toFixed(1)}`);
  return (
    <svg className="cc-spark" width={width} height={height} viewBox={`0 0 ${width} ${height}`} role="img"
         aria-label={label || `Trend of ${values.length} points, peak ${max}`}>
      <polyline points={`0,${height} ${pts.join(' ')} ${width},${height}`} fill="var(--color-primary-light)" stroke="none" />
      <polyline points={pts.join(' ')} fill="none" stroke={stroke} strokeWidth="1.6" strokeLinejoin="round" />
    </svg>
  );
}

/**
 * History + forecast with its band. `history` and `forecast` are
 * [{date, value}] / [{date, value, low, high}].
 */
export function ForecastChart({ history = [], forecast = [], height = 120, label }) {
  const width = 360;
  const all = [...history.map((h) => h.value), ...forecast.map((f) => f.high ?? f.value)];
  if (!all.length) return null;
  const max = Math.max(...all, 1);
  const n = history.length + forecast.length;
  const x = (i) => (n > 1 ? (i / (n - 1)) * width : 0);
  const y = (v) => height - 4 - (v / max) * (height - 10);
  const hist = history.map((h, i) => `${x(i).toFixed(1)},${y(h.value).toFixed(1)}`).join(' ');
  const off = history.length;
  const fc = forecast.map((f, i) => `${x(off + i).toFixed(1)},${y(f.value).toFixed(1)}`).join(' ');
  const band = [
    ...forecast.map((f, i) => `${x(off + i).toFixed(1)},${y(f.high).toFixed(1)}`),
    ...forecast.slice().reverse().map((f, i) => `${x(off + forecast.length - 1 - i).toFixed(1)},${y(f.low).toFixed(1)}`),
  ].join(' ');
  return (
    <svg className="cc-forecast" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label={label}>
      {forecast.length > 0 && <polygon points={band} fill="var(--color-info-light)" stroke="none" />}
      {history.length > 0 && <polyline points={hist} fill="none" stroke="var(--color-primary)" strokeWidth="1.6" />}
      {forecast.length > 0 && <polyline points={fc} fill="none" stroke="var(--color-info)" strokeWidth="1.6" strokeDasharray="4 3" />}
      {forecast.length > 0 && history.length > 0 && (
        <line x1={x(off - 1)} x2={x(off - 1)} y1="0" y2={height} stroke="var(--color-border)" strokeDasharray="2 2" />
      )}
    </svg>
  );
}

/**
 * Horizontal bars for small categorical breakdowns. A row's label drills when
 * the row has `to` (a link) or `onSelect(row, i)` is given; a row without
 * `to` (or with `drill: false`) stays plain, so callers decide what drills.
 */
export function Bars({ rows = [], label, onSelect }) {
  const max = Math.max(...rows.map((r) => r.value), 1);
  return (
    <ul className="cc-bars" aria-label={label}>
      {rows.map((r, i) => (
        <li key={r.key ?? r.label}>
          <span className="cc-bars__label" title={r.label}>
            <DrillLink to={r.to} onClick={onSelect && r.drill !== false ? () => onSelect(r, i) : undefined}>{r.label}</DrillLink>
          </span>
          <span className="cc-bars__track" aria-hidden="true">
            <span className="cc-bars__fill" style={{ width: `${Math.max(2, (r.value / max) * 100)}%` }} />
          </span>
          <span className="cc-bars__value">{r.display ?? r.value}</span>
        </li>
      ))}
    </ul>
  );
}
