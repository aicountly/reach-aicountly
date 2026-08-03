import { ContentStatusBadge } from '../../components/content/ContentStatusBadge';

/** Pull absolute/relative image URLs from HTML for a gallery strip. */
export function extractImageUrls(html = '') {
  if (!html) return [];
  const urls = [];
  const re = /<img[^>]+src=["']([^"']+)["']/gi;
  let match;
  while ((match = re.exec(html)) !== null) {
    if (match[1] && !urls.includes(match[1])) urls.push(match[1]);
  }
  return urls;
}

/**
 * Read-only article preview: featured image, body HTML, and image gallery.
 * Used by BCC review and publishing surfaces.
 */
export function BlogArticlePreview({
  title,
  summary,
  version,
  publicationProfile,
  mediaRequirements = [],
}) {
  const bodyHtml = version?.body_html
    || (version?.body_markdown ? `<pre style="white-space:pre-wrap;font-family:inherit">${escapeHtml(version.body_markdown)}</pre>` : '')
    || (version?.body_plain_text ? `<pre style="white-space:pre-wrap;font-family:inherit">${escapeHtml(version.body_plain_text)}</pre>` : '');

  const featured = publicationProfile?.featured_image_reference
    || publicationProfile?.featured_image_url
    || mediaRequirements.find((m) => m.media_type === 'featured_image' && (m.public_url || m.asset_reference))?.public_url
    || mediaRequirements.find((m) => m.media_type === 'featured_image')?.asset_reference
    || null;

  const featuredAlt = publicationProfile?.featured_image_alt
    || mediaRequirements.find((m) => m.media_type === 'featured_image')?.alt_text
    || title
    || 'Featured image';

  const inlineImages = extractImageUrls(bodyHtml);
  const mediaUrls = mediaRequirements
    .map((m) => m.public_url || m.asset_reference)
    .filter(Boolean)
    .filter((u) => u !== featured && !inlineImages.includes(u));

  const gallery = [...inlineImages, ...mediaUrls];

  return (
    <article className="bcc-article-preview">
      {featured && (
        <figure className="bcc-article-preview__featured">
          <img src={featured} alt={featuredAlt} loading="lazy" />
          {featuredAlt && featuredAlt !== title && (
            <figcaption>{featuredAlt}</figcaption>
          )}
        </figure>
      )}

      {title && <h1 className="bcc-article-preview__title">{title}</h1>}
      {(summary || version?.summary) && (
        <p className="bcc-article-preview__summary">{summary || version?.summary}</p>
      )}

      {bodyHtml ? (
        <div
          className="bcc-article-preview__body"
          dangerouslySetInnerHTML={{ __html: bodyHtml }}
        />
      ) : (
        <p className="text-muted">No draft body is available for this version yet.</p>
      )}

      {gallery.length > 0 && (
        <section className="bcc-article-preview__gallery" aria-label="Images in this article">
          <h3>Images ({gallery.length})</h3>
          <div className="bcc-article-preview__gallery-grid">
            {gallery.map((src) => (
              <a key={src} href={src} target="_blank" rel="noopener noreferrer">
                <img src={src} alt="" loading="lazy" />
              </a>
            ))}
          </div>
        </section>
      )}
    </article>
  );
}

export function BlogReviewMetaRow({ item }) {
  if (!item) return null;
  return (
    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12 }}>
      <ContentStatusBadge status={item.workflow_status} />
      <span style={{ fontSize: 12, color: '#6b7280' }}>
        Approval: {item.approval_status?.replace(/_/g, ' ') || '—'}
      </span>
      {item.risk_level && (
        <span style={{ fontSize: 12, color: '#6b7280' }}>
          Risk: {item.risk_level}
        </span>
      )}
      {item.slug && (
        <span style={{ fontSize: 12, color: '#6b7280' }}>/{item.slug}</span>
      )}
    </div>
  );
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}
