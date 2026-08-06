import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { ContentTypeBadge } from '../../components/content/ContentTypeBadge';
import { ContentRiskBadge } from '../../components/content/ContentRiskBadge';
import { ROUTES } from '../../constants/routes';
import { formatDate } from '../../utils/formatDate';

const WORKFLOW_STATUSES = [
  '', 'idea', 'brief', 'brief_ready', 'outline_ready', 'draft', 'draft_generating',
  'fact_verifying', 'fact_verified', 'seo_review', 'internal_review',
  'validation_pending', 'review_pending', 'changes_requested', 'approved',
  'scheduled', 'publish_queued', 'publishing', 'published', 'live',
  'ready_for_publication', 'refresh_due', 'archived', 'rejected', 'failed', 'blocked',
];

/**
 * Thin blog-scoped content list — reuses Content Studio data patterns without duplicating CRUD.
 */
export function BlogContentListWrapper({ title, subtitle, defaultWorkflowStatus }) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [workflowStatus, setWorkflowStatus] = useState(defaultWorkflowStatus || '');
  const nav = useNavigate();

  const load = useCallback(() => {
    setLoading(true);
    const params = { content_type: 'blog' };
    if (workflowStatus) params.workflow_status = workflowStatus;
    contentService.listItems(params)
      .then((d) => setItems(d.items ?? d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [workflowStatus]);

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
    { key: 'created', label: 'Created', render: (r) => formatDate(r.created_at) },
  ];

  return (
    <div>
      <Alert variant="info">
        Scoped to <code>content_type=blog</code>. Full editing lives in Content Studio.
      </Alert>

      <div className="page-header" style={{ marginTop: 16 }}>
        <div>
          <h1>{title}</h1>
          {subtitle && <p className="text-sm text-muted">{subtitle}</p>}
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <select
          value={workflowStatus}
          onChange={(e) => setWorkflowStatus(e.target.value)}
          style={{ borderRadius: 4, border: '1px solid #e5e7eb', padding: '5px 10px', fontSize: 12 }}
        >
          <option value="">All statuses</option>
          {WORKFLOW_STATUSES.filter(Boolean).map((s) => (
            <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
          ))}
        </select>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <DataTable
          columns={columns}
          rows={items}
          onRowClick={(r) => nav(ROUTES.CONTENT_DETAIL.replace(':id', r.id))}
          emptyMessage="No blog content items match this filter."
        />
      )}
    </div>
  );
}
