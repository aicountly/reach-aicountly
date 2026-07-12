export function VersionDiff({ versionA, versionB, fieldsChanged = [] }) {
  if (!versionA || !versionB) return null;

  return (
    <div style={{ fontSize: 13 }}>
      <div style={{ display: 'flex', gap: 16, marginBottom: 12 }}>
        <div style={{ flex: 1, padding: 12, background: '#fef2f2', borderRadius: 6, borderLeft: '4px solid #ef4444' }}>
          <div style={{ fontWeight: 700, marginBottom: 4 }}>v{versionA.version_number} (older)</div>
          <div style={{ color: '#6b7280', fontSize: 11 }}>
            {versionA.created_at ? new Date(versionA.created_at).toLocaleString() : '—'}
          </div>
        </div>
        <div style={{ flex: 1, padding: 12, background: '#f0fdf4', borderRadius: 6, borderLeft: '4px solid #10b981' }}>
          <div style={{ fontWeight: 700, marginBottom: 4 }}>v{versionB.version_number} (newer)</div>
          <div style={{ color: '#6b7280', fontSize: 11 }}>
            {versionB.created_at ? new Date(versionB.created_at).toLocaleString() : '—'}
          </div>
        </div>
      </div>

      {fieldsChanged.length === 0 && (
        <div style={{ color: '#10b981' }}>No changes detected between these versions.</div>
      )}

      {fieldsChanged.map((field) => (
        <div key={field} style={{ marginBottom: 16 }}>
          <div style={{ fontWeight: 600, marginBottom: 6, textTransform: 'capitalize' }}>
            {field.replace(/_/g, ' ')}
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            <div style={{ padding: 8, background: '#fef2f2', borderRadius: 4, fontSize: 12, whiteSpace: 'pre-wrap', maxHeight: 200, overflow: 'auto' }}>
              {versionA[field] || <span style={{ color: '#9ca3af' }}>empty</span>}
            </div>
            <div style={{ padding: 8, background: '#f0fdf4', borderRadius: 4, fontSize: 12, whiteSpace: 'pre-wrap', maxHeight: 200, overflow: 'auto' }}>
              {versionB[field] || <span style={{ color: '#9ca3af' }}>empty</span>}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
