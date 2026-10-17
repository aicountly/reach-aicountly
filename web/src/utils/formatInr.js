/**
 * Display an INR amount the API sends as a NUMERIC(14,2) string ("1234.50").
 * Display only — never use the result for arithmetic.
 */
export function formatInr(value, fallback = '—') {
  if (value === null || value === undefined || value === '') return fallback;
  const text = String(value).trim();
  if (!/^-?\d+(\.\d+)?$/.test(text)) return fallback;
  const [intPart, frac = ''] = text.replace('-', '').split('.');
  const sign = text.startsWith('-') ? '-' : '';
  // Indian digit grouping: last 3 digits, then groups of 2.
  const last3 = intPart.slice(-3);
  const rest = intPart.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
  const grouped = rest ? `${rest},${last3}` : last3;
  return `${sign}₹${grouped}.${(frac + '00').slice(0, 2)}`;
}
