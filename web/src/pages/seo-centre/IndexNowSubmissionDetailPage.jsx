import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { seoService } from '../../services/seoService';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { DataTable } from '../../components/common/DataTable';
import { Breadcrumbs } from '../../components/common/Breadcrumbs';
import { formatDateTime } from '../../utils/formatDate';
import { drillHref } from '../../components/drill';
import { contentHref } from './recordLinks';

/** One IndexNow submission: the URL, where it went, and every attempt. */
export function IndexNowSubmissionDetailPage() {
  const { id } = useParams();
  const [row, setRow] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    seoService.getIndexNowSubmission(id)
      .then((res) => { setRow(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load this submission.'));
  }, [id]);

  if (error) return <Alert variant="error">{error}</Alert>;
  if (!row) return <Loader label="Loading submission…" />;

  const content = row.content;
  const openIn = content ? contentHref({ content_item_id: content.content_item_id, content_identity_id: content.id }) : null;
  const facts = [
    ['Status', <Link key="s" to={drillHref('/seo-centre/indexnow/submissions', { status: row.status })}>{row.status}</Link>],
    ['Attempts', `${row.attempt_count} of ${row.max_attempts}`],
    ['Endpoint', row.provider_endpoint],
    ['Triggered by', row.triggered_by],
    ['Created', formatDateTime(row.created_at)],
    ['Submitted', row.submitted_at ? formatDateTime(row.submitted_at) : '—'],
    ['Next retry', row.next_retry_at ? formatDateTime(row.next_retry_at) : '—'],
    ['Completed', row.completed_at ? formatDateTime(row.completed_at) : '—'],
  ];

  return (
    <div>
      <Breadcrumbs items={[
        { label: 'IndexNow', to: '/seo-centre/indexnow' },
        { label: 'Submissions', to: '/seo-centre/indexnow/submissions' },
        { label: `#${row.id}` },
      ]} />
      <Card title="Submission">
        <p style={{ margin: '0 0 0.75rem', wordBreak: 'break-all' }}>
          <a href={row.url} target="_blank" rel="noreferrer">{row.url} ↗</a>
          {' · '}
          <Link to={drillHref('/seo-centre/search', { page: row.url, by: 'query' })} className="text-sm">Search queries for this page ›</Link>
          {openIn && <>{' · '}<Link to={openIn} className="text-sm">Open content ›</Link></>}
        </p>
        <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '0.3rem 1rem', margin: 0 }} className="text-sm">
          {facts.map(([k, v]) => (
            <div key={k} style={{ display: 'contents' }}><dt className="text-muted">{k}</dt><dd style={{ margin: 0 }}>{v}</dd></div>
          ))}
        </dl>
      </Card>

      <Card title={`Attempts (${(row.attempts || []).length})`}>
        <DataTable
          rowKey={(a) => a.id}
          columns={[
            { key: 'attempt_number', label: '#' },
            { key: 'attempted_at', label: 'When', render: (a) => formatDateTime(a.attempted_at) },
            { key: 'succeeded', label: 'Result', render: (a) => (a.succeeded === true || a.succeeded === 't'
              ? <span className="badge badge-success">ok</span> : <span className="badge badge-danger">failed</span>) },
            { key: 'http_status', label: 'HTTP', render: (a) => a.http_status ?? '—' },
            { key: 'latency_ms', label: 'Latency', render: (a) => (a.latency_ms != null ? `${a.latency_ms} ms` : '—') },
            { key: 'error_message', label: 'Error', render: (a) => a.error_message || '—' },
            { key: 'provider_response', label: 'Provider response', render: (a) => (a.provider_response
              ? <code className="text-xs" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>{String(a.provider_response).slice(0, 400)}</code> : '—') },
          ]}
          rows={row.attempts || []}
          emptyMessage="Not attempted yet."
        />
      </Card>
    </div>
  );
}

export default IndexNowSubmissionDetailPage;
