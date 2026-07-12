const RISK_STYLES = {
  low:      { bg: '#d1fae5', color: '#065f46' },
  medium:   { bg: '#fef3c7', color: '#92400e' },
  high:     { bg: '#fed7aa', color: '#9a3412' },
  critical: { bg: '#fee2e2', color: '#991b1b' },
};

export function ContentRiskBadge({ level }) {
  const style = RISK_STYLES[level] || RISK_STYLES.low;
  return (
    <span style={{
      background: style.bg,
      color: style.color,
      borderRadius: 4,
      padding: '2px 8px',
      fontSize: 11,
      fontWeight: 700,
      textTransform: 'uppercase',
    }}>
      {level || 'low'}
    </span>
  );
}
