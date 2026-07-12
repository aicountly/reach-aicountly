import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { ContentTypeBadge } from '../../components/content/ContentTypeBadge';
import { ContentRiskBadge } from '../../components/content/ContentRiskBadge';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

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

export function ContentListPage() {
  const [items, setItems]           = useState([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);
  const [filters, setFilters]       = useState({});
  const { has } = usePermission();
  const nav = useNavigate();

  const canCreate = has('content.create');

  const load = useCallback(() => {
    setLoading(true);
    contentService.listItems(filters)
      .then((d) => setItems(d.items ?? d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [filters]);

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
    { key: 'due', label: 'Due', render: (r) => r.review_due_at ? new Date(r.review_due_at).toLocaleDateString() : '—' },
    { key: 'created', label: 'Created', render: (r) => new Date(r.created_at).toLocaleDateString() },
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
          onChange={(e) => setFilters((f) => ({ ...f, content_type: e.target.value || undefined }))}
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        >
          <option value="">All types</option>
          {CONTENT_TYPES.filter(Boolean).map((t) => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}
        </select>
        <select
          value={filters.workflow_status || ''}
          onChange={(e) => setFilters((f) => ({ ...f, workflow_status: e.target.value || undefined }))}
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        >
          <option value="">All statuses</option>
          {WORKFLOW_STATUSES.filter(Boolean).map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
        </select>
        <input
          type="search"
          value={filters.search || ''}
          onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value || undefined }))}
          placeholder="Search title…"
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        />
        <button className="btn btn-ghost btn-sm" onClick={() => setFilters({})}>Clear</button>
      </div>

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
    </div>
  );
}
