import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Eye, Pencil, ExternalLink } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { extractImageUrls } from './BlogArticlePreview';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

const LIVE_STATUSES = 'published,live,publishing,publish_queued,verification_pending';

/**
 * Published / live blogs — open full content and edit (new version) after go-live.
 */
export function BlogPublishedManagePage() {
  const [items, setItems] = useState([]);
  const [previews, setPreviews] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const nav = useNavigate();
  const { has } = usePermission();
  const canEdit = has('content.edit');

  const load = useCallback(() => {
    setLoading(true);
    contentService.listItems({
      content_type: 'blog',
      workflow_status: LIVE_STATUSES,
    })
      .then(async (d) => {
        const list = d.items ?? d ?? [];
        setItems(list);
        const entries = await Promise.all(
          list.slice(0, 30).map(async (row) => {
            try {
              return [row.id, await contentService.getItem(row.id, 'current_version')];
            } catch {
              return [row.id, null];
            }
          }),
        );
        setPreviews(Object.fromEntries(entries));
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  if (loading) return <Loader label="Loading published blogs…" />;

  return (
    <div>
      <div className="page-header" style={{ marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0 }}>Published Blogs</h2>
          <p className="text-sm text-muted" style={{ marginTop: 4 }}>
            Live and recently published posts. Edit creates a new version — republish after changes if needed.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {items.length === 0 && !error && (
        <div className="empty-state">
          <p>No published blog items yet.</p>
          <p className="text-sm text-muted">
            After you approve and publish from{' '}
            <Link to={ROUTES.BCC_PUBLISHING_READY}>Ready to Publish</Link>, posts appear here.
          </p>
        </div>
      )}

      <div className="bcc-review-queue">
        {items.map((item) => {
          const full = previews[item.id];
          const version = full?.current_version;
          const thumb = full?.publication_profile?.featured_image_reference
            || extractImageUrls(version?.body_html || '')[0]
            || null;
          const liveUrl = full?.publication_profile?.canonical_url
            || item.canonical_url
            || null;

          return (
            <article key={item.id} className="bcc-review-card">
              <div className="bcc-review-card__media">
                {thumb ? <img src={thumb} alt="" loading="lazy" /> : (
                  <div className="bcc-review-card__placeholder">No image</div>
                )}
              </div>
              <div className="bcc-review-card__body">
                <ContentStatusBadge status={item.workflow_status} />
                <h3 style={{ margin: '8px 0 6px', fontSize: 16 }}>{item.title}</h3>
                <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 8 }}>/{item.slug}</div>
                {(version?.summary || item.summary) && (
                  <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>
                    {(version?.summary || item.summary || '').slice(0, 200)}
                  </p>
                )}
                <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    onClick={() => nav(`/blog-command-centre/verification/review/${item.id}`)}
                  >
                    <Eye size={13} /> View content
                  </button>
                  {canEdit && (
                    <Link
                      to={`${ROUTES.CONTENT_EDIT.replace(':id', item.id)}?returnTo=${encodeURIComponent(ROUTES.BCC_PUBLISHED)}`}
                      className="btn btn-primary btn-sm"
                    >
                      <Pencil size={13} /> Edit published content
                    </Link>
                  )}
                  {liveUrl && (
                    <a href={liveUrl} target="_blank" rel="noopener noreferrer" className="btn btn-ghost btn-sm">
                      <ExternalLink size={13} /> Open live
                    </a>
                  )}
                </div>
              </div>
            </article>
          );
        })}
      </div>
    </div>
  );
}

export default BlogPublishedManagePage;
