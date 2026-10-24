import { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { seoService } from '../../services/seoService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { DataTable } from '../../components/common/DataTable';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { formatDate, formatDateTime } from '../../utils/formatDate';
import { drillHref } from '../../components/drill';
import { FilterChips, UrlPagination } from './RecordFilters';
import { useParamsPatch, useUrlPage } from './recordLinks';

const PER_PAGE = 50;
const KIND_LABEL = { internal_links: 'Internal links', published_urls: 'Published URLs' };
const humanize = (s) => String(s ?? '').replace(/_/g, ' ');

/**
 * One backlink snapshot: its totals, whatever the provider stored in
 * details_json (lists become tables), and the records its two internal counts
 * summarise. Those counts are taken from live tables, so the rows are today's
 * and the page shows the drift next to the snapshot figure.
 */
export function BacklinkSnapshotPage() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const [page] = useUrlPage();
  const patch = useParamsPatch();
  const kind = params.get('kind') === 'published_urls' ? 'published_urls' : 'internal_links';
  const status = params.get('status') || '';
  const targetType = params.get('target_type') || '';
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    setError('');
    seoService.getBacklinkSnapshot(id, { kind, status, target_type: targetType, page, per_page: PER_PAGE })
      .then(setData)
      .catch((err) => setError(err?.message || 'Unable to load this snapshot.'))
      .finally(() => setLoading(false));
  }, [id, kind, status, targetType, page]);

  const setKind = (next) => patch({ kind: next, status: '', target_type: '' }, { push: true });

  if (loading && !data) return <Loader label="Loading snapshot…" />;
  if (error) return <Alert variant="error">{error}</Alert>;
  if (!data) return null;

  const snap = data.snapshot || {};
  const records = data.records || { rows: [], total: 0 };
  const current = data.current_count || {};

  const linkColumns = [
    { key: 'source_title', label: 'From post', render: (r) => r.source_title || `#${r.source_content_item_id}`,
      drill: (r) => (r.source_content_item_id ? `/content/${r.source_content_item_id}` : null) },
    { key: 'target', label: 'Links to', render: (r) => r.target_title || r.target_public_url || '—',
      drill: (r) => (r.target_content_item_id ? `/content/${r.target_content_item_id}` : null) },
    { key: 'anchor_text', label: 'Anchor', render: (r) => r.anchor_text || '—' },
    { key: 'target_type', label: 'Type', render: (r) => r.target_type,
      drill: (r) => (r.target_type !== targetType ? { onClick: () => patch({ target_type: r.target_type }, { push: true }), title: 'Only this link type' } : null) },
    { key: 'status', label: 'Status', render: (r) => r.status,
      drill: (r) => (r.status !== status ? { onClick: () => patch({ status: r.status }, { push: true }), title: 'Only links in this status' } : null) },
    { key: 'external', label: '', render: (r) => (r.target_public_url
      ? <a href={r.target_public_url} target="_blank" rel="noreferrer" className="text-sm">Open ↗</a> : null) },
  ];
  const urlColumns = [
    { key: 'title', label: 'Post', render: (r) => r.title || `#${r.content_item_id}`, drill: (r) => `/content/${r.content_item_id}` },
    { key: 'workflow_status', label: 'Workflow', render: (r) => humanize(r.workflow_status) },
    { key: 'indexing_status', label: 'Indexing', render: (r) => humanize(r.indexing_status),
      drill: (r) => drillHref('/seo-centre/indexing', { status: r.indexing_status }) },
    { key: 'public_url', label: 'Public URL', render: (r) => (r.public_url
      ? <a href={r.public_url} target="_blank" rel="noreferrer">{r.public_url}</a> : <span className="text-muted">—</span>) },
  ];

  return (
    <div>
      <Breadcrumbs items={[{ label: 'Backlinks', to: '/seo-centre/backlinks' }, { label: `Snapshot ${formatDate(snap.snapshot_date)}` }]} />

      <Card title={`Snapshot ${formatDate(snap.snapshot_date)} · ${snap.provider}`}>
        <div className="stat-grid">
          {[
            ['internal_links', 'Internal links', snap.internal_links],
            ['published_urls', 'Published URLs', snap.published_urls],
          ].map(([k, label, value]) => (
            <button key={k} type="button" className="stat-tile" onClick={() => setKind(k)}
                    aria-pressed={kind === k} aria-label={`${label}: ${value}`}>
              <div className="stat-tile__value">{value}</div>
              <div className="stat-tile__label">{label}</div>
              {current[k] != null && Number(current[k]) !== Number(value) && (
                <div className="text-xs text-muted">now {current[k]}</div>
              )}
            </button>
          ))}
          <div className="stat-tile">
            <div className="stat-tile__value">{snap.total_backlinks ?? '—'}</div>
            <div className="stat-tile__label">External backlinks</div>
          </div>
          <div className="stat-tile">
            <div className="stat-tile__value">{snap.referring_domains ?? '—'}</div>
            <div className="stat-tile__label">Referring domains</div>
          </div>
        </div>
        {snap.total_backlinks == null && (
          <p className="text-sm text-muted" style={{ margin: '0.75rem 0 0' }}>
            No external backlink index recorded for this snapshot, so there are no backlink or referring-domain rows to list.
          </p>
        )}
        <p className="text-xs text-muted" style={{ margin: '0.5rem 0 0' }}>Captured {formatDateTime(snap.created_at)}</p>
      </Card>

      {(Object.keys(data.detail_facts || {}).length > 0 || (data.detail_lists || []).length > 0) && (
        <Card title="Provider details">
          {Object.entries(data.detail_facts || {}).map(([k, v]) => (
            <p key={k} className="text-sm" style={{ margin: '0 0 0.25rem' }}><strong>{humanize(k)}:</strong> {String(v)}</p>
          ))}
          {(data.detail_lists || []).map((list) => {
            const keys = Array.from(new Set(list.rows.flatMap((r) => Object.keys(r)))).slice(0, 8);
            return (
              <div key={list.key} style={{ marginTop: '0.75rem' }}>
                <h3 className="text-sm" style={{ margin: '0 0 0.4rem' }}>{humanize(list.key)} ({list.rows.length})</h3>
                <DataTable
                  rowKey={(r) => JSON.stringify(r)}
                  columns={keys.map((k) => ({
                    key: k, label: humanize(k),
                    render: (r) => (/^https?:\/\//.test(String(r[k] ?? ''))
                      ? <a href={r[k]} target="_blank" rel="noreferrer">{r[k]}</a>
                      : (typeof r[k] === 'object' && r[k] !== null ? JSON.stringify(r[k]) : String(r[k] ?? '—'))),
                  }))}
                  rows={list.rows}
                />
              </div>
            );
          })}
        </Card>
      )}

      <Card title={`${KIND_LABEL[kind]} (${records.total})`}
            actions={<Link to="/seo-centre/backlinks" className="text-sm">All snapshots ›</Link>}>
        <p className="text-xs text-muted" style={{ marginTop: 0 }}>
          The snapshot counted {snap[kind]} on {formatDate(snap.snapshot_date)}; these are the records as they stand today.
        </p>
        <FilterChips labels={{ status: 'Status', target_type: 'Type' }} />
        <DataTable
          columns={kind === 'published_urls' ? urlColumns : linkColumns}
          rows={records.rows}
          rowKey={(r) => r.id ?? r.profile_id}
          emptyMessage={kind === 'published_urls' ? 'No published blog posts.' : 'No internal links match.'}
        />
        <UrlPagination total={records.total} perPage={PER_PAGE} />
      </Card>
    </div>
  );
}

export default BacklinkSnapshotPage;
