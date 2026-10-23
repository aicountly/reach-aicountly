import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { Pagination } from '../../components/common/Pagination';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { ContentTypeBadge } from '../../components/content/ContentTypeBadge';
import { ContentRiskBadge } from '../../components/content/ContentRiskBadge';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';
import { formatDate } from '../../utils/formatDate';

const CONTENT_TYPES = [
  '', 'blog', 'knowledge_base', 'community_question', 'community_answer',
  'video_topic', 'video_script', 'social_post', 'email', 'whatsapp', 'sms',
  'landing_page', 'product_announcement', 'release_announcement',
  'webinar', 'case_study', 'content_refresh',
];

const WORKFLOW_STATUSES = [
  '', 'idea', 'brief', 'draft', 'validation_pending', 'review_pending',
  'changes_requested', 'approved', 'scheduled', 'ready_for_publication',
  'published', 'refresh_due', 'archived', 'rejected',
];

const FILTER_KEYS = ['workflow_status', 'content_type', 'search', 'risk_level', 'approval_status', 'product_id', 'market_id'];

// Params drill links can set that have no control on this page; shown as
// removable chips so the viewer can see why the list is short.
const CHIP_FILTERS = {
  risk_level: 'Risk',
  approval_status: 'Approval',
  product_id: 'Product #',
  market_id: 'Market #',
};

export function ContentListPage() {
  const [items, setItems]           = useState([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);
  // Filters live in the URL so drill links from tiles elsewhere
  // (e.g. ?workflow_status=scheduled,ready_for_publication) land on the rows
  // they counted, and a later link to this page re-filters it.
  const [searchParams, setSearchParams] = useSearchParams();
  const qs = searchParams.toString();
  const filters = useMemo(() => {
    const out = {};
    FILTER_KEYS.forEach((k) => {
      const v = new URLSearchParams(qs).get(k);
      if (v) out[k] = v;
    });
    return out;
  }, [qs]);
  const setFilter = useCallback((key, value) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value) next.set(key, value); else next.delete(key);
      return next;
    }, { replace: true });
  }, [setSearchParams]);
  const clearFilters = () => setSearchParams((prev) => {
    const next = new URLSearchParams(prev);
    FILTER_KEYS.forEach((k) => next.delete(k));
    return next;
  });
  // Paging belongs to one filter set: a new filter starts again at page 1.
  const [paging, setPaging] = useState({ qs, page: 1 });
  const page = paging.qs === qs ? paging.page : 1;
  const [pager, setPager] = useState(null);
  const { has } = usePermission();
  const nav = useNavigate();

  const canCreate = has('content.create');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    contentService.listItems(page > 1 ? { ...filters, page } : filters)
      .then((d) => { setItems(d.items ?? d); setPager(d.pager ?? null); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [filters, page]);

  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'type', label: 'Type', render: (r) => <ContentTypeBadge type={r.content_type} /> },
    { key: 'title', label: 'Title', render: (r) => (
      <div>
        <div style={{ fontWeight: 600, fontSize: 13 }}>{r.title}</div>
        <div style={{ fontSize: 11, color: '#6b7280' }}>{r.slug}</div>
      </div>
    )},
    { key: 'status', label: 'Status', render: (r) => <ContentStatusBadge status={r.workflow_status} /> },
    { key: 'risk', label: 'Risk', render: (r) => <ContentRiskBadge level={r.risk_level} /> },
    { key: 'due', label: 'Due', render: (r) => r.review_due_at ? formatDate(r.review_due_at) : '—' },
    { key: 'created', label: 'Created', render: (r) => formatDate(r.created_at) },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Content Studio</h1>
          <p className="text-sm text-muted">All marketing content across types and workflow stages.</p>
        </div>
        {canCreate && (
          <button className="btn btn-primary" onClick={() => nav(ROUTES.CONTENT_NEW)}>
            <Plus size={14} /> New Content
          </button>
        )}
      </div>

      {/* Filters */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <select
          value={filters.content_type || ''}
          onChange={(e) => setFilter('content_type', e.target.value)}
          aria-label="Content type"
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        >
          <option value="">All types</option>
          {CONTENT_TYPES.filter(Boolean).map((t) => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}
        </select>
        <select
          value={filters.workflow_status || ''}
          onChange={(e) => setFilter('workflow_status', e.target.value)}
          aria-label="Workflow status"
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        >
          <option value="">All statuses</option>
          {WORKFLOW_STATUSES.filter(Boolean).map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
          {filters.workflow_status && !WORKFLOW_STATUSES.includes(filters.workflow_status) && (
            <option value={filters.workflow_status}>{filters.workflow_status.replace(/_/g, ' ').replace(/,/g, ' + ')}</option>
          )}
        </select>
        <input
          type="search"
          value={filters.search || ''}
          onChange={(e) => setFilter('search', e.target.value)}
          placeholder="Search title…"
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        />
        <button className="btn btn-ghost btn-sm" onClick={clearFilters}>Clear</button>
      </div>

      {Object.keys(CHIP_FILTERS).some((k) => filters[k]) && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {Object.entries(CHIP_FILTERS).filter(([k]) => filters[k]).map(([k, label]) => (
            <button key={k} type="button" className="badge badge-secondary" onClick={() => setFilter(k, '')}
                    aria-label={`Clear ${label.replace(' #', '')} filter`}>
              {label.endsWith('#') ? `${label}${filters[k]}` : `${label}: ${filters[k].replace(/_/g, ' ')}`} ✕
            </button>
          ))}
        </div>
      )}

      {pager && !loading && Object.keys(filters).length > 0 && (
        <p className="text-xs text-muted" style={{ marginBottom: 8 }}>{pager.total} matching item{pager.total === 1 ? '' : 's'}</p>
      )}
      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable
            columns={columns}
            rows={items}
            onRowClick={(r) => nav(ROUTES.CONTENT_DETAIL.replace(':id', r.id))}
            emptyMessage="No content items found."
          />
        </Card>
      )}
      {pager && (
        <Pagination
          page={page}
          limit={pager.perPage}
          total={pager.total}
          onPage={(p) => setPaging({ qs, page: p })}
        />
      )}
    </div>
  );
}
