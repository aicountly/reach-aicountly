import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Eye, Pencil, CheckCircle2 } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { ContentRiskBadge } from '../../components/content/ContentRiskBadge';
import { extractImageUrls } from './BlogArticlePreview';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

const REVIEW_STATUSES = 'internal_review,seo_review,review_pending';

function reviewDetailPath(id) {
  return `/blog-command-centre/verification/review/${id}`;
}

function firstImage(item, version) {
  const featured = item?.publication_profile?.featured_image_reference
    || item?.publication_profile?.featured_image_url;
  if (featured) return featured;
  const fromMedia = (item?.media_requirements || [])
    .map((m) => m.public_url || m.asset_reference)
    .find(Boolean);
  if (fromMedia) return fromMedia;
  return extractImageUrls(version?.body_html || '')[0] || null;
}

/**
 * Verification Queue — blogs awaiting human approval with preview thumbnails.
 */
export function BlogReviewQueuePage() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [previews, setPreviews] = useState({});
  const nav = useNavigate();
  const { has } = usePermission();
  const canApprove = has('content.approve');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    contentService.listItems({
      content_type: 'blog',
      workflow_status: REVIEW_STATUSES,
    })
      .then(async (d) => {
        const list = d.items ?? d ?? [];
        setItems(list);
        // Warm thumbnails / excerpts for the first page of results.
        const entries = await Promise.all(
          list.slice(0, 20).map(async (row) => {
            try {
              const full = await contentService.getItem(row.id, 'current_version');
              return [row.id, full];
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

  if (loading) return <Loader label="Loading blogs awaiting review…" />;

  return (
    <div>
      <div className="page-header" style={{ marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0 }}>Verification Queue</h2>
          <p className="text-sm text-muted" style={{ marginTop: 4 }}>
            Review full blog drafts (content + images), edit if needed, then approve or reject.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}

      {!error && items.length === 0 && (
        <div className="empty-state">
          <CheckCircle2 size={36} style={{ color: '#10b981', marginBottom: 8 }} />
          <p>No blogs are awaiting review right now.</p>
          <p className="text-sm text-muted">
            When automation finishes drafts, they appear here for human approval.
          </p>
        </div>
      )}

      <div className="bcc-review-queue">
        {items.map((item) => {
          const full = previews[item.id];
          const version = full?.current_version;
          const thumb = firstImage(full || item, version);
          const excerpt = version?.summary || item.summary || version?.body_plain_text?.slice(0, 220);

          return (
            <article
              key={item.id}
              className="bcc-review-card"
              onClick={() => nav(reviewDetailPath(item.id))}
              onKeyDown={(e) => { if (e.key === 'Enter') nav(reviewDetailPath(item.id)); }}
              role="button"
              tabIndex={0}
            >
              <div className="bcc-review-card__media">
                {thumb ? (
                  <img src={thumb} alt="" loading="lazy" />
                ) : (
                  <div className="bcc-review-card__placeholder">No image</div>
                )}
              </div>
              <div className="bcc-review-card__body">
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 6 }}>
                  <ContentStatusBadge status={item.workflow_status} />
                  <ContentRiskBadge level={item.risk_level} />
                </div>
                <h3 style={{ margin: '0 0 6px', fontSize: 16 }}>{item.title || '(untitled)'}</h3>
                <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 8 }}>/{item.slug}</div>
                {excerpt && (
                  <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>
                    {excerpt.length > 220 ? `${excerpt.slice(0, 220)}…` : excerpt}
                  </p>
                )}
                <div
                  style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}
                  onClick={(e) => e.stopPropagation()}
                >
                  <Link
                    to={reviewDetailPath(item.id)}
                    className="btn btn-primary btn-sm"
                  >
                    <Eye size={13} /> Review full post
                  </Link>
                  {has('content.edit') && (
                    <Link
                      to={`${ROUTES.CONTENT_EDIT.replace(':id', item.id)}?returnTo=${encodeURIComponent(reviewDetailPath(item.id))}`}
                      className="btn btn-secondary btn-sm"
                    >
                      <Pencil size={13} /> Edit content
                    </Link>
                  )}
                  {canApprove && (
                    <span className="text-xs text-muted" style={{ alignSelf: 'center' }}>
                      Open review to approve
                    </span>
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

export default BlogReviewQueuePage;
