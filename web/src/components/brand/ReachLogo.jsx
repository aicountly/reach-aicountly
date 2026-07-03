export function ReachLogo({ height = 32 }) {
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
      <svg width={height} height={height} viewBox="0 0 40 40" aria-hidden>
        <rect width="40" height="40" rx="8" fill="#25b003" />
        <text x="20" y="27" textAnchor="middle" fontFamily="Nunito, sans-serif" fontSize="22" fontWeight="800" fill="#ffffff">R</text>
      </svg>
      <div style={{ lineHeight: 1 }}>
        <div style={{ fontSize: '0.95rem', fontWeight: 700, color: 'var(--color-text)' }}>Reach</div>
        <div style={{ fontSize: '0.65rem', color: 'var(--color-text-muted)', letterSpacing: '0.06em', textTransform: 'uppercase' }}>AICOUNTLY</div>
      </div>
    </div>
  );
}
