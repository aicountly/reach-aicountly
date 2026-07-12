import { useState } from 'react';
import { MessageSquare, Check, Trash2 } from 'lucide-react';
import { contentService } from '../../services/contentService';

function CommentBubble({ comment, onResolve, onDelete, canModerate }) {
  return (
    <div style={{
      background: comment.resolved_at ? '#f3f4f6' : '#fff',
      border: '1px solid ' + (comment.resolved_at ? '#e5e7eb' : '#dbeafe'),
      borderRadius: 8,
      padding: '10px 12px',
      opacity: comment.resolved_at ? 0.6 : 1,
    }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div style={{ fontSize: 11, color: '#6b7280' }}>
          #{comment.created_by || 'System'} · {comment.created_at ? new Date(comment.created_at).toLocaleString() : ''}
          {comment.resolved_at && <span style={{ marginLeft: 6, color: '#10b981' }}>✓ Resolved</span>}
        </div>
        {canModerate && !comment.resolved_at && (
          <div style={{ display: 'flex', gap: 4 }}>
            <button className="btn btn-ghost btn-sm" onClick={() => onResolve(comment.id)}>
              <Check size={12} /> Resolve
            </button>
            <button className="btn btn-ghost btn-sm" onClick={() => onDelete(comment.id)}>
              <Trash2 size={12} />
            </button>
          </div>
        )}
      </div>
      <div style={{ marginTop: 4, fontSize: 13 }} dangerouslySetInnerHTML={{ __html: comment.body }} />
      {comment.replies?.length > 0 && (
        <div style={{ marginTop: 8, paddingLeft: 16, borderLeft: '2px solid #e5e7eb' }}>
          {comment.replies.map((r) => (
            <CommentBubble key={r.id} comment={r} onResolve={onResolve} onDelete={onDelete} canModerate={canModerate} />
          ))}
        </div>
      )}
    </div>
  );
}

export function CommentThread({ contentItemId, comments = [], onRefresh, canModerate = false }) {
  const [newComment, setNewComment] = useState('');
  const [submitting, setSubmitting]  = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!newComment.trim()) return;
    setSubmitting(true);
    try {
      await contentService.addComment(contentItemId, newComment);
      setNewComment('');
      onRefresh?.();
    } finally {
      setSubmitting(false);
    }
  };

  const handleResolve = async (commentId) => {
    await contentService.resolveComment(contentItemId, commentId);
    onRefresh?.();
  };

  const handleDelete = async (commentId) => {
    if (!window.confirm('Delete this comment?')) return;
    await contentService.deleteComment(contentItemId, commentId);
    onRefresh?.();
  };

  return (
    <div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 12 }}>
        {comments.length === 0 && (
          <div style={{ color: '#9ca3af', fontSize: 13 }}>No comments yet.</div>
        )}
        {comments.map((c) => (
          <CommentBubble key={c.id} comment={c} onResolve={handleResolve} onDelete={handleDelete} canModerate={canModerate} />
        ))}
      </div>
      <form onSubmit={handleSubmit} style={{ display: 'flex', gap: 8 }}>
        <textarea
          value={newComment}
          onChange={(e) => setNewComment(e.target.value)}
          placeholder="Add a comment…"
          rows={2}
          style={{ flex: 1, borderRadius: 6, border: '1px solid #e5e7eb', padding: '6px 10px', fontSize: 13, resize: 'vertical' }}
        />
        <button type="submit" className="btn btn-primary btn-sm" disabled={submitting || !newComment.trim()}>
          <MessageSquare size={13} /> Post
        </button>
      </form>
    </div>
  );
}
