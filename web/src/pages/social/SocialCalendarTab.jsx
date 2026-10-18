import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ExternalLink, RefreshCw, RotateCcw } from 'lucide-react';
import api from '../../services/api';
import { usePermission } from '../../hooks/usePermission';
import { formatDate, formatDateTime } from '../../utils/formatDate';
import {
  CALENDAR_FILTERS, NETWORKS, canRetry, groupByDay, isPipelineVideo, networkMeta, postStatusMeta, postTitle,
  postWhen, sortPosts,
} from './socialAdsModel';

const PER_STATUS = 100;

function itemsOf(r) {
  if (Array.isArray(r)) return { items: r, total: r.length };
  const items = r?.items ?? r?.data ?? [];
  return { items: Array.isArray(items) ? items : [], total: Number(r?.total ?? items.length ?? 0) };
}

function PostRow({ post, canDispatch, confirming, busy, onRetry, onAskRetry, onCancelRetry }) {
  const net = networkMeta(post.channel);
  const status = postStatusMeta(post.status);
  const when = postWhen(post);
  return (
    <li className="sa-post" data-testid={`post-${post.id}`}>
      <span className="sa-post__net" title={net.label}>
        <net.icon size={16} aria-hidden="true" />
        <span className="sa-post__net-label">{net.label}</span>
      </span>
      <div className="sa-post__main">
        <p className="sa-post__title">{postTitle(post)}</p>
        <p className="sa-post__meta">
          <span className={`badge ${status.badge}`}>{status.label}</span>
          {when && <time dateTime={String(when)}>{formatDateTime(when)}</time>}
          {isPipelineVideo(post) && <span className="text-muted">video pipeline</span>}
        </p>
        {post.provider_error_code && <p className="sa-post__error"><code>{post.provider_error_code}</code></p>}
        {post.error_message && (post.status === 'failed' || post.status === 'publishing' || post.status === 'manual_queue') && (
          <p className="sa-post__note">{post.error_message}</p>
        )}
      </div>
      <div className="sa-post__actions">
        {post.remote_url && (
          <a className="btn btn-secondary btn-sm" href={post.remote_url} target="_blank" rel="noreferrer">
            <ExternalLink size={13} aria-hidden="true" /> View post
          </a>
        )}
        {post.status === 'failed' && isPipelineVideo(post) && (
          <Link className="btn btn-secondary btn-sm" to="/video">Retry in Video pipeline</Link>
        )}
        {canDispatch && canRetry(post) && (confirming ? (
          <>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => onRetry(post)} disabled={busy}>
              {busy ? 'Sending…' : 'Confirm retry'}
            </button>
            <button type="button" className="btn btn-secondary btn-sm" onClick={onCancelRetry} disabled={busy}>Cancel</button>
          </>
        ) : (
          <button type="button" className="btn btn-secondary btn-sm" onClick={() => onAskRetry(post)}>
            <RotateCcw size={13} aria-hidden="true" /> Retry
          </button>
        ))}
      </div>
    </li>
  );
}

/**
 * Calendar & queue — every social post Reach holds (incl. YouTube videos and
 * Shorts from the video pipeline), by day, with its real status: `publishing`
 * means social-api.ai accepted it and is delivering; only `posted` is posted.
 */
