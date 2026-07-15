import { useState } from 'react';
import { Zap, Send, RefreshCw, CheckCircle, XCircle, Clock } from 'lucide-react';

export default function IndexNowOperationsPage() {
  const [url, setUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null);

  const submissions = [
    { id: 1, url: 'https://example.com/blog/post-1', status: 'submitted', submitted_at: '2026-07-15T08:00:00Z', attempt_count: 1 },
    { id: 2, url: 'https://example.com/blog/post-2', status: 'retrying', attempt_count: 1, next_retry_at: '2026-07-15T08:10:00Z' },
    { id: 3, url: 'https://example.com/blog/post-3', status: 'failed', attempt_count: 3 },
  ];

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!url) return;
    setSubmitting(true);
    setTimeout(() => {
      setResult({ success: true, url, status: 'submitted' });
      setSubmitting(false);
      setUrl('');
    }, 800);
  };

  const statusBadge = (status) => {
    const map = {
      submitted: 'bg-green-100 text-green-700',
      retrying: 'bg-yellow-100 text-yellow-700',
      failed: 'bg-red-100 text-red-700',
      pending: 'bg-blue-100 text-blue-700',
    };
    return (
      <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${map[status] ?? 'bg-gray-100 text-gray-700'}`}>
        {status}
      </span>
    );
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center gap-3">
        <Zap className="h-7 w-7 text-yellow-500" />
        <div>
          <h1 className="text-2xl font-bold text-gray-900">IndexNow Operations</h1>
          <p className="text-sm text-gray-500">Submit URLs to search engine indexes via IndexNow protocol</p>
        </div>
      </div>

      {/* Submit URL form */}
      <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
        <h2 className="text-base font-semibold text-gray-800 mb-4">Submit URL</h2>
        <form onSubmit={handleSubmit} className="flex gap-3">
          <input
            type="url"
            value={url}
            onChange={e => setUrl(e.target.value)}
            placeholder="https://example.com/blog/my-post"
            className="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400"
            required
          />
          <button
            type="submit"
            disabled={submitting || !url}
            className="flex items-center gap-2 px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 disabled:opacity-50 text-sm font-medium"
          >
            <Send className="h-4 w-4" />
            {submitting ? 'Submitting…' : 'Submit'}
          </button>
        </form>
        {result && (
          <div className="mt-3 flex items-center gap-2 text-green-700 text-sm">
            <CheckCircle className="h-4 w-4" />
            URL submitted: {result.url}
          </div>
        )}
      </div>

      {/* SSRF note */}
      <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
        <strong>Security:</strong> Submissions are only accepted to allowlisted endpoints (api.indexnow.org, www.bing.com, search.google.com).
      </div>

      {/* Recent submissions */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div className="flex items-center justify-between p-4 border-b border-gray-100">
          <h2 className="text-base font-semibold text-gray-800">Recent Submissions</h2>
          <button className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700">
            <RefreshCw className="h-3 w-3" /> Retry pending
          </button>
        </div>
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
            <tr>
              <th className="px-4 py-3 text-left">URL</th>
              <th className="px-4 py-3 text-left">Status</th>
              <th className="px-4 py-3 text-left">Attempts</th>
              <th className="px-4 py-3 text-left">Submitted</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {submissions.map(s => (
              <tr key={s.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-mono text-xs text-gray-700 truncate max-w-xs">{s.url}</td>
                <td className="px-4 py-3">{statusBadge(s.status)}</td>
                <td className="px-4 py-3 text-gray-500">{s.attempt_count}</td>
                <td className="px-4 py-3 text-gray-400 text-xs">
                  {s.submitted_at ? new Date(s.submitted_at).toLocaleString() : (
                    <span className="flex items-center gap-1"><Clock className="h-3 w-3" /> {s.next_retry_at ? `retry at ${new Date(s.next_retry_at).toLocaleTimeString()}` : '—'}</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
