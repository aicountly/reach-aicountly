import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { botService } from '../../services/botService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';
import { formatDateTime } from '../../utils/formatDate';

export function BotReportsPage() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const navigate = useNavigate();

  // The dashboard's "Reports pending" tile links here with the filter it
  // counted, so the page opens on those rows rather than the whole log.
  // BotReportController::index already filters on approval_status.
  const [searchParams, setSearchParams] = useSearchParams();
  const approvalStatus = searchParams.get('approval_status') ?? '';

  useEffect(() => {
    setLoading(true);
    const params = { limit: 100 };
    if (approvalStatus) params.approval_status = approvalStatus;
    botService.reports(params)
      .then((d) => setRows(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [approvalStatus]);

  const columns = [
    { key: 'id', label: '#' },
    { key: 'action', label: 'Action', render: (r) => (r.action || '').replace(/_/g,' ') },
    { key: 'mode', label: 'Mode' },
    { key: 'approval_status', label: 'Approval', render: (r) => <ApprovalBadge status={r.approval_status} /> },
    { key: 'publishing_status', label: 'Publishing', render: (r) => <StatusBadge status={r.publishing_status || 'none'} /> },
    { key: 'created_at', label: 'When', render: (r) => r.created_at ? formatDateTime(r.created_at) : '—' },
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Marketing bot reports</h1>
          <p className="text-sm text-muted">Local audit log of what the bot understood, generated and did.</p>
        </div>
        {approvalStatus && (
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => setSearchParams({}, { replace: true })}
          >
            Clear “{approvalStatus}” filter
          </button>
        )}
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false}>
          <DataTable
            columns={columns}
            rows={rows}
            onRowClick={(r) => navigate(`/bot/reports/${r.id}`)}
            emptyMessage={approvalStatus
              ? `No bot reports with approval status “${approvalStatus}”.`
              : 'No bot reports yet.'}
          />
        </Card>
      )}
    </div>
  );
}
