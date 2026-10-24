import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { socialInboxService } from '../../services/socialInboxService';
import { DrillLink, drillHref, useDrillFilters } from '../../components/drill';
import { ROUTES } from '../../constants/routes';
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

/**
 * URL filters. The first four have controls; the rest come from the "Last 24
 * hours" tiles and match SocialInboxService::stats() predicates (has=ticket /
 * has=lead, since_hours on fetched_at, replied_since_hours on replied_at,
 * updated_since_hours on updated_at for reply failures, waiting=flow for
 * complaints with no ticket still deferred / retrying), so a tile's number and
 * the list it opens agree.
 */
const TILE_KEYS = ['reply_status', 'has', 'waiting', 'since_hours', 'replied_since_hours', 'updated_since_hours'];
const FILTER_KEYS = ['status', 'classification', 'network', 'search', ...TILE_KEYS];
const NONE = Object.fromEntries(FILTER_KEYS.map((k) => [k, '']));

/** Lead rows the social inbox bot creates (SocialInboxService). */
const SOCIAL_LEAD_SOURCE = 'social_inbox';

const CHIP_LABELS = {
  reply_status: (v) => `Reply ${label(v)}`,
  has: (v) => (v === 'ticket' ? 'Has a Flow ticket' : v === 'lead' ? 'Created a lead' : `Has ${v}`),
  since_hours: (v) => `Read in the last ${v} h`,
  replied_since_hours: (v) => `Replied in the last ${v} h`,
  updated_since_hours: (v) => `Changed in the last ${v} h`,
  waiting: (v) => (v === 'flow' ? 'Complaints waiting for Flow' : `Waiting: ${v}`),
};

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

/** A day tile; with onDrill (and a non-zero value) it narrows the list below. */
function Tile({ title, value, testId, onDrill, active }) {
  const body = (
    <>
      <div className="stat-tile__label">{title}</div>
      <div className="stat-tile__value">{value ?? 0}</div>
    </>
  );
  if (!onDrill || !value) return <div className="stat-tile" data-testid={testId}>{body}</div>;
  return (
    <button type="button" className={`stat-tile${active ? ' is-active' : ''}`} data-testid={testId}
            onClick={onDrill} aria-pressed={!!active} title="Show these messages">
      {body}
    </button>
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
  // Filters live in the URL so a tile drill is shareable and Back undoes it.
  const drill = useDrillFilters(FILTER_KEYS);
  const f = drill.filters;
  const { status, classification, network, search } = f;
  // Typed / selected filters replace the entry; tile drills push one.
  const setFilter = (key, value) => { setPage(1); drill.set(key, value); };
  const narrow = (patch) => { setPage(1); drill.drill({ ...NONE, ...patch }); };
  const isOnly = (patch) => FILTER_KEYS.every((k) => (patch[k] ?? '') === f[k]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [expanded, setExpanded] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    const params = { status, classification, network, search, page, limit: LIMIT };
    TILE_KEYS.forEach((k) => { if (f[k]) params[k] = f[k]; });
    Promise.all([
      socialInboxService.summary(),
      socialInboxService.list(params),
    ])
      .then(([s, list]) => {
        setSummary(s);
        setRows(list.items || []);
        setTotal(list.total ?? 0);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [f, status, classification, network, search, page]);
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
        {r.lead_id && <div className="text-xs mt-1"><DrillLink to={ROUTES.LEAD_DETAIL.replace(':id', r.lead_id)}>Lead #{r.lead_id}</DrillLink></div>}
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
            Comments and messages on Aicountly&apos;s social accounts, read every 10 minutes through social-api.ai. The bot answers
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
            {[
              { title: 'Read', value: day.received, testId: 'tile-read', patch: { since_hours: '24' } },
              { title: 'Replies posted', value: day.replies_sent, testId: 'tile-replies', patch: { reply_status: 'sent', replied_since_hours: '24' } },
              { title: 'Flow tickets', value: day.tickets, testId: 'tile-tickets', patch: { has: 'ticket', since_hours: '24' } },
              { title: 'Leads', value: day.leads, testId: 'tile-leads', patch: { has: 'lead', since_hours: '24' } },
            ].map((t) => (
              <Tile key={t.testId} title={t.title} value={t.value} testId={t.testId}
                    onDrill={() => narrow(t.patch)} active={isOnly(t.patch)} />
            ))}
          </div>
          <div className="flex gap-2 mt-3" style={{ flexWrap: 'wrap' }}>
            {/* "unclassified" is a NULL classification — no value to filter on. */}
            {Object.entries(day.by_classification || {}).map(([k, n]) => (
              <span key={k} className="text-xs">{label(k)}: <strong>
                <DrillLink onClick={k !== 'unclassified' && n > 0 ? () => narrow({ classification: k, since_hours: '24' }) : undefined}
                           label={`Show ${n} ${label(k)} messages from the last 24 hours`}>{n}</DrillLink>
              </strong></span>
            ))}
            {day.leads > 0 && (
              <Link className="text-xs" to={drillHref(ROUTES.LEADS, { source_kind: SOCIAL_LEAD_SOURCE })}>All social inbox leads ›</Link>
            )}
            {day.reply_failures > 0 && (
              <span className="text-xs">reply failures: <strong>
                <DrillLink onClick={() => narrow({ reply_status: 'failed', updated_since_hours: '24' })}
                           label={`Show ${day.reply_failures} reply failures from the last 24 hours`}>{day.reply_failures}</DrillLink>
              </strong></span>
            )}
            {/* Not a 24 h figure: every complaint still queued without a ticket. */}
            {day.waiting_for_flow > 0 && (
              <span className="text-xs">complaints waiting for Flow: <strong>
                <DrillLink onClick={() => narrow({ waiting: 'flow' })}
                           label={`Show ${day.waiting_for_flow} complaints waiting for Flow`}>{day.waiting_for_flow}</DrillLink>
              </strong></span>
            )}
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
          <select aria-label="Outcome" value={status} onChange={(e) => setFilter('status', e.target.value)}>
            {STATUSES.map((s) => <option key={s} value={s}>{s ? label(s) : 'All outcomes'}</option>)}
          </select>
          <select aria-label="Classification" value={classification} onChange={(e) => setFilter('classification', e.target.value)}>
            {CLASSES.map((s) => <option key={s} value={s}>{s ? label(s) : 'All types'}</option>)}
          </select>
          <select aria-label="Network" value={network} onChange={(e) => setFilter('network', e.target.value)}>
            {NETWORKS.map((s) => <option key={s} value={s}>{s ? label(s) : 'All networks'}</option>)}
          </select>
          <input aria-label="Search" placeholder="Text, @handle or TCK-…" value={search} onChange={(e) => setFilter('search', e.target.value)} />
        </FilterBar>
        {/* Tile-only filters have no control above: show them, removable. */}
        {Object.keys(CHIP_LABELS).some((k) => f[k]) && (
          <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
            {Object.entries(CHIP_LABELS).filter(([k]) => f[k]).map(([k, fmt]) => (
              <button key={k} type="button" className="badge badge-secondary"
                      onClick={() => { setPage(1); drill.clear(k); }} aria-label={`Clear ${fmt(f[k])} filter`}>
                {fmt(f[k])} ✕
              </button>
            ))}
          </div>
        )}
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
