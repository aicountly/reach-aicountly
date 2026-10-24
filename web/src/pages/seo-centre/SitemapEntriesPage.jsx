import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { seoService } from '../../services/seoService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { DataTable } from '../../components/common/DataTable';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { formatDateTime } from '../../utils/formatDate';
import { drillHref, useUrlFilter } from '../../components/drill';
import { FilterChips, UrlPagination } from './RecordFilters';
import { contentHref, useParamsPatch, useUrlPage } from './recordLinks';

const PER_PAGE = 100;
const REASONS = { noindex: 'noindex', withdrawn: 'withdrawn', not_published: 'not published', analytics_ineligible: 'analytics ineligible', other: 'other (not published / ineligible)' };

/**
 * The entries of one sitemap snapshot, filtered the way its counts were
 * taken (state=included|excluded, reason=…). Each entry opens its public URL
 * and, where the URL maps to Reach content, that content.
 */
export function SitemapEntriesPage() {
  const { snapshotId } = useParams();
  const [state] = useUrlFilter('state');
  const [reason] = useUrlFilter('reason');
  const [q] = useUrlFilter('q');
  const [page] = useUrlPage();
  const patch = useParamsPatch();
  const [res, setRes] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    seoService.listSitemapEntries(snapshotId, { state, reason, q, page, per_page: PER_PAGE })
      .then((r) => { setRes(r); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load sitemap entries.'));
  }, [snapshotId, state, reason, q, page]);

  const meta = res?.meta || {};
  const snap = meta.snapshot;
  const facets = meta.facets || {};
  const total = meta.total ?? 0;
  const narrow = (p) => patch(p, { push: true });

  const tiles = [
    { label: 'All entries', n: facets.all, patch: { state: '', reason: '' }, on: !state && !reason },
    { label: 'Included', n: facets.included, patch: { state: 'included', reason: '' }, on: state === 'included' },
    { label: 'Excluded', n: facets.excluded, patch: { state: 'excluded', reason: '' }, on: state === 'excluded' && !reason },
    ...Object.keys(REASONS).filter((r) => r !== 'other' && facets[r] > 0)
      .map((r) => ({ label: `Excluded: ${REASONS[r]}`, n: facets[r], patch: { state: '', reason: r }, on: reason === r })),
  ];

  return (
    <div>
      <Breadcrumbs items={[{ label: 'Sitemap', to: '/seo-centre/sitemap' }, { label: `Snapshot #${snapshotId} entries` }]} />
      {error && <Alert variant="error">{error}</Alert>}
      {snap && (
        <p className="text-sm text-muted" style={{ margin: '0 0 0.75rem' }}>
          Generated {formatDateTime(snap.generated_at)} · {snap.total_entries} identities counted
          {meta.unlisted > 0 && <> · {meta.unlisted} had no canonical URL, so no entry was written for them</>}
        </p>
      )}

      <div className="stat-grid mb-3">
        {tiles.filter((t) => t.n > 0).map((t) => (
          <button key={t.label} type="button" className="stat-tile" aria-pressed={t.on}
                  onClick={() => narrow(t.patch)} aria-label={`${t.label}: ${t.n}`}>
            <div className="stat-tile__value">{t.n}</div>
            <div className="stat-tile__label">{t.label}</div>
          </button>
        ))}
      </div>

      <Card>
        <div className="flex items-center gap-2 flex-wrap" style={{ marginBottom: '0.75rem' }}>
          <input key={q} type="search" placeholder="URL contains…" aria-label="URL contains" defaultValue={q}
                 onKeyDown={(e) => { if (e.key === 'Enter') patch({ q: e.currentTarget.value.trim() }); }}
                 style={{ maxWidth: 280 }} />
        </div>
        <FilterChips labels={{ state: 'State', reason: 'Excluded because', q: 'URL contains' }}
                     format={(k, v) => (k === 'reason' ? (REASONS[v] || v) : v)} />
        {!res && !error ? <Loader label="Loading entries…" /> : (
          <>
            <p className="text-sm text-muted" style={{ marginTop: 0 }}>{total} entr{total === 1 ? 'y' : 'ies'}</p>
            <DataTable
              columns={[
                { key: 'url', label: 'URL', render: (r) => (
                  <a href={r.url} target="_blank" rel="noreferrer" style={{ wordBreak: 'break-all' }}>{r.url}</a>
                ) },
                { key: 'included', label: 'State', render: (r) => (r.included
                  ? <span className="badge badge-success">included</span>
                  : <span className="badge badge-secondary">excluded{r.exclusion_reason ? `: ${REASONS[r.exclusion_reason] || r.exclusion_reason}` : ''}</span>),
                  drill: (r) => (r.included || !r.exclusion_reason || r.exclusion_reason === reason
                    ? null : { onClick: () => narrow({ state: '', reason: r.exclusion_reason }), title: 'Only entries excluded for this reason' }) },
                { key: 'content_type', label: 'Content', render: (r) => r.content_type || '—' },
                { key: 'last_modified_at', label: 'Last modified', render: (r) => (r.last_modified_at ? formatDateTime(r.last_modified_at) : '—') },
                { key: 'open', label: '', render: (r) => {
                  const href = contentHref(r);
                  return (
                    <span className="text-sm" style={{ display: 'inline-flex', gap: '0.6rem', flexWrap: 'wrap' }}>
                      {href && <Link to={href}>Open content ›</Link>}
                      <Link to={drillHref('/seo-centre/search', { page: r.url, by: 'query' })}>Search queries ›</Link>
                    </span>
                  );
                } },
              ]}
              rows={res?.data || []}
              emptyMessage={state || reason || q ? 'No entries match these filters.' : 'This snapshot has no entries.'}
            />
            <UrlPagination total={total} perPage={PER_PAGE} />
          </>
        )}
      </Card>
    </div>
  );
}

export default SitemapEntriesPage;
