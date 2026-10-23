import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Play } from 'lucide-react';
import { seoService } from '../../services/seoService';
import { usePermission } from '../../hooks/usePermission';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';
import { DrillLink, drillHref, useDrillFilters } from '../../components/drill';

const STATUS_TONE = {
  ready: 'var(--color-success)',
  disabled: 'var(--color-warning)',
  not_configured: 'var(--color-warning)',
  not_migrated: 'var(--color-danger)',
};

function rank(value) {
  return value == null ? '—' : `#${value}`;
}

function Change({ value }) {
  if (value == null) return <span className="text-muted">—</span>;
  if (value === 0) return <span className="text-muted">0</span>;
  const up = value > 0;
  return (
    <span style={{ color: up ? 'var(--color-success)' : 'var(--color-danger)', fontWeight: 600 }}>
      {up ? '▲' : '▼'} {Math.abs(value)}
    </span>
  );
}

/**
 * Keyword-table slices behind each summary tile. Same predicates as
 * SeoTrackerService::tracker() counts them, so a tile's number and the rows
 * it shows agree. `competitor` narrows to keywords where that competitor is
 * in the top 10 (the "Share of top 10" rows).
 */
const SLICES = {
  checked:          { label: 'Keywords checked', test: (k) => k.check_status === 'ok' },
  ranked:           { label: 'We rank (top 100)', test: (k) => k.check_status === 'ok' && k.our_rank != null },
  top10:            { label: 'We are top 10', test: (k) => k.check_status === 'ok' && k.our_rank != null && k.our_rank <= 10 },
  up:               { label: 'Up vs 7 days', test: (k) => k.change != null && k.change > 0 },
  down:             { label: 'Down vs 7 days', test: (k) => k.change != null && k.change < 0 },
  competitor_leads: { label: 'Competitor leads', test: (k) => k.check_status === 'ok' && k.winner != null && !k.winner.ours },
};

/**
 * SEO Tracker — real Google India positions from DataForSEO for the tracked
 * keywords, for aicountly.com and each competitor. Without credentials the
 * page says so and shows no positions.
 */
