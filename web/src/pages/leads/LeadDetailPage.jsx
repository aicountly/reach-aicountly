import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { leadsService } from '../../services/leadsService';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { StatusBadge } from '../../components/common/StatusBadge';
import { DataTable } from '../../components/common/DataTable';
import { DrillLink, drillHref } from '../../components/drill';
import { ROUTES } from '../../constants/routes';
import { formatDateTime } from '../../utils/formatDate';
import { formatInr } from '../../utils/formatInr';
import { conversionsHref } from './leadLinks';

const label = (s) => (s ? String(s).replace(/_/g, ' ') : '—');
const PUSHABLE = ['pending_push', 'failed', 'retry_scheduled'];

/** A labelled value in a field grid; `to` makes the value a drill into the records sharing it. */
function Field({ name, value, to, title, href }) {
  const empty = value === null || value === undefined || value === '';
  let body = empty ? '—' : value;
  if (!empty && href) {
    body = <a href={href} target="_blank" rel="noopener noreferrer" style={{ overflowWrap: 'anywhere' }}>{value} <ExternalLink size={11} /></a>;
  } else if (!empty && to) {
    body = <DrillLink to={to} title={title}>{value}</DrillLink>;
  }
  return (
    <div>
      <div className="text-xs text-muted">{name}</div>
      <div className="text-sm" style={{ overflowWrap: 'anywhere' }}>{body}</div>
    </div>
  );
}

/** JSON stored with a record (push request/response, audit values), collapsed by default. */
function JsonDetails({ summary, value }) {
  if (value === null || value === undefined || (typeof value === 'object' && Object.keys(value).length === 0)) return null;
  return (
    <details>
      <summary className="text-xs text-muted" style={{ cursor: 'pointer' }}>{summary}</summary>
      <pre className="text-xs" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', margin: '0.25rem 0 0', maxHeight: 240, overflow: 'auto' }}>
        {typeof value === 'string' ? value : JSON.stringify(value, null, 2)}
      </pre>
    </details>
  );
}

const isHttpUrl = (u) => /^https?:\/\//i.test(String(u || ''));

/**
 * Leads → one lead: the record every lead count ends at. Contact and capture
 * data, the Engage push and every attempt, Engage's outcome events, the
 * attribution journey, the social inbox message it came from, and its audit trail.
 */
