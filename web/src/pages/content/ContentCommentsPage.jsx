import { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { contentService } from '../../services/contentService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { CommentThread } from '../../components/content/CommentThread';
import { usePermission } from '../../hooks/usePermission';

export function ContentCommentsPage() {
  const { id } = useParams();
  const { has } = usePermission();
  const canModerate = has('content_comment.delete') || has('content_comment.resolve');
  const [comments, setComments]   = useState([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState(null);
  const [showResolved, setShowResolved] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await contentService.listComments(id, showResolved);
      setComments(data.comments || []);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [id, showResolved]);

  useEffect(load, [load]);

  if (loading) return <Loader />;

  return (
    <div>
      <div className="page-header">
        <h1>Comments</h1>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 }}>
          <input type="checkbox" checked={showResolved} onChange={(e) => setShowResolved(e.target.checked)} />
          Show resolved
        </label>
      </div>
      {error && <Alert variant="danger">{error}</Alert>}
      <Card>
        <CommentThread
          contentItemId={id}
          comments={comments}
          onRefresh={load}
          canModerate={canModerate}
        />
      </Card>
    </div>
  );
}
