export function formatScore(value) {
  if (value === null || value === undefined || value === '') return '—';
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric.toFixed(1) : '—';
}