export function LeadDetailPage() {
  const { id } = useParams();
  const [lead, setLead] = useState(null);
  const [error, setError] = useState(null);
  const [pushError, setPushError] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    setError(null);
    leadsService.get(id).then(setLead).catch((e) => setError(e.message));
  }, [id]);
  useEffect(load, [load]);

  const push = async () => {
    setBusy(true);
    setPushError(null);
    try { await leadsService.push(lead.id); load(); }
    catch (e) { setPushError(e.message); }
    finally { setBusy(false); }
  };

  if (error) {
    return (
      <div>
        <Link to={ROUTES.LEADS} className="btn btn-secondary btn-sm"><ArrowLeft size={12} /> All leads</Link>
        <div className="mt-3"><Alert variant="danger">{error}</Alert></div>
      </div>
    );
  }
  if (!lead) return <Loader />;

  const r = lead.related || {};
  const attempts = r.push_attempts || [];
  const outcomes = r.outcomes || [];
  const conversions = r.attribution?.conversions || [];
  const touchpoints = r.attribution?.touchpoints || [];
  const inbox = r.social_inbox || [];
  const audit = r.audit || [];
  const leads = (params) => drillHref(ROUTES.LEADS, params);
  const outcomesHref = (params) => drillHref(ROUTES.LEAD_OUTCOMES, { days: 'all', ...params });
  const campaignHref = (cid) => ROUTES.CAMPAIGN_DETAIL.replace(':id', cid);

  const attemptColumns = [
    { key: 'attempt_number', label: '#', render: (a) => a.attempt_number },
    { key: 'attempted_at', label: 'When', render: (a) => formatDateTime(a.attempted_at) },
    { key: 'ok', label: 'Result', render: (a) => <StatusBadge status={a.ok ? 'ok' : 'failed'} /> },
    { key: 'response_status', label: 'HTTP', render: (a) => a.response_status ?? '—' },
    { key: 'error_message', label: 'Error / response', render: (a) => (
      <div style={{ maxWidth: 420 }}>
        {a.error_message && <div className="text-danger text-xs" style={{ overflowWrap: 'anywhere' }}>{a.error_message}</div>}
        <JsonDetails summary="Response" value={a.response_body} />
        <JsonDetails summary="Request" value={a.request_body} />
        {!a.error_message && !a.response_body && !a.request_body && '—'}
      </div>
    )},
  ];

  const outcomeColumns = [
    { key: 'occurred_at', label: 'When', render: (o) => formatDateTime(o.occurred_at) },
    { key: 'stage', label: 'Stage', render: (o) => (
      <div>
        <div>{label(o.stage)}</div>
        {o.sales_status && <div className="text-xs text-muted">{label(o.sales_status)}</div>}
      </div>
    )},
    { key: 'value_inr', label: 'Value', render: (o) => formatInr(o.value_inr) },
    { key: 'product', label: 'Product', render: (o) => o.product || '—' },
    { key: 'attributed', label: 'Attributed to', render: (o) => (
      <div>
        <div>
          {o.campaign_id
            ? <DrillLink to={campaignHref(o.campaign_id)}>{o.campaign_name || `Campaign #${o.campaign_id}`}</DrillLink>
            : o.source ? <DrillLink to={outcomesHref({ source: o.source })} title="All outcomes from this source">{o.source}</DrillLink> : '—'}
        </div>
        <div className="text-xs text-muted">
          {label(o.attribution_method)}
          {o.seller_code && <> · seller <DrillLink to={outcomesHref({ seller_code: o.seller_code })}>{o.seller_code}</DrillLink></>}
        </div>
      </div>
    )},
    { key: 'match_method', label: 'Matched by', render: (o) => label(o.match_method) },
    { key: 'event_id', label: 'Event', render: (o) => <span className="text-xs text-muted" style={{ fontFamily: 'monospace' }}>{o.event_id}</span> },
  ];

  const conversionColumns = [
    { key: 'converted_at', label: 'Converted', render: (c) => formatDateTime(c.converted_at) },
    { key: 'conversion_type', label: 'Type', render: (c) => label(c.conversion_type) },
    { key: 'attributed', label: 'Attributed', render: (c) => (
      <DrillLink to={conversionsHref({ attributed: c.attributed ? '1' : '0' })} title={c.attributed ? 'All attributed conversions' : 'All unattributed conversions'}>
        {c.attributed ? 'yes' : 'no'}
      </DrillLink>
    )},
    { key: 'first_touch_channel', label: 'First touch', render: (c) => (
      c.first_touch_channel
        ? <DrillLink to={c.attributed ? conversionsHref({ attributed: '1', channel: c.first_touch_channel }) : null} title="Attributed conversions with this first-touch channel">{c.first_touch_channel}</DrillLink>
        : '—'
    )},
    { key: 'last_touch_channel', label: 'Last touch', render: (c) => c.last_touch_channel || '—' },
    { key: 'matching_method', label: 'Model', render: (c) => <DrillLink to={conversionsHref({ model: c.matching_method })}>{label(c.matching_method)}</DrillLink> },
    { key: 'confidence_state', label: 'Confidence', render: (c) => (
      <div>
        <div>{label(c.confidence_state)}</div>
        {c.manual_correction_note && <div className="text-xs text-muted">{c.manual_correction_note}</div>}
      </div>
    )},
  ];

  const touchColumns = [
    { key: 'touched_at', label: 'When', render: (t) => formatDateTime(t.touched_at) },
    { key: 'channel', label: 'Channel', render: (t) => t.channel || t.utm_source || 'Unknown' },
    { key: 'touchpoint_type', label: 'Type', render: (t) => label(t.touchpoint_type) },
    { key: 'utm', label: 'UTM', render: (t) => (
      <span className="text-xs">{[t.utm_source, t.utm_medium, t.utm_campaign, t.utm_content, t.utm_term].filter(Boolean).join(' / ') || '—'}</span>
    )},
    { key: 'referrer_domain', label: 'Referrer', render: (t) => t.referrer_domain || '—' },
    { key: 'campaign_id', label: 'Campaign', render: (t) => (t.campaign_id ? <DrillLink to={campaignHref(t.campaign_id)}>#{t.campaign_id}</DrillLink> : '—') },
  ];

  const auditColumns = [
    { key: 'created_at', label: 'When', render: (a) => formatDateTime(a.created_at) },
    { key: 'action', label: 'Action', render: (a) => a.action },
    { key: 'actor', label: 'By', render: (a) => a.user_name || (a.user_id ? `User #${a.user_id}` : (a.actor_service || a.actor_type || 'system')) },
    { key: 'change', label: 'Change', render: (a) => (
      <div style={{ maxWidth: 420 }}>
        {a.reason && <div className="text-xs">{a.reason}</div>}
        <JsonDetails summary={a.new_value && typeof a.new_value === 'object' ? Object.keys(a.new_value).join(', ') : 'New value'} value={a.new_value} />
        <JsonDetails summary="Previous value" value={a.old_value} />
        {!a.reason && !a.new_value && !a.old_value && '—'}
      </div>
    )},
  ];

  const utm = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

  return (
    <div>
      <div className="page-header">
        <div>
          <Link to={ROUTES.LEADS} className="btn btn-secondary btn-sm"><ArrowLeft size={12} /> All leads</Link>
          <h1 style={{ marginTop: 6 }}>{lead.name || `Lead #${lead.id}`}</h1>
          <div className="flex gap-2 mt-1" style={{ flexWrap: 'wrap', alignItems: 'center' }}>
            <span className="text-xs text-muted">Lead #{lead.id} · captured {formatDateTime(lead.created_at)}</span>
            <StatusBadge status={lead.engage_push_status} />
            {lead.priority && <span className="badge badge-secondary">{lead.priority}</span>}
          </div>
        </div>
        {PUSHABLE.includes(lead.engage_push_status) && (
          <button className="btn btn-primary" disabled={busy} onClick={push}>{busy ? 'Pushing…' : 'Push to Engage'}</button>
        )}
      </div>
      {pushError && <Alert variant="danger">{pushError}</Alert>}

      <div className="grid grid-2" style={{ alignItems: 'start' }}>
        <Card title="Contact">
          <div className="grid grid-2">
            <Field name="E-mail" value={lead.email} />
            <Field name="Organization" value={lead.organization} />
            <Field name="Mobile" value={lead.mobile} />
            <Field name="WhatsApp" value={lead.whatsapp} />
            <Field name="Product interest" value={lead.product_interest} />
            <Field name="Updated" value={lead.updated_at ? formatDateTime(lead.updated_at) : ''} />
          </div>
          {lead.notes && <div className="mt-3"><div className="text-xs text-muted">Notes</div><div className="text-sm" style={{ whiteSpace: 'pre-wrap' }}>{lead.notes}</div></div>}
        </Card>

        <Card title="Source">
          <div className="grid grid-2">
            <Field name="Source kind" value={lead.source_kind} to={leads({ source_kind: lead.source_kind })} title="Leads from this source" />
            <Field name="Campaign" value={lead.campaign_id ? (r.campaign?.name || `Campaign #${lead.campaign_id}`) : ''} to={lead.campaign_id ? campaignHref(lead.campaign_id) : null} />
            <Field name="Landing page" value={lead.landing_page_id ? (r.landing_page?.title || `Landing page #${lead.landing_page_id}`) : ''} to={lead.landing_page_id ? ROUTES.LANDING_DETAIL.replace(':id', lead.landing_page_id) : null} />
            <Field name="Seller referral (ref)" value={lead.ref_code} to={lead.ref_code ? outcomesHref({ seller_code: lead.ref_code }) : null} title="Engage outcomes credited to this seller" />
            <Field name="Landing URL" value={lead.landing_url} href={isHttpUrl(lead.landing_url) ? lead.landing_url : null} />
            <Field name="Referrer" value={lead.referrer_url} href={isHttpUrl(lead.referrer_url) ? lead.referrer_url : null} />
            {utm.map((k) => (
              <Field key={k} name={k} value={lead[k]}
                to={lead[k] && k !== 'utm_content' && k !== 'utm_term' ? leads({ [k]: lead[k] }) : null}
                title={`Leads with this ${k}`} />
            ))}
          </div>
          <div className="mt-2"><JsonDetails summary="Captured payload" value={lead.raw_payload} /></div>
        </Card>
      </div>

      <div className="mt-3">
        <Card title="Engage">
          <div className="grid grid-4">
            <Field name="Push status" value={label(lead.engage_push_status)} to={lead.engage_push_status ? drillHref(ROUTES.ENGAGE_PUSH, { status: lead.engage_push_status }) : null} title="Leads with this push status" />
            <Field name="Engage lead code" value={lead.engage_lead_code} />
            <Field name="Attempts" value={String(lead.engage_push_attempts ?? 0)} />
            <Field name="Last push" value={lead.last_push_at ? formatDateTime(lead.last_push_at) : ''} />
            <Field name="Engage stage" value={lead.engage_stage ? label(lead.engage_stage) : ''} />
            <Field name="Sales status" value={lead.engage_sales_status ? label(lead.engage_sales_status) : ''} />
            <Field name="Stage updated" value={lead.engage_stage_at ? formatDateTime(lead.engage_stage_at) : ''} />
          </div>
          {lead.last_push_error && <div className="text-danger text-xs mt-2" style={{ overflowWrap: 'anywhere' }}>Last error: {lead.last_push_error}</div>}
        </Card>
      </div>

      <div className="mt-3">
        <Card padding={false} title={`Push attempts (${attempts.length})`}>
          <DataTable columns={attemptColumns} rows={attempts} emptyMessage="No push attempts recorded for this lead." />
        </Card>
      </div>

      <div className="mt-3">
        <Card padding={false} title={`Engage outcomes (${outcomes.length})`}
          actions={outcomes.length > 0 ? <Link className="text-xs" to={outcomesHref({ lead_id: lead.id })}>Open in Outcomes ›</Link> : null}>
          <DataTable columns={outcomeColumns} rows={outcomes} emptyMessage="Engage has not reported any outcome for this lead." />
        </Card>
      </div>

      <div className="mt-3">
        <Card padding={false} title={`Attribution conversions (${conversions.length})`}>
          <DataTable columns={conversionColumns} rows={conversions} emptyMessage="No attribution conversion is linked to this lead." />
        </Card>
      </div>

      {touchpoints.length > 0 && (
        <div className="mt-3">
          <Card padding={false} title={`Touchpoint journey (${touchpoints.length})`}>
            <DataTable columns={touchColumns} rows={touchpoints} />
          </Card>
        </div>
      )}

      <div className="mt-3">
        <Card title="Social inbox origin">
          {inbox.length === 0 ? (
            <p className="text-sm text-muted" style={{ margin: 0 }}>This lead did not come from the social inbox.</p>
          ) : inbox.map((m) => (
            <div key={m.id} className="mb-2">
              <div className="text-sm">
                <strong>{m.author_name || m.author_handle || 'Unknown author'}</strong>
                {m.author_handle && m.author_name ? <span className="text-muted"> {m.author_handle}</span> : null}
                <span className="text-xs text-muted"> · {m.network} {m.kind} · {formatDateTime(m.received_at)}</span>
              </div>
              <div className="text-sm" style={{ whiteSpace: 'pre-wrap', margin: '0.25rem 0' }}>{m.text}</div>
              <div className="text-xs text-muted flex gap-2" style={{ flexWrap: 'wrap' }}>
                <StatusBadge status={m.status} />
                {m.classification && <span>{label(m.classification)}</span>}
                {m.ticket_number && <span>ticket {m.ticket_number}</span>}
                {isHttpUrl(m.post_url) && <a href={m.post_url} target="_blank" rel="noopener noreferrer">Open post <ExternalLink size={11} /></a>}
                <Link to={drillHref(ROUTES.SOCIAL_INBOX, { has: 'lead', network: m.network, search: m.author_handle || '' })}>In the inbox ›</Link>
              </div>
            </div>
          ))}
        </Card>
      </div>

      <div className="mt-3">
        <Card padding={false} title={`Audit trail (${audit.length})`}>
          <DataTable columns={auditColumns} rows={audit} emptyMessage="No audit entries for this lead." />
        </Card>
      </div>
    </div>
  );
}

export default LeadDetailPage;
