import { useSearchParams } from 'react-router-dom';

/** `?page=` in the URL so Back returns to the same page of a drilled list. */
export function useUrlPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const setPage = (p) => setParams((prev) => {
    const next = new URLSearchParams(prev);
    if (p <= 1) next.delete('page');
    else next.set('page', String(p));
    return next;
  });
  return [page, setPage];
}

/** Where a content record opens in Reach: the post for a blog, else its analytics. */
export function contentHref({ content_item_id: itemId, content_identity_id: identityId } = {}) {
  if (itemId) return `/content/${itemId}`;
  if (identityId) return `/intelligence/content/${identityId}`;
  return null;
}

/**
 * Change several URL filters in one history step. react-router's functional
 * setSearchParams reads the params of the current render, so two setters in
 * one handler would overwrite each other. Any change resets `page`.
 */
export function useParamsPatch() {
  const [, setParams] = useSearchParams();
  return (patch, { push = false } = {}) => setParams((prev) => {
    const next = new URLSearchParams(prev);
    Object.entries(patch).forEach(([k, v]) => {
      if (v === undefined || v === null || v === '') next.delete(k);
      else next.set(k, String(v));
    });
    if (!('page' in patch)) next.delete('page');
    return next;
  }, { replace: !push });
}
