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
  const [totalAcrossStatuses, setTotalAcrossStatuses] = useState(null);
  const nav = useNavigate();

  const load = useCallback(() => {
    setLoading(true);
    const params = { content_type: 'blog' };
    if (workflowStatus) params.workflow_status = workflowStatus;
    contentService.listItems(params)
      .then(async (d) => {
        const rows = d.items ?? d;
        setItems(rows);
        // An empty status filter used to render as "No blog content items
        // match this filter", which reads as an empty system. Find out whether
        // the library is actually empty so the empty state can say which.
        if (workflowStatus && (rows?.length ?? 0) === 0) {
          try {
            const all = await contentService.listItems({ content_type: 'blog' });
            setTotalAcrossStatuses((all.items ?? all)?.length ?? 0);
          } catch {
            setTotalAcrossStatuses(0);
          }
        } else {
          setTotalAcrossStatuses(null);
        }
      })
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
    { key: 'created', label: 'Created', render: (r) => new Date(r.created_at).toLocaleDateString() },
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

      {!loading && items.length === 0 && workflowStatus && totalAcrossStatuses > 0 && (
        <Alert variant="info">
          No blog content is in <code>{workflowStatus.replace(/_/g, ' ')}</code> right now, but{' '}
          {totalAcrossStatuses} blog item{totalAcrossStatuses === 1 ? '' : 's'} exist in other statuses.{' '}
          <button
            type="button"
            className="btn btn--sm btn--secondary"
            onClick={() => setWorkflowStatus('')}
          >
            Show all statuses
          </button>
        </Alert>
      )}

      {loading ? <Loader /> : (
        <DataTable
          columns={columns}
          rows={items}
          onRowClick={(r) => nav(ROUTES.CONTENT_DETAIL.replace(':id', r.id))}
          emptyMessage={
            workflowStatus
              ? `No blog content items are in "${workflowStatus.replace(/_/g, ' ')}".`
              : 'No blog content items yet.'
          }
        />
      )}
    </div>
  );
}
