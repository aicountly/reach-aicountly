import { useCallback, useEffect, useState } from 'react';
import { socialInboxService } from '../../services/socialInboxService';
import { usePermission } from '../../hooks/usePermission';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { Pagination } from '../../components/common/Pagination';
import { formatDateTime } from '../../utils/formatDate';

const STATUSES = ['', 'new', 'retry', 'deferred', 'replied', 'ticket_created', 'lead_created', 'ignored', 'hidden', 'failed'];
const CLASSES = ['', 'question', 'praise', 'complaint', 'sales_lead', 'spam_abuse', 'sensitive', 'other'];
const NETWORKS = ['', 'linkedin', 'x', 'facebook', 'instagram', 'youtube', 'tiktok'];
const LIMIT = 25;

const label = (s) => (s ? String(s).replace(/_/g, ' ') : '—');

const STATUS_TONE = {
  replied: 'success', ticket_created: 'success', lead_created: 'success', hidden: 'secondary',
  ignored: 'secondary', deferred: 'warning', retry: 'warning', new: 'info', failed: 'danger',
};
const CLASS_TONE = {
  complaint: 'danger', sales_lead: 'success', question: 'info', praise: 'success',
  spam_abuse: 'secondary', sensitive: 'warning', other: 'secondary',
};

function Badge({ tone, children, testId }) {
  return <span className={`badge badge-${tone || 'secondary'}`} data-testid={testId}>{children}</span>;
}

function Tile({ title, value, testId }) {
  return (
    <div className="stat-tile" data-testid={testId}>
      <div className="stat-tile__label">{title}</div>
      <div className="stat-tile__value">{value ?? 0}</div>
    </div>
  );
}

/**
 * Social → Inbox. Every comment and direct message the social inbox bot read
 * from social-api.ai, what Gemini classified it as, and what happened: the
 * reply (only shown as posted once social-api.ai accepted it), the Flow ticket
 * for complaints, the Reach lead for sales enquiries, or why it was left alone.
 * Informational: the bot needs nobody to act here; Retry exists for failures.
 */
