import { ChannelBadge } from '../common/ChannelBadge';
import { StatusBadge } from '../common/StatusBadge';
import { formatDateTime } from '../../utils/formatDate';

export function SocialQueueItem({ post, onMarkPosted }) {
  return (
    <div className="card" style={{ padding: 12 }}>
      <div className="flex items-center gap-2 mb-2 flex-wrap">
        <ChannelBadge channel={post.channel} />
        <StatusBadge status={post.status} />
        <span className="text-xs text-muted" style={{ marginLeft: 'auto' }}>
          Scheduled: {formatDateTime(post.scheduled_at)}
        </span>
      </div>
      <div className="text-sm" style={{ whiteSpace: 'pre-wrap' }}>{post.content}</div>
      {post.status !== 'posted' && onMarkPosted && (
        <div className="flex justify-end mt-2">
          <button className="btn btn-secondary btn-sm" onClick={() => onMarkPosted(post)}>Mark posted</button>
        </div>
      )}
    </div>
  );
}
