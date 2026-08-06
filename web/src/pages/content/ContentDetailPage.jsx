import { useCallback, useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Edit2, FileText, MessageSquare, CheckSquare, Clock, GitBranch, Send } from 'lucide-react';
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

const AWAITING_HUMAN = new Set(['review_pending', 'internal_review', 'seo_review']);
// A deployment sitting in one of these has been accepted by Reach but not yet
// carried out — the `publishing` queue worker still has to pick it up.
const DEPLOYMENT_PENDING = new Set(['draft', 'ready', 'queued', 'sending']);
const DEPLOYMENT_TONE = {
  published: '#059669',
  verified: '#059669',
  scheduled: '#2563eb',
  failed: '#dc2626',
  unpublished: '#6b7280',
  rolled_back: '#d97706',
};
// Mirrors the sources the submit endpoint accepts: ContentWorkflowService::submit()
// for generic items, BlogHumanApprovalService::submitForReview() for blogs.
const SUBMITTABLE = new Set(['draft', 'validation_pending', 'changes_requested', 'fact_verified']);
const PUBLISHABLE = new Set(['approved', 'scheduled']);

export function ContentDetailPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const [item, setItem]             = useState(null);
  const [transitions, setTrans]     = useState([]);
  const [deployment, setDeployment] = useState(null);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState(null);
  const [actioning, setActioning]   = useState(null);

  const canEdit     = has('content.edit');
  const canSubmit   = has('content.submit');
  const canApprove  = has('content.approve');
  const canReview   = has('content.review');
  const canPublish  = has('publishing.publish');
  const canSeePublishing = has('publishing.view') || canPublish;

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

  // Publish is asynchronous: the API only queues a deployment, so without this
  // the page gave no sign of whether the article ever reached the public site.
  const loadDeployment = useCallback(async () => {
    if (!canSeePublishing) return;
    try {
      const rows = await contentService.listDeployments(id);
      setDeployment(Array.isArray(rows) && rows.length > 0 ? rows[0] : null);
    } catch {
      // Publishing visibility is supplementary — never block the detail page.
      setDeployment(null);
    }
  }, [id, canSeePublishing]);

  useEffect(() => { loadDeployment(); }, [loadDeployment]);

  // While a deployment is waiting on the worker, poll so the page settles on
  // the real outcome instead of leaving the user to guess.
  const deploymentStatus = deployment?.status;
  useEffect(() => {
    if (!deploymentStatus || !DEPLOYMENT_PENDING.has(deploymentStatus)) return undefined;
    const timer = setInterval(loadDeployment, 10000);
    return () => clearInterval(timer);
  }, [deploymentStatus, loadDeployment]);

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

  const handlePublish = async () => {
    if (!window.confirm('Publish this blog to AICOUNTLY.com now?')) return;
    setActioning('publish');
    try {
      await contentService.publishItem(id, item?.current_version_id);
      load();
      loadDeployment();
    } catch (e) {
      setError(e.message);
    } finally {
      setActioning(null);
    }
  };

  if (loading) return <Loader />;
  if (error && !item) return <Alert variant="danger">{error}</Alert>;
  if (!item) return <Alert variant="warning">Content item not found.</Alert>;

  const awaitingHuman = AWAITING_HUMAN.has(item.workflow_status);
  const canPublishNow = canPublish
    && item.content_type === 'blog'
    && item.approval_status === 'approved'
    && PUBLISHABLE.has(item.workflow_status);

  const detailLinks = [
    { to: ROUTES.CONTENT_VERSIONS.replace(':id', id), icon: <GitBranch size={14} />, label: 'Versions' },
    { to: ROUTES.CONTENT_BRIEF.replace(':id', id), icon: <FileText size={14} />, label: 'Brief' },
    { to: ROUTES.CONTENT_COMMENTS.replace(':id', id), icon: <MessageSquare size={14} />, label: 'Comments' },
    { to: ROUTES.CONTENT_VALIDATIONS.replace(':id', id), icon: <CheckSquare size={14} />, label: 'Validations' },
    { to: ROUTES.CONTENT_SCHEDULE.replace(':id', id), icon: <Clock size={14} />, label: 'Schedule' },
  ];

  return (
    <div>
      {error && <Alert variant="danger" style={{ marginBottom: 12 }}>{error}</Alert>}

      <div className="page-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
        <div>
          <div style={{ display: 'flex', gap: 8, marginBottom: 6, flexWrap: 'wrap' }}>
            <ContentTypeBadge type={item.content_type} />
            <ContentRiskBadge level={item.risk_level} />
          </div>
          <h1 style={{ margin: 0 }}>{item.title}</h1>
          <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>/{item.slug}</div>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {canEdit && (
            <Link to={ROUTES.CONTENT_EDIT.replace(':id', id)} className="btn btn-secondary">
              <Edit2 size={13} /> Edit
            </Link>
          )}
          {canSubmit && SUBMITTABLE.has(item.workflow_status) && (
            <button className="btn btn-primary" onClick={handleSubmit} disabled={actioning === 'submit'}>
              Submit for Review
            </button>
          )}
          {canApprove && awaitingHuman && (
            <button className="btn btn-primary" onClick={handleApprove} disabled={actioning === 'approve'}>
              Approve
            </button>
          )}
          {canReview && awaitingHuman && (
            <>
              <button className="btn btn-warning" onClick={handleReturn} disabled={actioning === 'return'}>
                Request Changes
              </button>
              <button className="btn btn-danger" onClick={handleReject} disabled={actioning === 'reject'}>
                Reject
              </button>
            </>
          )}
          {canPublishNow && (
            <button className="btn btn-primary" onClick={handlePublish} disabled={actioning === 'publish'}>
              <Send size={13} /> Publish now
            </button>
          )}
        </div>
      </div>

      <Card style={{ marginBottom: 16 }}>
        <WorkflowStatusBar currentStatus={item.workflow_status} nextStatuses={transitions} />
      </Card>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        {detailLinks.map((l) => (
          <Link key={l.to} to={l.to} className="btn btn-ghost btn-sm" style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            {l.icon} {l.label}
          </Link>
        ))}
      </div>

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

      {canSeePublishing && deployment && (
        <Card style={{ marginTop: 12 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6, gap: 12 }}>
            <div style={{ fontSize: 11, color: '#6b7280' }}>Publication</div>
            <button className="btn btn-ghost btn-sm" onClick={loadDeployment}>Refresh</button>
          </div>
          <div style={{ fontSize: 13, fontWeight: 600, color: DEPLOYMENT_TONE[deployment.status] }}>
            {deployment.status?.replace(/_/g, ' ')}
            <span style={{ fontWeight: 400, color: '#6b7280' }}> · deployment #{deployment.id}</span>
          </div>
          {DEPLOYMENT_PENDING.has(deployment.status) && (
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 6, lineHeight: 1.6 }}>
              Queued for the publishing worker. If it stays here for more than a few minutes, the
              worker is not draining the <code>publishing</code> queue — check the
              <code> reach:work --queue=default,blog,publishing</code> cron.
            </div>
          )}
          {deployment.status === 'failed' && (
            <div style={{ fontSize: 12, color: '#dc2626', marginTop: 6, lineHeight: 1.6 }}>
              {deployment.error_category?.replace(/_/g, ' ')}
              {deployment.redacted_error ? ` — ${deployment.redacted_error}` : ''}
            </div>
          )}
          {deployment.canonical_url && (
            <div style={{ fontSize: 12, marginTop: 6 }}>
              <a href={deployment.canonical_url} target="_blank" rel="noreferrer">{deployment.canonical_url}</a>
            </div>
          )}
          {deployment.scheduled_at && (
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 6 }}>
              Scheduled for {new Date(deployment.scheduled_at).toLocaleString()}
            </div>
          )}
        </Card>
      )}

      {item.summary && (
        <Card style={{ marginTop: 12 }}>
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 6 }}>Summary</div>
          <div style={{ fontSize: 13, lineHeight: 1.6 }}>{item.summary}</div>
        </Card>
      )}
    </div>
  );
}
