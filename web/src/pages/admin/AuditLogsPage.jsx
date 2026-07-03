import { useCallback, useEffect, useState } from 'react';
import { adminService } from '../../services/adminService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { Pagination } from '../../components/common/Pagination';

export function AuditLogsPage() {
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [limit] = useState(50);
  const [action, setAction] = useState('');
  const [entityType, setEntityType] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    adminService.auditLogs({ page, limit, action, entity_type: entityType })
      .then((d) => { setRows(d.items || d); setTotal(d.total ?? (d.items?.length || 0)); })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [page, limit, action, entityType]);
  useEffect(() => { load(); }, [load]);

  const columns = [
    { key: 'created_at', label: 'When', render: (r) => r.created_at ? new Date(r.created_at).toLocaleString() : '—' },
    { key: 'user_email', label: 'User', render: (r) => r.user_email || r.user_id || '—' },
    { key: 'action', label: 'Action' },
    { key: 'entity_type', label: 'Entity type' },
    { key: 'entity_id', label: 'Entity ID' },
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
        <input placeholder="Filter action…" value={action} onChange={(e) => setAction(e.target.value)} />
        <input placeholder="Filter entity type…" value={entityType} onChange={(e) => setEntityType(e.target.value)} />
        <button className="btn btn-secondary" onClick={() => { setPage(1); load(); }}>Apply</button>
      </FilterBar>

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
