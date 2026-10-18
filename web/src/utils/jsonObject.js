/** null when the text is blank or a JSON object, else the reason it is not. */
export function jsonObjectError(text) {
  const t = String(text ?? '').trim();
  if (t === '') return null;
  try {
    const v = JSON.parse(t);
    if (v === null || typeof v !== 'object' || Array.isArray(v)) return 'Must be a JSON object, e.g. {"key": "value"}.';
    return null;
  } catch (e) {
    return `Not valid JSON: ${e.message}`;
  }
}
