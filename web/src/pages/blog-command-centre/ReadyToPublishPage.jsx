import { useEffect, useState } from 'react';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { Card } from '../../components/common/Card';

export function ReadyToPublishPage() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetch('/api/v1/publishing/deployments?status=ready&content_type=blog', {
      headers: {
        Authorization: `Bearer ${localStorage.getItem('reach_token') || ''}`,
      },
    })
      .then(async (res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        return json.data ?? json;
      })
      .then((data) => setItems(data.items ?? data ?? []))
      .catch((err) => setError(err.message))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <Loader label="Loading ready items…" />;

  if (error) {
    return (
      <div>
        <h2>Ready to Publish</h2>
        <Alert variant="info">
          Publishing readiness API is not available yet ({error}). Use Publishing &gt; Deployments in the meantime.
        </Alert>
      </div>
    );
  }

  return (
    <div>
      <h2>Ready to Publish</h2>
      <p className="text-sm text-muted">Blog content approved and queued for publication.</p>
      {items.length === 0 ? (
        <div className="empty-state"><p>No blog items are ready to publish.</p></div>
      ) : (
        <Card title={`${items.length} item(s)`}>
          <ul>
            {items.map((item) => (
              <li key={item.id ?? item.deployment_id}>{item.title ?? item.slug ?? item.id}</li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  );
}
