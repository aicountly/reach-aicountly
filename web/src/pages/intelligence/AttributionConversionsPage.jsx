import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Target } from 'lucide-react';
import { listAttributionConversions } from '../../services/intelligenceService.js';
import { Card } from '../../components/common/Card';
import { Alert } from '../../components/common/Alert';
import { Loader } from '../../components/common/Loader';
import { DataTable } from '../../components/common/DataTable';
import { FilterBar } from '../../components/common/FilterBar';
import { Pagination } from '../../components/common/Pagination';
import { DrillLink, drillHref, useUrlFilter } from '../../components/drill';
import { formatDateTime } from '../../utils/formatDate';
import { ATTRIBUTION_CONVERSIONS_PATH, leadHref } from '../leads/leadLinks';

const LIMIT = 50;
const MODELS = ['first_touch', 'last_touch', 'manual', 'unattributed'];
const CONFIDENCES = ['confirmed', 'inferred', 'unattributed', 'corrected'];
const TYPES = ['lead', 'signup', 'demo_request', 'contact', 'download', 'engage_push'];
// Drill filters with no control of their own — removable chips.
const CHIP_FILTERS = { channel: 'First touch: ', lead_id: 'Lead #' };

const label = (s) => (s ? String(s).replace(/_/g, ' ') : '—');

/**
 * Intelligence → Attribution → conversions: the conversion links behind the
 * overview's tiles and first-touch bars. Every filter is a URL param the API
 * applies with the overview's own predicates, so a tile's number is this
 * list's total.
 */
