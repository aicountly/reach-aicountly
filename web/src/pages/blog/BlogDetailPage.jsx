import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Edit3, Check, X, Send, History } from 'lucide-react';
import { blogService } from '../../services/blogService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';
import { ApprovalBadge } from '../../components/common/ApprovalBadge';

const NEXT_STATUS_MAP = {
  idea:            ['draft', 'archived'],
  draft:           ['seo_review', 'archived'],
  seo_review:      ['internal_review', 'draft', 'rejected'],
  internal_review: ['approved', 'draft', 'rejected'],
  approved:        ['scheduled', 'published', 'draft'],
  scheduled:       ['published', 'approved'],
  published:       ['archived'],
  rejected:        ['draft', 'archived'],
  archived:        ['draft'],
};

export function BlogDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [post, setPost]       = useState(null);
  const [versions, setVersions] = useState([]);
  const [error, setError]     = useState(null);
  const [busy, setBusy]       = useState(false);

  const load = useCallback(() => {
    blogService.get(id).then(setPost).catch((e) => setError(e.message));
    blogService.versions(id).then((d) => setVersions(d.items || d)).catch(() => {});
  }, [id]);
  useEffect(() => { load(); }, [load]);

  const transition = async (status) => {
    setBusy(true);
    try { await blogService.transition(id, status); load(); }
    catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };
  const approve = async () => { setBusy(true); try { await blogService.approve(id); load(); } catch (e) { setError(e.message); } finally { setBusy(false); } };
  const reject  = async () => {
    const note = window.prompt('Reject reason (optional):') || '';
    setBusy(true); try { await blogService.reject(id, note); load(); } catch (e) { setError(e.message); } finally { setBusy(false); }
  };
  const publish = async () => { setBusy(true); try { await blogService.publish(id); load(); } catch (e) { setError(e.message); } finally { setBusy(false); } };

  if (error) return <Alert variant="danger">{error}</Alert>;
  if (!post) return <Loader />;

  const nextOpts = NEXT_STATUS_MAP[post.status] || [];

  return (
    <div>
      <div className="page-header">
        <div>
          <button className="btn btn-secondary btn-sm" onClick={() => navigate('/blog')}><ArrowLeft size={12}/> All posts</button>
          <h1 style={{ marginTop: 6 }}>{post.title || '(untitled)'}</h1>
          <div className="text-xs text-muted">{post.slug}</div>
        </div>
        <div className="flex gap-2">
          <button className="btn btn-secondary" onClick={() => navigate(`/blog/${post.id}/edit`)}><Edit3 size={13}/> Edit</button>
        </div>
      </div>

      <div className="flex gap-2 mb-4 flex-wrap">
        <StatusBadge status={post.status} />
        <ApprovalBadge status={post.approval_status} />
        <StatusBadge status={post.publishing_status || 'none'} />
        {post.bot_generated && <span className="badge badge-info">Bot generated</span>}
      </div>

      <div className="grid grid-2" style={{ alignItems: 'start' }}>
        <Card title="Content" padding={false}>
          <div style={{ padding: '1rem', whiteSpace: 'pre-wrap', fontSize: '0.9rem' }}>{post.content || '(empty)'}</div>
        </Card>

        <div className="flex flex-col gap-3">
          <Card title="Workflow">
            <div className="flex gap-2 flex-wrap">
              {nextOpts.map((s) => (
                <button key={s} className="btn btn-secondary btn-sm" disabled={busy} onClick={() => transition(s)}>
                  → {s.replace(/_/g,' ')}
                </button>
              ))}
            </div>
            <div className="flex gap-2 mt-3 flex-wrap">
              {post.approval_status !== 'approved' && (
                <button className="btn btn-primary btn-sm" disabled={busy} onClick={approve}><Check size={13}/> Approve</button>
              )}
              {post.approval_status !== 'rejected' && (
                <button className="btn btn-danger btn-sm" disabled={busy} onClick={reject}><X size={13}/> Reject</button>
              )}
              {post.status === 'approved' || post.status === 'scheduled' ? (
                <button className="btn btn-primary btn-sm" disabled={busy} onClick={publish}><Send size={13}/> Publish to AICOUNTLY.com</button>
              ) : null}
            </div>
          </Card>

          <Card title="SEO & Metadata">
            <div className="grid grid-2">
              <div><div className="text-xs text-muted">SEO title</div><div className="text-sm">{post.seo_title || '—'}</div></div>
              <div><div className="text-xs text-muted">Focus keyword</div><div className="text-sm">{post.focus_keyword || '—'}</div></div>
              <div style={{ gridColumn: 'span 2' }}><div className="text-xs text-muted">SEO description</div><div className="text-sm">{post.seo_description || '—'}</div></div>
              <div><div className="text-xs text-muted">Canonical URL</div><div className="text-sm">{post.canonical_url || '—'}</div></div>
              <div><div className="text-xs text-muted">Author</div><div className="text-sm">{post.author || '—'}</div></div>
              <div><div className="text-xs text-muted">Category</div><div className="text-sm">{post.category || '—'}</div></div>
              <div><div className="text-xs text-muted">Tags</div><div className="text-sm">{Array.isArray(post.tags) ? post.tags.join(', ') : (post.tags || '—')}</div></div>
              <div><div className="text-xs text-muted">Scheduled at</div><div className="text-sm">{post.scheduled_at || '—'}</div></div>
              <div><div className="text-xs text-muted">Published at</div><div className="text-sm">{post.published_at || '—'}</div></div>
            </div>
          </Card>

          <Card title={<span className="flex items-center gap-2"><History size={14}/> Version history</span>}>
            {versions.length === 0 && <div className="text-sm text-muted">No versions recorded.</div>}
            {versions.map((v) => (
              <div key={v.id} className="flex items-center justify-between" style={{ padding: '0.35rem 0', borderBottom: '1px solid var(--color-border)' }}>
                <div>
                  <div className="text-sm font-semibold">v{v.version}</div>
                  <div className="text-xs text-muted">{v.created_at ? new Date(v.created_at).toLocaleString() : ''}</div>
                </div>
                <div className="text-xs text-muted">{v.change_reason || ''}</div>
              </div>
            ))}
          </Card>
        </div>
      </div>
    </div>
  );
}
