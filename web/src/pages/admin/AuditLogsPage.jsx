import { useCallback, useEffect, useState } from 'react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { Pagination } from '../../components/common/Pagination';
import { drillHref, useUrlFilter } from '../../components/drill';
import { ROUTES } from '../../constants/routes';
import { formatDateTime } from '../../utils/formatDate';

/**
 * Where an audited entity opens, by entity_type. Types without a detail page
 * (settings, jobs, social posts, ad campaigns…) stay plain text.
 */
const ENTITY_ROUTES = {
  content_item:      (id) => ROUTES.CONTENT_DETAIL.replace(':id', id),
  blog:              (id) => ROUTES.BLOG_DETAIL.replace(':id', id),
  campaign:          (id) => ROUTES.CAMPAIGN_DETAIL.replace(':id', id),
  landing:           (id) => ROUTES.LANDING_DETAIL.replace(':id', id),
  email_campaign:    (id) => ROUTES.EMAIL_DETAIL.replace(':id', id),
  whatsapp_campaign: (id) => ROUTES.WHATSAPP_DETAIL.replace(':id', id),
  bot_report:        (id) => ROUTES.BOT_REPORT_DETAIL.replace(':id', id),
  lead:              (id) => drillHref(ROUTES.LEADS, { lead_id: id }),
};

function entityHref(row) {
  const to = ENTITY_ROUTES[row.entity_type];
  return to && row.entity_id ? to(row.entity_id) : null;
}

export function AuditLogsPage() {
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [limit] = useState(50);
  // Filters live in the URL (shareable; a cell click narrows and Back undoes it).
  const [action, setActionParam] = useUrlFilter('action');
  const [entityType, setEntityTypeParam] = useUrlFilter('entity_type');
  const [userId, setUserIdParam] = useUrlFilter('user_id');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const setAction = (v) => { setPage(1); setActionParam(v); };
  const setEntityType = (v) => { setPage(1); setEntityTypeParam(v); };
  const setUserId = (v) => { setPage(1); setUserIdParam(v); };

  const load = useCallback(() => {
    setLoading(true);
    const params = { page, limit, action, entity_type: entityType };
    if (userId) params.user_id = userId;
    adminService.auditLogs(params)
      .then((d) => { setRows(d.items || d); setTotal(d.total ?? (d.items?.length || 0)); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, action, entityType, userId]);
  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'created_at', label: 'When', render: (r) => r.created_at ? formatDateTime(r.created_at) : '—' },
    { key: 'user_email', label: 'User', render: (r) => r.user_email || r.user_id || '—',
      drill: (r) => (r.user_id && String(r.user_id) !== userId ? { onClick: () => setUserId(String(r.user_id)), title: 'Only this user' } : null) },
    { key: 'action', label: 'Action',
      drill: (r) => (r.action && r.action !== action ? { onClick: () => setAction(r.action), title: 'Only this action' } : null) },
    { key: 'entity_type', label: 'Entity type',
      drill: (r) => (r.entity_type && r.entity_type !== entityType ? { onClick: () => setEntityType(r.entity_type), title: 'Only this entity type' } : null) },
    { key: 'entity_id', label: 'Entity ID', drill: (r) => entityHref(r) },
    { key: 'ip_address', label: 'IP' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Audit logs</h1>
          <p className="text-sm text-muted">Every action, blog transition, campaign change, bot decision and Engage push.</p>
        </div>
      </div>
      <FilterBar>
        <input placeholder="Filter action…" aria-label="Action" value={action} onChange={(e) => setAction(e.target.value)} />
        <input placeholder="Filter entity type…" aria-label="Entity type" value={entityType} onChange={(e) => setEntityType(e.target.value)} />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Apply</button>
      </FilterBar>
      {userId && (
        <div className="flex items-center gap-2 mb-2">
          <button type="button" className="badge badge-secondary" onClick={() => setUserId('')} aria-label="Clear user filter">
            User #{userId} ✕
          </button>
          <span className="text-xs text-muted">{total} matching entr{total === 1 ? 'y' : 'ies'}</span>
        </div>
      )}

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No audit entries." />
        </Card>
      )}
      <Pagination page={page} limit={limit} total={total} onPage={setPage} />
    </div>
  );
}