export default function SocialCalendarTab() {
  const { has } = usePermission();
  const canDispatch = has('distribution.dispatch');
  const [filterId, setFilterId] = useState('upcoming');
  const [network, setNetwork] = useState('');
  const [posts, setPosts] = useState([]);
  const [truncated, setTruncated] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [confirmId, setConfirmId] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [notice, setNotice] = useState(null);

  const filter = CALENDAR_FILTERS.find((f) => f.id === filterId) ?? CALENDAR_FILTERS[0];

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    Promise.all(filter.statuses.map((status) => api.get('v1/social/posts', {
      status, channel: network || undefined, page: 1, limit: PER_STATUS,
    }).then(itemsOf)))
      .then((lists) => {
        setPosts(sortPosts(lists.flatMap((l) => l.items), filter.order));
        setTruncated(lists.some((l) => l.total > l.items.length));
      })
      .catch((e) => { setError(e?.message || 'Could not load social posts.'); setPosts([]); })
      .finally(() => setLoading(false));
  }, [filter, network]);

  useEffect(() => { load(); }, [load]);

  const retry = async (post) => {
    setBusyId(post.id);
    setNotice(null);
    try {
      const r = await api.post(`v1/distribution/social/dispatch/${post.id}`);
      const st = r?.status ?? r?.post?.status;
      setNotice({ ok: true, text: `Sent again to social-api.ai${st ? ` (status: ${st})` : ''}. It shows Posted only once social-api.ai confirms it.` });
    } catch (e) {
      setNotice({ ok: false, text: e?.message || 'Retry failed.' });
    } finally {
      setBusyId(null);
      setConfirmId(null);
      load();
    }
  };

  const groups = groupByDay(posts);

  return (
    <section aria-labelledby="sa-cal-title">
      <div className="sa-toolbar">
        <div className="sa-toolbar__text">
          <h2 id="sa-cal-title" className="sa-h2">Calendar &amp; queue</h2>
          <p className="text-sm text-muted">
            <em>Publishing</em> = accepted by social-api.ai and being delivered (checked every 5 minutes; failed after 30 minutes without confirmation).
          </p>
        </div>
        <div className="sa-toolbar__actions">
          <label className="sa-select">
            <span className="as-sr-only">Network</span>
            <select className="form-select form-select--sm" value={network} onChange={(e) => setNetwork(e.target.value)} aria-label="Network">
              <option value="">All networks</option>
              {NETWORKS.map((n) => <option key={n.key} value={n.key}>{n.label}</option>)}
            </select>
          </label>
          <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={loading}>
            <RefreshCw size={14} aria-hidden="true" /> Refresh
          </button>
        </div>
      </div>

      <div className="sa-chips" role="group" aria-label="Show posts">
        {CALENDAR_FILTERS.map((f) => (
          <button
            key={f.id}
            type="button"
            className={`sa-chip${f.id === filterId ? ' sa-chip--active' : ''}`}
            aria-pressed={f.id === filterId}
            onClick={() => { setFilterId(f.id); setConfirmId(null); }}
          >
            {f.label}
          </button>
        ))}
      </div>

      {notice && <div className={`alert ${notice.ok ? 'alert-success' : 'alert-danger'}`} role="status">{notice.text}</div>}
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {loading && <p className="muted">Loading posts…</p>}

      {!loading && !error && posts.length === 0 && (
        <div className="as-empty" data-testid="calendar-empty">
          <p><strong>No {filter.label.toLowerCase()} posts{network ? ` on ${networkMeta(network).label}` : ''}.</strong></p>
          <p className="text-sm">
            {filter.id === 'upcoming'
              ? 'Routines schedule posts and the video pipeline adds YouTube videos and Shorts; they appear here once queued.'
              : 'Nothing in this state right now.'}
          </p>
        </div>
      )}

      {!loading && posts.length > 0 && (
        <div className="sa-days">
          {groups.map((g) => (
            <section key={g.day} className="sa-day" aria-label={g.day === 'undated' ? 'No date' : formatDate(g.day)}>
              <h3 className="sa-day__title">{g.day === 'undated' ? 'No date' : formatDate(g.day)}</h3>
              <ul className="sa-posts">
                {g.posts.map((p) => (
                  <PostRow
                    key={p.id}
                    post={p}
                    canDispatch={canDispatch}
                    confirming={confirmId === p.id}
                    busy={busyId === p.id}
                    onAskRetry={(post) => setConfirmId(post.id)}
                    onCancelRetry={() => setConfirmId(null)}
                    onRetry={retry}
                  />
                ))}
              </ul>
            </section>
          ))}
          {truncated && <p className="text-sm text-muted">Showing the first {PER_STATUS} posts per status.</p>}
        </div>
      )}
    </section>
  );
}