export function SeoTrackerPage() {
  const { has } = usePermission();
  const canManage = has('seo.manage');

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [newKeyword, setNewKeyword] = useState('');
  const [product, setProduct] = useState('');
  // ?show= / ?competitor= narrow the keyword table; one URL update per click
  // (drill pushes history, so Back returns to the full table).
  const tableFilter = useDrillFilters(['show', 'competitor']);
  const { show, competitor: competitorFilter } = tableFilter.filters;

  const load = useCallback(() => {
    setLoading(true);
    seoService.getTracker()
      .then((res) => { setData(res); setError(''); })
      .catch((err) => setError(err?.message || 'Unable to load the SEO Tracker.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const act = async (fn, success) => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const res = await fn();
      setNotice(typeof success === 'function' ? success(res) : success);
      load();
    } catch (err) {
      setError(err?.message || 'Action failed.');
    } finally {
      setBusy(false);
    }
  };

  if (loading && !data) return <Loader label="Loading SEO Tracker…" />;

  const status = data?.status || {};
  const competitors = data?.competitors || [];
  const keywords = data?.keywords || [];
  const summary = data?.summary || {};
  const share = data?.top10_share || [];
  const canRun = canManage && (status.status === 'ready' || status.status === 'disabled');
  const slice = SLICES[show] || null;
  const competitorName = competitors.find((c) => String(c.id) === competitorFilter)?.name;
  const shownKeywords = keywords.filter((k) => (!slice || slice.test(k))
    && (!competitorFilter || (k.check_status === 'ok' && k.competitor_ranks?.[competitorFilter] != null && k.competitor_ranks[competitorFilter] <= 10)));
  const filtered = Boolean(slice || competitorFilter);
  const narrow = (patch) => {
    tableFilter.drill({ show: patch.show ?? '', competitor: patch.competitor ?? '' });
    document.getElementById('tracked-keywords')?.scrollIntoView?.({ behavior: 'smooth' });
  };

  return (
    <div>
      {error && <Alert variant="danger">{error}</Alert>}
      {notice && <Alert variant="success">{notice}</Alert>}

      <Card
        title="SEO Tracker"
        actions={(
          <>
            <button type="button" className="btn btn-secondary btn-sm" onClick={load} disabled={busy}>
              <RefreshCw size={14} /> Refresh
            </button>
            {canManage && (
              <button
                type="button"
                className="btn btn-primary btn-sm"
                disabled={busy || !canRun}
                title={canRun ? 'Checks today’s remaining keywords (paid DataForSEO calls)' : status.detail}
                onClick={() => act(() => seoService.runTracker({}), (r) => r?.message || 'Run queued.')}
              >
                <Play size={14} /> Run now
              </button>
            )}
          </>
        )}
      >
        <div style={{ display: 'flex', gap: '1rem', alignItems: 'baseline', flexWrap: 'wrap' }}>
          <span className="text-xs" style={{ fontWeight: 700, color: STATUS_TONE[status.status] || 'var(--color-danger)' }} data-testid="tracker-status">
            {String(status.status || 'unknown').replace('_', ' ').toUpperCase()}
          </span>
          <span className="text-sm">{status.detail}</span>
        </div>
        <p className="text-sm text-muted" style={{ margin: '0.5rem 0 0' }}>
          Google {status.location_code === 2356 ? 'India' : `location ${status.location_code ?? '—'}`} · language {status.language_code || '—'} · top {status.depth ?? '—'} results ·
          up to {status.max_keywords_per_day ?? '—'} keywords/day · our domain {status.our_domain || '—'}
          {data?.date ? <> · latest check <strong>{data.date}</strong></> : null}
        </p>
      </Card>

      {data?.date && (
        <div className="grid grid-3" style={{ margin: '1.25rem 0' }}>
          {[
            { key: 'checked', value: summary.checked ?? 0 },
            { key: 'ranked', value: summary.we_rank ?? 0 },
            { key: 'top10', value: summary.top10 ?? 0 },
            { key: 'up', value: summary.up ?? 0 },
            { key: 'down', value: summary.down ?? 0 },
            { key: 'competitor_leads', value: summary.competitor_wins ?? 0 },
          ].map((s) => {
            const label = SLICES[s.key].label;
            const body = (
              <>
                <div className="stat-tile__label">{label}</div>
                <div className="stat-tile__value">{s.value}</div>
              </>
            );
            // A tile narrows the keyword table below; zero has nothing to show.
            return s.value > 0 ? (
              <button key={s.key} type="button" className="stat-tile" onClick={() => narrow({ show: s.key })}
                      aria-pressed={show === s.key} aria-label={`${label}: ${s.value} — show keywords`}>
                {body}
              </button>
            ) : (
              <div key={s.key} className="stat-tile">{body}</div>
            );
          })}
        </div>
      )}

      {data?.date && share.length > 0 && (
        <Card title="Share of top 10">
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Domain</th><th style={{ width: '50%' }}>Keywords in top 10</th><th style={{ textAlign: 'right' }}>Share</th></tr></thead>
              <tbody>
                {share.map((row) => (
                  <tr key={row.domain}>
                    <td style={{ fontWeight: row.ours ? 700 : 500 }}>{row.name} <span className="text-xs text-muted">{row.domain}</span></td>
                    <td>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                        <div aria-hidden="true" style={{ height: '0.5rem', width: `${Math.max(2, (row.share || 0) * 100)}%`, background: row.ours ? 'var(--color-success)' : 'var(--color-info)', borderRadius: '2px' }} />
                        <span className="text-sm">
                          <DrillLink
                            onClick={row.top10 > 0 ? () => narrow(row.ours ? { show: 'top10' } : { competitor: String(row.id) }) : undefined}
                            title="Keywords where this domain is in the top 10"
                          >
                            {row.top10}
                          </DrillLink>
                        </span>
                      </div>
                    </td>
                    <td style={{ textAlign: 'right' }}>{row.share == null ? '—' : `${Math.round(row.share * 100)}%`}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {!data?.date && (
        <div className="alert alert-info" style={{ margin: '1.25rem 0' }} data-testid="tracker-empty">
          {status.status === 'ready'
            ? 'No SERP check has completed yet. Checks run daily at 06:00 IST, or use “Run now”.'
            : 'No positions are shown until DataForSEO is configured — nothing here is estimated.'}
        </div>
      )}

      {canManage && (
        <Card title="Track a keyword">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              if (!newKeyword.trim()) return;
              act(() => seoService.createTrackedKeyword({ keyword: newKeyword.trim(), product: product || null }), () => {
                setNewKeyword('');
                return 'Keyword added — it is checked in the next run.';
              });
            }}
            style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}
          >
            <input value={newKeyword} onChange={(e) => setNewKeyword(e.target.value)} placeholder="e.g. gst reconciliation software" style={{ flex: 1, minWidth: '14rem' }} aria-label="Keyword" />
            <select value={product} onChange={(e) => setProduct(e.target.value)} aria-label="Product">
              <option value="">Product…</option>
              {['smart_books', 'billing', 'inventory', 'notes', 'drive', 'brand'].map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
            <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>Track</button>
          </form>
          <p className="text-xs text-muted" style={{ margin: '0.5rem 0 0' }}>Each enabled keyword is one paid DataForSEO call per day.</p>
        </Card>
      )}

      <div id="tracked-keywords" />
      <Card title={filtered ? `Tracked keywords (${shownKeywords.length} of ${keywords.length})` : `Tracked keywords (${keywords.length})`}>
        {filtered && (
          <div className="flex items-center gap-2 mb-2" style={{ flexWrap: 'wrap' }}>
            {slice && (
              <button type="button" className="badge badge-secondary" onClick={() => tableFilter.clear('show')}
                      aria-label={`Clear ${slice.label} filter`}>
                {slice.label} ✕
              </button>
            )}
            {competitorFilter && (
              <button type="button" className="badge badge-secondary" onClick={() => tableFilter.clear('competitor')}
                      aria-label="Clear competitor filter">
                {competitorName || `Competitor #${competitorFilter}`} in top 10 ✕
              </button>
            )}
          </div>
        )}
        <div className="table-wrap" style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Keyword</th>
                <th>Product</th>
                <th style={{ textAlign: 'right' }}>Our position</th>
                <th style={{ textAlign: 'right' }}>vs 7d</th>
                {competitors.map((c) => <th key={c.id} style={{ textAlign: 'right' }} title={c.domain}>{c.name}</th>)}
                <th>Winner</th>
                {canManage && <th>Tracked</th>}
              </tr>
            </thead>
            <tbody>
              {filtered && shownKeywords.length === 0 && (
                <tr><td colSpan={5 + competitors.length + (canManage ? 1 : 0)} className="text-sm text-muted">No keywords match this filter.</td></tr>
              )}
              {shownKeywords.map((k) => (
                <tr key={k.id} style={{ opacity: k.enabled ? 1 : 0.55 }}>
                  <td style={{ fontWeight: 500 }}>
                    {/* Which of our pages Search Console shows for this keyword. */}
                    <DrillLink to={drillHref('/seo-centre/search', { query: k.keyword, by: 'page' })} title="Search Console pages for this keyword">
                      {k.keyword}
                    </DrillLink>
                    {k.check_status === 'failed' && <div className="text-xs" style={{ color: 'var(--color-danger)' }}>Check failed: {k.error}</div>}
                  </td>
                  <td className="text-sm">{k.product || '—'}</td>
                  <td style={{ textAlign: 'right', fontWeight: 600 }}>
                    {k.our_url ? <a href={k.our_url} target="_blank" rel="noreferrer">{rank(k.our_rank)}</a> : rank(k.our_rank)}
                  </td>
                  <td style={{ textAlign: 'right' }}><Change value={k.change} /></td>
                  {competitors.map((c) => (
                    <td key={c.id} style={{ textAlign: 'right' }}>{rank(k.competitor_ranks?.[String(c.id)])}</td>
                  ))}
                  <td className="text-sm" style={{ fontWeight: 600, color: k.winner?.ours ? 'var(--color-success)' : undefined }}>
                    {k.winner ? `${k.winner.name} (#${k.winner.rank})` : '—'}
                  </td>
                  {canManage && (
                    <td>
                      <input
                        type="checkbox"
                        checked={!!k.enabled}
                        disabled={busy}
                        aria-label={`Track ${k.keyword}`}
                        onChange={(e) => act(() => seoService.updateTrackedKeyword(k.id, { enabled: e.target.checked }), 'Saved.')}
                      />
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}

export default SeoTrackerPage;