export default function AttributionConversionsPage() {
  const [params] = useSearchParams();
  const [attributed, setAttributed] = useUrlFilter('attributed');
  const [model, setModel] = useUrlFilter('model');
  const [confidence, setConfidence] = useUrlFilter('confidence');
  const [type, setType] = useUrlFilter('type');
  const [from, setFrom] = useUrlFilter('from');
  const [to, setTo] = useUrlFilter('to');
  const chipValues = Object.fromEntries(Object.keys(CHIP_FILTERS).map((k) => [k, params.get(k) || '']));
  const chipKey = JSON.stringify(chipValues);
  const filterKey = JSON.stringify([attributed, model, confidence, type, from, to, chipKey]);
  const [page, setPage] = useState(1);
  const [data, setData] = useState({ items: [], total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  // A new filter starts again at page 1.
  useEffect(() => { setPage(1); }, [filterKey]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    listAttributionConversions({ attributed, model, confidence, type, from, to, ...JSON.parse(chipKey), page, limit: LIMIT })
      .then((d) => { if (!cancelled) setData({ items: d?.items || [], total: d?.total ?? 0 }); })
      .catch((e) => { if (!cancelled) setError(e.message || 'Failed to load conversions'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [attributed, model, confidence, type, from, to, chipKey, page]);

  // An in-page drill step: the current filters plus `patch` (a Link, so Back steps up again).
  const here = (patch) => drillHref(ATTRIBUTION_CONVERSIONS_PATH, { ...Object.fromEntries(params), ...patch });
  const activeChips = Object.entries(CHIP_FILTERS).filter(([k]) => chipValues[k]);
  const title = attributed === '1' ? 'Attributed conversions' : attributed === '0' ? 'Unattributed conversions' : 'Conversions';

  const columns = [
    { key: 'converted_at', label: 'Converted', render: (c) => formatDateTime(c.converted_at) },
    // Cells drill in place, so the row opens its lead through this link rather than onRowClick.
    { key: 'lead', label: 'Lead', render: (c) => (
      c.lead_id ? (
        <div>
          <DrillLink to={leadHref(c.lead_id)} title="Open this lead">#{c.lead_id} · {c.lead_name || '—'}</DrillLink>
          {c.lead_email && <div className="text-xs text-muted">{c.lead_email}</div>}
        </div>
      ) : <span className="text-muted">No lead</span>
    )},
    { key: 'conversion_type', label: 'Type', render: (c) => label(c.conversion_type),
      drill: (c) => (c.conversion_type && c.conversion_type !== type ? here({ type: c.conversion_type }) : null) },
    { key: 'attributed', label: 'Attributed', render: (c) => (c.attributed ? 'yes' : 'no'),
      drill: (c) => (attributed === '' ? here({ attributed: c.attributed ? '1' : '0' }) : null) },
    { key: 'first_touch_channel', label: 'First touch', render: (c) => (
      <div>
        <div>{c.first_touch_channel || '—'}</div>
        {(c.first_utm_source || c.first_utm_campaign) && (
          <div className="text-xs text-muted">{[c.first_utm_source, c.first_utm_medium, c.first_utm_campaign].filter(Boolean).join(' / ')}</div>
        )}
      </div>
    ), drill: (c) => (c.first_touch_channel && !chipValues.channel ? here({ channel: c.first_touch_channel }) : null) },
    { key: 'last_touch_channel', label: 'Last touch', render: (c) => c.last_touch_channel || '—' },
    { key: 'matching_method', label: 'Model', render: (c) => label(c.matching_method),
      drill: (c) => (c.matching_method !== model ? here({ model: c.matching_method }) : null) },
    { key: 'confidence_state', label: 'Confidence', render: (c) => label(c.confidence_state),
      drill: (c) => (c.confidence_state !== confidence ? here({ confidence: c.confidence_state }) : null) },
  ];

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1.25rem' }}>
        <Target size={26} style={{ color: 'var(--color-info)', flexShrink: 0 }} />
        <div>
          <Link to="/intelligence/attribution" className="text-xs"><ArrowLeft size={11} /> Attribution overview</Link>
          <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>{title}</h1>
          <p className="text-sm text-muted" style={{ margin: '0.15rem 0 0' }}>
            Conversion links with their first and last touchpoints. Open a row for the lead.
          </p>
        </div>
      </div>

      <FilterBar>
        <select aria-label="Attributed" value={attributed} onChange={(e) => setAttributed(e.target.value)}>
          <option value="">Attributed and unattributed</option>
          <option value="1">Attributed</option>
          <option value="0">Unattributed</option>
        </select>
        <select aria-label="Model" value={model} onChange={(e) => setModel(e.target.value)}>
          <option value="">Any model</option>
          {MODELS.map((m) => <option key={m} value={m}>{label(m)}</option>)}
        </select>
        <select aria-label="Confidence" value={confidence} onChange={(e) => setConfidence(e.target.value)}>
          <option value="">Any confidence</option>
          {CONFIDENCES.map((m) => <option key={m} value={m}>{label(m)}</option>)}
        </select>
        <select aria-label="Type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">Any type</option>
          {TYPES.map((m) => <option key={m} value={m}>{label(m)}</option>)}
        </select>
        <input type="date" aria-label="From" value={from} onChange={(e) => setFrom(e.target.value)} />
        <input type="date" aria-label="To" value={to} onChange={(e) => setTo(e.target.value)} />
      </FilterBar>

      {activeChips.length > 0 && (
        <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
          {activeChips.map(([k, chip]) => (
            <Link key={k} to={here({ [k]: '' })} className="badge badge-secondary" aria-label={`Clear ${chip.replace(/[ #:]+$/, '')} filter`}>
              {chip}{chipValues[k]} ✕
            </Link>
          ))}
        </div>
      )}

      {error && <Alert variant="danger">{error}</Alert>}
      {loading ? <Loader /> : (
        <Card padding={false} title={`${data.total} conversion${data.total === 1 ? '' : 's'}`}>
          <DataTable
            columns={columns}
            rows={data.items}
            emptyMessage="No conversions match this filter."
          />
          {data.total > LIMIT && <Pagination page={page} limit={LIMIT} total={data.total} onPage={setPage} />}
        </Card>
      )}

      <p className="text-xs text-muted" style={{ marginTop: '1rem' }}>
        Visitors are stored as one-way hashes. Raw PII is never retained in the attribution system.
      </p>
    </div>
  );
}
