import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { botService } from '../../services/botService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';

function J({ v }) {
  if (v == null || (typeof v === 'string' && v === '')) return <div className="text-sm text-muted">(none)</div>;
  if (typeof v === 'object') {
    return <pre className="text-xs" style={{ whiteSpace: 'pre-wrap' }}>{JSON.stringify(v, null, 2)}</pre>;
  }
  return <div className="text-sm" style={{ whiteSpace: 'pre-wrap' }}>{String(v)}</div>;
}

export function BotReportDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [r, setR] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    botService.report(id).then(setR).catch((e) => setError(e.message));
  }, [id]);

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!r) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/bot/reports')}><ArrowLeft size={12}/> All reports</button>
          <h1 style={{ marginTop: 6 }}>{(r.action || '').replace(/_/g,' ')}</h1>
          <div className="flex gap-2 mt-1 flex-wrap">
            <span className="badge badge-secondary">mode: {r.mode}</span>
            <ApprovalBadge status={r.approval_status} />
            <StatusBadge status={r.publishing_status || 'none'} />
            <span className="text-xs text-muted">{r.created_at ? new Date(r.created_at).toLocaleString() : ''}</span>
          </div>
        </div>
      </div>

      <div className="grid grid-2">
        <Card title="Understood"><J v={r.understanding} /></Card>
        <Card title="Data accessed"><J v={r.data_accessed} /></Card>
        <Card title="Content generated"><J v={r.content_generated} /></Card>
        <Card title="Recommended action"><J v={r.recommended_action} /></Card>
        <Card title="Action taken"><J v={r.action_taken} /></Card>
        <Card title="Next recommended action"><J v={r.next_recommended_action} /></Card>
        <Card title="Errors"><J v={r.errors} /></Card>
        <Card title="Evidence"><J v={r.evidence} /></Card>
      </div>
    </div>
  );
}
