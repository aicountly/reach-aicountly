export function Pagination({ page, limit, total, onPage }) {
  const pages = Math.max(1, Math.ceil((total || 0) / (limit || 1)));
  if (pages <= 1) return null;
  return (
    <div className="flex items-center gap-2 mt-4" style={{ justifyContent: 'flex-end' }}>
      <button
        className="btn btn-secondary btn-sm"
        disabled={page <= 1}
        onClick={() => onPage(Math.max(1, page - 1))}
      >Prev</button>
      <span className="text-sm text-muted">Page {page} of {pages}</span>
      <button
        className="btn btn-secondary btn-sm"
        disabled={page >= pages}
        onClick={() => onPage(Math.min(pages, page + 1))}
      >Next</button>
    </div>
  );
}