export function SocialInboxPage() {
  const { has } = usePermission();
  const canRetry = has('social.dispatch');
  const [summary, setSummary] = useState(null);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [classification, setClassification] = useState('');
  const [network, setNetwork] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [expanded, setExpanded] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    Promise.all([
      socialInboxService.summary(),
      socialInboxService.list({ status, classification, network, search, page, limit: LIMIT }),
    ])
      .then(([s, list]) => {
        setSummary(s);
        setRows(list.items || []);
        setTotal(list.total ?? 0);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [status, classification, network, search, page]);
  useEffect(() => { load(); }, [load]);

  const retry = async (row) => {
    if (row.reply_status === 'failed' && !window.confirm('The earlier reply may have reached the network (its outcome was not confirmed). Try again anyway?')) return;
    try {
      const r = await socialInboxService.retry(row.id);
      setNotice(`Item #${row.id}: ${label(r.status)}.`);
      load();
    } catch (e) {
      setError(e.message);
    }
  };

  const cfg = summary?.config;
  const day = summary?.last_24h;
  const lastRun = summary?.last_run;

  const columns = [
    { key: 'received_at', label: 'Received', render: (r) => (
      <div>
        <div>{formatDateTime(r.received_at)}</div>
        <div className="text-xs text-muted">{label(r.network)} · {label(r.kind)}</div>
      </div>
    )},
    { key: 'author', label: 'From', render: (r) => (
      <div>
        <div className="font-semibold">{r.author_name || (r.author_handle ? `@${r.author_handle}` : '—')}</div>
        {r.author_handle && r.author_name && <div className="text-xs text-muted">@{r.author_handle}</div>}
      </div>
    )},
    { key: 'text', label: 'Message', render: (r) => (
      <div style={{ maxWidth: 360 }}>
        <div className="text-sm">{r.text?.length > 160 ? `${r.text.slice(0, 160)}…` : r.text}</div>
        {r.post_url && <a className="text-xs" href={r.post_url} target="_blank" rel="noopener noreferrer">View post</a>}
      </div>
    )},
    { key: 'classification', label: 'Classified', render: (r) => (r.classification ? (
      <div>
        <Badge tone={CLASS_TONE[r.classification]} testId={`class-${r.id}`}>{label(r.classification)}</Badge>
        <div className="text-xs text-muted">{r.confidence != null ? `${r.confidence}%` : ''}{r.severity === 'high' ? ' · high severity' : ''}{r.personal_data ? ' · personal data' : ''}</div>
      </div>
    ) : <span className="text-muted">pending</span>) },
    { key: 'status', label: 'Outcome', render: (r) => (
      <div style={{ maxWidth: 320 }}>
        <Badge tone={STATUS_TONE[r.status]} testId={`status-${r.id}`}>{label(r.status)}</Badge>
        {r.ticket_number && (
          <div className="text-xs mt-1">
            Flow {r.ticket_url ? <a href={r.ticket_url} target="_blank" rel="noopener noreferrer">{r.ticket_number}</a> : r.ticket_number}
          </div>
        )}
        {r.lead_id && <div className="text-xs mt-1">Lead #{r.lead_id}</div>}
        {r.status_reason && <div className="text-xs text-muted mt-1">{r.status_reason}</div>}
      </div>
    )},
    { key: 'reply', label: 'Reply', render: (r) => (
      <div style={{ maxWidth: 320 }}>
        <div className="text-xs"><strong>{label(r.reply_status)}</strong>{r.replied_at ? ` · ${formatDateTime(r.replied_at)}` : ''}</div>
        {r.reply_text && <div className="text-sm">{r.reply_text}</div>}
        {r.reply_status !== 'sent' && r.reply_reason && <div className="text-xs text-muted">{r.reply_reason}</div>}
        {canRetry && (r.status === 'failed' || r.reply_status === 'failed') && (
          <button type="button" className="btn btn-sm mt-1" onClick={(e) => { e.stopPropagation(); retry(r); }}>Retry</button>
        )}
      </div>
    )},
  ];

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Social inbox</h1>
          <p className="text-sm text-muted">
            Comments and messages on AICOUNTLY&apos;s social accounts, read every 10 minutes through social-api.ai. The bot answers
            questions with a vetted reply, lodges complaints in Flow and acknowledges them with the ticket number, records sales
            enquiries as leads, and never answers spam. A reply shows as posted only once social-api.ai accepted it.
          </p>
        </div>
      </div>

      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      {cfg && (!cfg.enabled || !cfg.social_api_key || cfg.flow !== 'configured' || !cfg.auto_reply) && (
        <Alert variant="warning">
          <div data-testid="inbox-config">
            {!cfg.enabled && <div>The social inbox bot is off (Settings → AI &amp; Automation → Social inbox).</div>}
            {!cfg.social_api_key && <div>The social-api.ai API key is not set — nothing can be read.</div>}
            {cfg.enabled && !cfg.auto_reply && <div>Auto-reply is off: messages are classified and lodged, but nothing is posted.</div>}
            {cfg.flow !== 'configured' && <div>Flow: {cfg.flow} Complaints wait until it is set.</div>}
          </div>
        </Alert>
      )}

      {day && (
        <Card title="Last 24 hours">
          <div className="grid grid-4">
            <Tile title="Read" value={day.received} testId="tile-read" />
            <Tile title="Replies posted" value={day.replies_sent} testId="tile-replies" />
            <Tile title="Flow tickets" value={day.tickets} testId="tile-tickets" />
            <Tile title="Leads" value={day.leads} testId="tile-leads" />
          </div>
          <div className="flex gap-2 mt-3" style={{ flexWrap: 'wrap' }}>
            {Object.entries(day.by_classification || {}).map(([k, n]) => (
              <span key={k} className="text-xs">{label(k)}: <strong>{n}</strong></span>
            ))}
            {day.reply_failures > 0 && <span className="text-xs">reply failures: <strong>{day.reply_failures}</strong></span>}
            {day.waiting_for_flow > 0 && <span className="text-xs">complaints waiting for Flow: <strong>{day.waiting_for_flow}</strong></span>}
          </div>
          {lastRun && (
            <div className="text-xs text-muted mt-2" data-testid="last-run">
              Last run: {label(lastRun.status)}{lastRun.completed_at ? ` at ${formatDateTime(lastRun.completed_at)}` : ''}
              {lastRun.result?.poll?.errors && Object.keys(lastRun.result.poll.errors).length > 0
                && ` — ${Object.entries(lastRun.result.poll.errors).map(([n, m]) => `${n}: ${m}`).join(' · ')}`}
              {lastRun.error_message ? ` — ${lastRun.error_message}` : ''}
            </div>
          )}
        </Card>
      )}

      <div className="mt-3">
        <FilterBar>
          <select aria-label="Outcome" value={status} onChange={(e) => { setPage(1); setStatus(e.target.value); }}>
            {STATUSES.map((s) => <option key={s} value={s}>{s ? label(s) : 'All outcomes'}</option>)}
          </select>
          <select aria-label="Classification" value={classification} onChange={(e) => { setPage(1); setClassification(e.target.value); }}>
            {CLASSES.map((s) => <option key={s} value={s}>{s ? label(s) : 'All types'}</option>)}
          </select>
          <select aria-label="Network" value={network} onChange={(e) => { setPage(1); setNetwork(e.target.value); }}>
            {NETWORKS.map((s) => <option key={s} value={s}>{s ? label(s) : 'All networks'}</option>)}
          </select>
          <input aria-label="Search" placeholder="Text, @handle or TCK-…" value={search} onChange={(e) => { setPage(1); setSearch(e.target.value); }} />
        </FilterBar>
      </div>

      {loading && !summary ? <Loader /> : (
        <div className="mt-3">
          <Card padding={false} title={`Messages (${total})`}>
            <DataTable
              columns={columns}
              rows={rows}
              emptyMessage="Nothing read yet for this filter."
              onRowClick={(r) => setExpanded(expanded === r.id ? null : r.id)}
              expandedRowId={expanded}
              renderExpanded={(r) => (
                <div className="p-3 text-sm" data-testid={`detail-${r.id}`}>
                  <div><strong>Full message:</strong> {r.text}</div>
                  {r.summary && <div className="mt-1"><strong>Summary:</strong> {r.summary}</div>}
                  {r.severity_reason && <div className="mt-1"><strong>Severity:</strong> {r.severity} — {r.severity_reason}</div>}
                  {r.vetting_decision && <div className="mt-1"><strong>Vetting:</strong> {r.vetting_decision}{r.vetting_reasons?.length ? ` — ${r.vetting_reasons.join(' ')}` : ''}</div>}
                  {r.ticket_error && <div className="mt-1"><strong>Flow:</strong> {r.ticket_error}</div>}
                  {r.owner_alerted_at && <div className="mt-1">Owner alerted {formatDateTime(r.owner_alerted_at)}.</div>}
                </div>
              )}
            />
          </Card>
          <Pagination page={page} total={total} limit={LIMIT} onPage={setPage} />
        </div>
      )}
    </div>
  );
}

export default SocialInboxPage;
