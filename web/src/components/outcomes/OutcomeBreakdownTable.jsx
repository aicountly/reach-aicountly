import { formatInr } from '../../utils/formatInr';

/**
 * One breakdown of an outcome summary (by source / campaign / content / seller).
 * `labelOf(row)` renders the first column.
 */
export function OutcomeBreakdownTable({ title, rows, labelOf, emptyMessage = 'Nothing to show for this period.' }) {
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
                  <td>{labelOf(r)}</td>
                  <td>{r.events}</td>
                  <td>{r.conversions}</td>
                  <td>{formatInr(r.revenue_inr)}</td>
                  <td>{formatInr(r.converted_value_inr)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
