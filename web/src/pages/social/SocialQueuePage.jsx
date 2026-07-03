import { useCallback, useEffect, useState } from 'react';
import { socialService } from '../../services/socialService';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { SocialQueueItem } from '../../components/social/SocialQueueItem';
import { EmptyState } from '../../components/common/EmptyState';
import { Card } from '../../components/common/Card';

export function SocialQueuePage() {
  const [posts, setPosts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    socialService.queue()
      .then((d) => setPosts(d.items || d))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);
  useEffect(load, [load]);

  const markPosted = async (post) => {
    const externalId = window.prompt('External post ID (optional):') || '';
    try {
      await socialService.markPosted(post.id, externalId);
      load();
    } catch (e) { setError(e.message); }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Social posting queue</h1>
          <p className="text-sm text-muted">Approved / scheduled / manual-queue posts awaiting delivery.</p>
        </div>
      </div>

      <Card title={`${posts.length} item(s) in queue`}>
        {error && <Alert variant="danger">{error}</Alert>}
        {loading ? <Loader /> : (
          posts.length === 0 ? <EmptyState message="Queue is empty." /> : (
            <div className="grid grid-2">
              {posts.map((p) => <SocialQueueItem key={p.id} post={p} onMarkPosted={markPosted} />)}
            </div>
          )
        )}
      </Card>
    </div>
  );
}
