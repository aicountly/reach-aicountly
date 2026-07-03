export function Loader({ label }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: 24 }}>
      <div className="spinner" />
      {label && <div className="text-sm text-muted">{label}</div>}
    </div>
  );
}
