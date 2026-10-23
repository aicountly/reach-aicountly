import { Fragment } from 'react';
import { EmptyState } from './EmptyState';
import { DrillLink } from '../drill/DrillLink';

/**
 * A column may set `drill(row)` to make its cell a drill-down into the
 * records behind that value: return a route string, `{ to }`, `{ onClick }`,
 * or null when that row has nothing to drill into (e.g. a zero count).
 * `onRowClick` makes the whole row a destination, reachable by keyboard too.
 */
export function DataTable({ columns, rows, onRowClick, emptyMessage, expandedRowId, renderExpanded, rowKey, rowLabel }) {
  if (!rows || rows.length === 0) {
    return <EmptyState message={emptyMessage} />;
  }

  const getRowKey = (row, i) => {
    if (rowKey) return rowKey(row);
    return row.id ?? row.uuid ?? row.slug ?? i;
  };

  const renderCell = (col, row) => {
    const content = col.render ? col.render(row) : row[col.key];
    const target = col.drill?.(row);
    if (!target) return content;
    const spec = typeof target === 'string' ? { to: target } : target;
    return (
      <DrillLink to={spec.to} onClick={spec.onClick} label={spec.label} title={spec.title}>
        {content}
      </DrillLink>
    );
  };

  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key} style={col.width ? { width: col.width } : undefined}>{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <Fragment key={getRowKey(row, i)}>
              <tr
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                onKeyDown={onRowClick ? (e) => {
                  if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    onRowClick(row);
                  }
                } : undefined}
                tabIndex={onRowClick ? 0 : undefined}
                aria-label={onRowClick && rowLabel ? rowLabel(row) : undefined}
                className={onRowClick ? 'is-drillable' : undefined}
              >
                {columns.map((col) => (
                  <td key={col.key}>{renderCell(col, row)}</td>
                ))}
              </tr>
              {renderExpanded && expandedRowId === row.id && (
                <tr>
                  <td colSpan={columns.length} style={{ padding: 0, background: '#f9fafb' }}>
                    {renderExpanded(row)}
                  </td>
                </tr>
              )}
            </Fragment>
          ))}
        </tbody>
      </table>
    </div>
  );
}
