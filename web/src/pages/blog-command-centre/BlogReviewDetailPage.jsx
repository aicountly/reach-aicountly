import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Check, Pencil, RotateCcw, Send, X } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { Card } from '../../components/common/Card';
import { BlogArticlePreview, BlogReviewMetaRow } from './BlogArticlePreview';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

const AWAITING = new Set(['internal_review', 'seo_review', 'review_pending']);
const PUBLISHABLE = new Set(['approved', 'scheduled']);

export function BlogReviewDetailPage() {
  const { id } = useParams();
  const nav = useNavigate();
  const { has } = usePermission();
  const [item, setItem] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [actioning, setActioning] = useState(null);
  const [notice, setNotice] = useState(null);

  const canEdit = has('content.edit');
  const canApprove = has('content.approve');
  const canReview = has('content.review');
  const canPublish = has('publishing.publish');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    contentService.getItem(id, 'current_version')
      .then(setItem)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const run = async (key, fn) => {
    setActioning(key);
    setNotice(null);
    setError(null);
    try {
      await fn();
      setNotice(key === 'approve' ? 'Approved. You can publish from Ready to Publish.' : 'Saved.');
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setActioning(null);
    }
  };

  if (loading) return <Loader label="Loading blog draft…" />;
  if (error && !item) return <Alert variant="danger">{error}</Alert>;
  if (!item) return <Alert variant="warning">Blog not found.</Alert>;

  const version = item.current_version;
  const awaiting = AWAITING.has(item.workflow_status);
  const canPublishNow = canPublish
    && item.approval_status === 'approved'
    && PUBLISHABLE.has(item.workflow_status);
  const editHref = `${ROUTES.CONTENT_EDIT.replace(':id', id)}?returnTo=${encodeURIComponent(`/blog-command-centre/verification/review/${id}`)}`;

  return (
    <div>
      <div style={{ marginBottom: 12 }}>
        <button
          type="button"
          className="btn btn-secondary btn-sm"
          onClick={() => nav(ROUTES.BCC_VERIFICATION)}
        >
          <ArrowLeft size={12} /> Back to queue
        </button>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      <div className="page-header" style={{ display: 'flex', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0 }}>{item.title || '(untitled)'}</h2>
          <BlogReviewMetaRow item={item} />
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {canEdit && (
            <Link to={editHref} className="btn btn-secondary">
              <Pencil size={13} /> Edit content
            </Link>
          )}
          {canApprove && awaiting && (
            <button
              type="button"
              className="btn btn-primary"
              disabled={!!actioning}
              onClick={() => run('approve', () => contentService.approveItem(id, 'final_approval', ''))}
            >
              <Check size={13} /> {actioning === 'approve' ? 'Approving…' : 'Approve'}
            </button>
          )}
          {canReview && awaiting && (
            <>
              <button
                type="button"
                className="btn btn-warning"
                disabled={!!actioning}
                onClick={() => {
                  const reason = window.prompt('Reason for requesting changes (required):');
                  if (!reason) return;
                  run('return', () => contentService.requestChanges(id, reason));
                }}
              >
                <RotateCcw size={13} /> Request changes
              </button>
              <button
                type="button"
                className="btn btn-danger"
                disabled={!!actioning}
                onClick={() => {
                  const reason = window.prompt('Rejection reason (required):');
                  if (!reason) return;
                  run('reject', () => contentService.rejectItem(id, 'final_approval', reason));
                }}
              >
                <X size={13} /> Reject
              </button>
            </>
          )}
          {canPublishNow && (
            <button
              type="button"
              className="btn btn-primary"
              disabled={!!actioning}
              onClick={() => {
                if (!window.confirm('Publish this blog to AICOUNTLY.com now?')) return;
                run('publish', () => contentService.publishItem(id, item.current_version_id));
              }}
            >
              <Send size={13} /> {actioning === 'publish' ? 'Publishing…' : 'Publish now'}
            </button>
          )}
        </div>
      </div>

      {item.workflow_status === 'approved' && (
        <Alert variant="info">
          This post is approved. Open{' '}
          <Link to={ROUTES.BCC_PUBLISHING_READY}>Ready to Publish</Link>
          {' '}to publish, or edit content first (editing creates a new version and may require re-approval for high-risk posts).
        </Alert>
      )}

      <div className="bcc-review-detail-layout">
        <Card>
          <BlogArticlePreview
            title={version?.title || item.title}
            summary={item.summary}
            version={version}
            publicationProfile={item.publication_profile}
            mediaRequirements={item.media_requirements}
          />
        </Card>

        <aside className="bcc-review-detail-aside" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <Card title="Version">
            <div style={{ fontSize: 13, lineHeight: 1.6 }}>
              <div>Current version: #{item.current_version_id || '—'}</div>
              {version?.version_number != null && <div>Number: {version.version_number}</div>}
              {version?.created_at && (
                <div>Created: {new Date(version.created_at).toLocaleString()}</div>
              )}
            </div>
          </Card>
          <Card title="SEO">
            <div style={{ fontSize: 13, lineHeight: 1.6 }}>
              <div><span className="text-muted">Title:</span> {version?.title || item.title || '—'}</div>
              <div><span className="text-muted">Summary:</span> {version?.summary || item.summary || '—'}</div>
            </div>
          </Card>
          <Card title="Actions help">
            <ol style={{ margin: 0, paddingLeft: 18, fontSize: 12, color: '#4b5563', lineHeight: 1.6 }}>
              <li>Read the full draft and images.</li>
              <li>Use Edit content if anything needs fixing.</li>
              <li>Approve when ready — then publish from Ready to Publish.</li>
            </ol>
          </Card>
        </aside>
      </div>
    </div>
  );
}

export default BlogReviewDetailPage;
