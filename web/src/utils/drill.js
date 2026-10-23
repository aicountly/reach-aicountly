/**
 * Drill-down links. A drill target is a route plus the filters that narrow it
 * to the records behind a number, e.g. drillHref('/leads', { status: 'failed' })
 * → '/leads?status=failed'. Empty values are dropped so a link never filters
 * on "".
 *
 * @param {string} path
 * @param {Record<string, string|number|boolean|null|undefined>} [params]
 */
export function drillHref(path, params = {}) {
  const [base, existing = ''] = String(path).split('?');
  const search = new URLSearchParams(existing);
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '' || value === false) search.delete(key);
    else search.set(key, value === true ? '1' : String(value));
  });
  const qs = search.toString();
  return qs ? `${base}?${qs}` : base;
}
