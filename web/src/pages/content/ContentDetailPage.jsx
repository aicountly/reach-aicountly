import { useCallback, useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Edit2, FileText, MessageSquare, CheckSquare, Clock, GitBranch, Map } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { ContentTypeBadge } from '../../components/content/ContentTypeBadge';
import { ContentRiskBadge } from '../../components/content/ContentRiskBadge';
import { WorkflowStatusBar } from '../../components/content/WorkflowStatusBar';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

export function ContentDetailPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const [item, setItem]             = useState(null);
  const [transitions, setTrans]     = useState([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);
  const [actioning, setActioning]   = useState(null);

  const canEdit    = has('content.edit');
  const canSubmit  = has('content.submit');
  const canApprove = has('content.approve');
  const canReview  = has('content.review');

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [itemData, transData] = await Promise.all([
        contentService.getItem(id),
        contentService.getTransitions(id),
      ]);
      setItem(itemData);
      setTrans(transData.next_statuses || []);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const handleSubmit = async () => {
    setActioning('submit');
    try { await contentService.submitItem(id); load(); }
    catch (e) { setError(e.message); }
    finally { setActioning(null); }
  };

  const handleApprove = async () => {
    setActioning('approve');
    try { await contentService.approveItem(id, 'final_approval', ''); load(); }
    catch (e) { setError(e.message); }
    finally { setActioning(null); }
  };

  const handleReject = async () => {
    const reason = window.prompt('Rejection reason (required):');
    if (!reason) return;
    setActioning('reject');
    try { await contentService.rejectItem(id, 'final_approval', reason); load(); }
    catch (e) { setError(e.message); }
    finally { setActioning(null); }
  };

  const handleReturn = async () => {
    const reason = window.prompt('Reason for requesting changes (required):');
    if (!reason) return;
    setActioning('return');
    try { await contentService.requestChanges(id, reason); load(); }
    catch (e) { setError(e.message); }
    finally { setActioning(null); }
  };

  if (loading) return <Loader />;
  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!item) return null;

  const detailLinks = [
    { to: ROUTES.CONTENT_VERSIONS.replace(':id', id), label: 'Versions', icon: <GitBranch size={14} /> },
    { to: ROUTES.CONTENT_BRIEF.replace(':id', id), label: 'Brief', icon: <FileText size={14} /> },
    { to: ROUTES.CONTENT_COMMENTS.replace(':id', id), label: 'Comments', icon: <MessageSquare size={14} /> },
    { to: ROUTES.CONTENT_VALIDATIONS.replace(':id', id), label: 'Validations', icon: <CheckSquare size={14} /> },
    { to: ROUTES.CONTENT_SCHEDULE.replace(':id', id), label: 'Schedule', icon: <Clock size={14} /> },
  ];

  return (
    <div>
      {/* Header */}
      <div className="page-header">
        <div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 4 }}>
            <ContentTypeBadge type={item.content_type} />
            <ContentRiskBadge level={item.risk_level} />
          </div>
          <h1 style={{ margin: 0 }}>{item.title}</h1>
          <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>/{item.slug}</div>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          {canEdit && (
            <Link to={ROUTES.CONTENT_EDIT.replace(':id', id)} className="btn btn-secondary">
              <Edit2 size={13} /> Edit
            </Link>
          )}
          {canSubmit && item.workflow_status === 'draft' && (
            <button className="btn btn-primary" onClick={handleSubmit} disabled={actioning === 'submit'}>
              Submit for Review
            </button>
          )}
          {canApprove && item.workflow_status === 'review_pending' && (
            <button className="btn btn-primary" onClick={handleApprove} disabled={actioning === 'approve'}>
              Approve
            </button>
          )}
          {canReview && item.workflow_status === 'review_pending' && (
            <>
              <button className="btn btn-warning" onClick={handleReturn} disabled={actioning === 'return'}>
                Request Changes
              </button>
              <button className="btn btn-danger" onClick={handleReject} disabled={actioning === 'reject'}>
                Reject
              </button>
            </>
          )}
        </div>
      </div>

      {/* Workflow bar */}
      <Card style={{ marginBottom: 16 }}>
        <WorkflowStatusBar currentStatus={item.workflow_status} nextStatuses={transitions} />
      </Card>

      {/* Detail tabs */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        {detailLinks.map((l) => (
          <Link key={l.to} to={l.to} className="btn btn-ghost btn-sm" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            {l.icon} {l.label}
          </Link>
        ))}
      </div>

      {/* Meta */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12 }}>
        <Card>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>Status</div>
          <ContentStatusBadge status={item.workflow_status} />
        </Card>
        <Card>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>Approval</div>
          <div style={{ fontSize: 13, fontWeight: 600 }}>{item.approval_status?.replace(/_/g, ' ')}</div>
        </Card>
        <Card>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>Validation</div>
          <div style={{ fontSize: 13, fontWeight: 600 }}>{item.validation_status?.replace(/_/g, ' ')}</div>
        </Card>
        <Card>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>Language</div>
          <div style={{ fontSize: 13 }}>{item.language || 'en'}</div>
        </Card>
        <Card>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>Review Due</div>
          <div style={{ fontSize: 13, color: item.review_due_at && new Date(item.review_due_at) < new Date() ? '#ef4444' : undefined }}>
            {item.review_due_at ? new Date(item.review_due_at).toLocaleDateString() : '—'}
          </div>
        </Card>
        <Card>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 4 }}>Created</div>
          <div style={{ fontSize: 13 }}>{new Date(item.created_at).toLocaleString()}</div>
        </Card>
      </div>

      {item.summary && (
        <Card style={{ marginTop: 12 }}>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 6 }}>Summary</div>
          <div style={{ fontSize: 13, lineHeight: 1.6 }}>{item.summary}</div>
        </Card>
      )}
    </div>
  );
}

