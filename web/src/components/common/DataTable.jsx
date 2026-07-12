import { EmptyState } from './EmptyState';

export function DataTable({ columns, rows, onRowClick, emptyMessage, expandedRowId, renderExpanded }) {
  if (!rows || rows.length === 0) {
    return <EmptyState message={emptyMessage} />;
  }
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
            <>
              <tr
                key={row.id ?? i}
                onClick={() => onRowClick?.(row)}
                style={onRowClick ? { cursor: 'pointer' } : undefined}
              >
                {columns.map((col) => (
                  <td key={col.key}>{col.render ? col.render(row) : row[col.key]}</td>
                ))}
              </tr>
              {renderExpanded && expandedRowId === row.id && (
                <tr key={`${row.id ?? i}-expanded`}>
                  <td colSpan={columns.length} style={{ padding: 0, background: '#f9fafb' }}>
                    {renderExpanded(row)}
                  </td>
                </tr>
              )}
            </>
          ))}
        </tbody>
      </table>
    </div>
  );
}
