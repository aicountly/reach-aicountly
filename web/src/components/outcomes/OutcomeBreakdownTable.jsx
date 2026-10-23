import { formatInr } from '../../utils/formatInr';
import { DrillLink, drillHref } from '../drill';

const positive = (v) => Number(v) > 0;

/**
 * One breakdown of an outcome summary (by source / campaign / content / seller).
 * `labelOf(row)` renders the first column.
 *
 * `drillOf(row)` → the Lead outcomes URL for that row's events (or null): the
 * label and Events link there, Conversions / Revenue / Converted value link to
 * the same list narrowed to those events. `labelTo(row)` sends the label
 * somewhere else instead (e.g. the content's analytics page).
 */
export function OutcomeBreakdownTable({ title, rows, labelOf, drillOf, labelTo, emptyMessage = 'Nothing to show for this period.' }) {
  const href = (r, extra) => {
    const base = drillOf?.(r);
    return base ? drillHref(base, extra) : null;
  };
  const cell = (r, value, shown, extra) => (
    <DrillLink to={positive(value) ? href(r, extra) : null}>{shown}</DrillLink>
  );

  return (
    <div>
      <h3 className="text-sm" style={{ fontWeight: 600, margin: '0 0 0.5rem' }}>{title}</h3>
      {(!rows || rows.length === 0) ? (
        <p className="text-xs text-muted">{emptyMessage}</p>
      ) : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr><th>{title.replace(/^By /, '')}</th><th>Events</th><th>Conversions</th><th>Revenue</th><th>Converted value</th></tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={i}>
                  <td><DrillLink to={labelTo?.(r) || href(r)}>{labelOf(r)}</DrillLink></td>
                  <td>{cell(r, r.events, r.events)}</td>
                  <td>{cell(r, r.conversions, r.conversions, { stage: 'converted' })}</td>
                  <td>{cell(r, r.revenue_inr, formatInr(r.revenue_inr), { paid: '1' })}</td>
                  <td>{cell(r, r.converted_value_inr, formatInr(r.converted_value_inr), { stage: 'converted' })}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
