import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { botService } from '../../services/botService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';

export function BotReportsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    botService.reports({ limit: 100 })
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const columns = [
    { key: 'id', label: '#' },
    { key: 'action', label: 'Action', render: (r) => (r.action || '').replace(/_/g,' ') },
    { key: 'mode', label: 'Mode' },
    { key: 'approval_status', label: 'Approval', render: (r) => <ApprovalBadge status={r.approval_status} /> },
    { key: 'publishing_status', label: 'Publishing', render: (r) => <StatusBadge status={r.publishing_status || 'none'} /> },
    { key: 'created_at', label: 'When', render: (r) => r.created_at ? new Date(r.created_at).toLocaleString() : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Marketing bot reports</h1>
          <p className="text-sm text-muted">Local audit log of what the bot understood, generated and did.</p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable
            columns={columns}
            rows={rows}
            onRowClick={(r) => navigate(`/bot/reports/${r.id}`)}
            emptyMessage="No bot reports yet."
          />
        </Card>
      )}
    </div>
  );
}
