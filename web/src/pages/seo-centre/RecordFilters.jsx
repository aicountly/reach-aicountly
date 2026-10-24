import { useSearchParams } from 'react-router-dom';
import { Pagination } from '../../components/common/Pagination';
import { useUrlPage } from './recordLinks';

/**
 * Active URL filters of a record list as removable chips ("Status: failed ✕"),
 * so a drilled list always says why it is short. Removing a chip also drops
 * the page, since the total changes.
 *
 * @param {{ labels: Record<string, string>, format?: (key: string, value: string) => string }} props
 */
export function FilterChips({ labels, format }) {
  const [params, setParams] = useSearchParams();
  const active = Object.keys(labels).filter((k) => params.get(k));
  if (active.length === 0) return null;

  const clear = (key) => setParams((prev) => {
    const next = new URLSearchParams(prev);
    next.delete(key);
    next.delete('page');
    return next;
  });

  return (
    <div className="flex items-center gap-2 flex-wrap" style={{ margin: '0 0 0.75rem' }} aria-label="Active filters">
      {active.map((key) => {
        const value = params.get(key);
        const shown = format ? format(key, value) : value;
        return (
          <button key={key} type="button" className="badge badge-secondary" onClick={() => clear(key)}
                  aria-label={`Clear ${labels[key]} filter`}>
            {labels[key]}: {shown} ✕
          </button>
        );
      })}
    </div>
  );
}

export function UrlPagination({ total, perPage }) {
  const [page, setPage] = useUrlPage();
  return <Pagination page={page} limit={perPage} total={total} onPage={setPage} />;
}
