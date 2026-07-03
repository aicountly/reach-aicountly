import { useCallback, useEffect, useState } from 'react';
import { Check, X } from 'lucide-react';
import { approvalService } from '../services/approvalService';
import { Card } from '../components/common/Card';
import { Alert } from '../components/common/Alert';
import { Loader } from '../components/common/Loader';
import { DataTable } from '../components/common/DataTable';
import { ApprovalBadge } from '../components/common/ApprovalBadge';
import { FilterBar } from '../components/common/FilterBar';

export function ApprovalsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [status, setStatus] = useState('pending');

  const load = useCallback(() => {
    setLoading(true);
    approvalService.list({ decision: status })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status]);
  useEffect(load, [load]);

  const decide = async (id, decision) => {
    const note = decision === 'rejected' ? (window.prompt('Reason (optional):') || '') : '';
    try { await approvalService.decide(id, decision, note); load(); }
    catch (e) { setError(e.message); }
  };

  const columns = [
    { key: 'id', label: '#' },
    { key: 'subject_type', label: 'Subject type' },
    { key: 'subject_id', label: 'Subject ID' },
    { key: 'summary', label: 'Summary', render: (r) => r.summary || '—' },
    { key: 'decision', label: 'Decision', render: (r) => <ApprovalBadge status={r.decision === 'pending' ? 'pending' : r.decision} /> },
    { key: 'created_at', label: 'Created', render: (r) => r.created_at ? new Date(r.created_at).toLocaleString() : '—' },
    { key: 'actions', label: '', render: (r) => (
      r.decision === 'pending' ? (
        <div className="flex gap-2">
          <button className="btn btn-primary btn-sm" onClick={() => decide(r.id, 'approved')}><Check size={13}/> Approve</button>
          <button className="btn btn-danger btn-sm"  onClick={() => decide(r.id, 'rejected')}><X size={13}/> Reject</button>
        </div>
      ) : null
    )},
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Console approvals</h1>
          <p className="text-sm text-muted">Pending approvals across blog, campaigns, social & bot actions.</p>
        </div>
      </div>
      <FilterBar>
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
      </FilterBar>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable columns={columns} rows={rows} emptyMessage="No approvals matching this filter." />
        </Card>
      )}
    </div>
  );
}
