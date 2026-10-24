import { DrillLink } from '../../components/drill';
import { label, shortUrl } from './refreshShared.js';

/**
 * The content a recommendation / workflow is about: its title (blogs) or URL,
 * linking to that identity's content analytics; the blog item itself is a
 * second link when known.
 */
export default function ContentCell({ row }) {
  const id = row.content_identity_id;
  const name = row.content_title || shortUrl(row.canonical_url) || `Identity #${id}`;
  return (
    <div onClick={(e) => e.stopPropagation()} role="presentation">
      <DrillLink to={id ? `/intelligence/content/${id}` : undefined} title="Content analytics">{name}</DrillLink>
      <div className="text-xs text-muted">
        {row.content_type ? `${label(row.content_type)} · ` : ''}identity #{id}
        {row.content_item_id ? (
          <> · <DrillLink to={`/content/${row.content_item_id}`} title="Open the content item">item #{row.content_item_id}</DrillLink></>
        ) : null}
      </div>
    </div>
  );
}
