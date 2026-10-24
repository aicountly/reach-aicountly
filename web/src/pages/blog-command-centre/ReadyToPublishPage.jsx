import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Eye, Pencil, Send } from 'lucide-react';
import { contentService } from '../../services/contentService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';
import { ContentRiskBadge } from '../../components/content/ContentRiskBadge';
import { extractImageUrls } from './BlogArticlePreview';
import { ROUTES } from '../../constants/routes';
import { usePermission } from '../../hooks/usePermission';

function reviewPath(id) {
  return `/blog-command-centre/verification/review/${id}`;
}

/**
 * Ready to Publish — approved blog items with edit + publish actions.
 */
export function ReadyToPublishPage() {
  const [items, setItems] = useState([]);
  const [previews, setPreviews] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [publishingId, setPublishingId] = useState(null);
  const [notice, setNotice] = useState(null);
  const nav = useNavigate();
  const { has } = usePermission();
  const canPublish = has('publishing.publish');
  const canEdit = has('content.edit');

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    contentService.listItems({
      content_type: 'blog',
      workflow_status: 'approved',
      approval_status: 'approved',
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

  const publish = async (item) => {
    if (!window.confirm(`Publish “${item.title}” to aicountly.com now?`)) return;
    setPublishingId(item.id);
    setNotice(null);
    setError(null);
    try {
      await contentService.publishItem(item.id, item.current_version_id);
      setNotice(`Queued publish for “${item.title}” — Gemini approved this version. The worker sends it within a minute.`);
      load();
    } catch (e) {
      setError(e.message);
    } finally {
      setPublishingId(null);
    }
  };

  if (loading) return <Loader label="Loading approved blogs…" />;

  return (
    <div>
      <div className="page-header" style={{ marginBottom: 16 }}>
        <div>
          <h2 style={{ margin: 0 }}>Ready to Publish</h2>
          <p className="text-sm text-muted" style={{ marginTop: 4 }}>
            Approved blogs not yet live. Routine articles publish on their own; anything listed here
            is checked by Gemini against its current text before it goes out, including Publish now.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {items.length === 0 && !error && (
        <div className="empty-state">
          <p>No approved blogs are waiting.</p>
          <p className="text-sm text-muted">
            Routine articles are vetted, approved and published automatically — follow them in{' '}
            <Link to={ROUTES.BCC_VERIFICATION}>Verification</Link>.
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
          const excerpt = version?.summary || item.summary || version?.body_plain_text?.slice(0, 200);

          return (
            <article key={item.id} className="bcc-review-card">
              <div className="bcc-review-card__media">
                {thumb ? <img src={thumb} alt="" loading="lazy" /> : (
                  <div className="bcc-review-card__placeholder">No image</div>
                )}
              </div>
              <div className="bcc-review-card__body">
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 6 }}>
                  <ContentStatusBadge status={item.workflow_status} />
                  <ContentRiskBadge level={item.risk_level} />
                </div>
                <h3 style={{ margin: '0 0 6px', fontSize: 16 }}>{item.title}</h3>
                <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 8 }}>/{item.slug}</div>
                {excerpt && (
                  <p style={{ margin: 0, fontSize: 13, color: '#374151', lineHeight: 1.5 }}>
                    {excerpt.length > 200 ? `${excerpt.slice(0, 200)}…` : excerpt}
                  </p>
                )}
                <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    onClick={() => nav(reviewPath(item.id))}
                  >
                    <Eye size={13} /> View full post
                  </button>
                  {canEdit && (
                    <Link
                      to={`${ROUTES.CONTENT_EDIT.replace(':id', item.id)}?returnTo=${encodeURIComponent(ROUTES.BCC_PUBLISHING_READY)}`}
                      className="btn btn-secondary btn-sm"
                    >
                      <Pencil size={13} /> Edit before publish
                    </Link>
                  )}
                  {canPublish && (
                    <button
                      type="button"
                      className="btn btn-primary btn-sm"
                      disabled={publishingId === item.id}
                      onClick={() => publish(item)}
                    >
                      <Send size={13} />
                      {publishingId === item.id ? 'Publishing…' : 'Publish now'}
                    </button>
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

export default ReadyToPublishPage;
