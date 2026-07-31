import { useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';

export function EmergencyUnpublishPage() {
  const [slug, setSlug] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState(null);
  const [error, setError] = useState(null);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setMessage(null);
    setError(null);
    try {
      const res = await fetch('/api/v1/blog-command-centre/publishing/emergency-unpublish', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${localStorage.getItem('reach_token') || ''}`,
        },
        body: JSON.stringify({ slug, reason }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.ok === false) {
        throw new Error(json.error || `HTTP ${res.status}`);
      }
      setMessage('Emergency unpublish request submitted.');
      setSlug('');
      setReason('');
    } catch (err) {
      setError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div>
      <div className="page-header">
        <div>
          <h2><AlertTriangle size={18} style={{ verticalAlign: 'text-bottom' }} /> Emergency Unpublish</h2>
          <p className="text-sm text-muted">
            Immediately withdraw a published blog from the public site. Requires elevated permissions.
          </p>
        </div>
      </div>

      {message && <Alert variant="success">{message}</Alert>}
      {error && (
        <Alert variant="info">
          Emergency unpublish API is not available yet ({error}). Contact an administrator.
        </Alert>
      )}

      <Card title="Unpublish request">
        <form onSubmit={handleSubmit} className="flex flex-col gap-3">
          <div>
            <label className="text-xs text-secondary">Blog slug</label>
            <input required value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="my-blog-post" />
          </div>
          <div>
            <label className="text-xs text-secondary">Reason</label>
            <textarea required rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <button type="submit" className="btn btn-danger" disabled={submitting}>
            {submitting ? 'Submitting…' : 'Submit emergency unpublish'}
          </button>
        </form>
      </Card>
    </div>
  );
}
