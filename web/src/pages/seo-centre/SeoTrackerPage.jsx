import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Play } from 'lucide-react';
import { seoService } from '../../services/seoService';
import { usePermission } from '../../hooks/usePermission';
import { Loader } from '../../components/common/Loader';
import { Alert } from '../../components/common/Alert';
import { Card } from '../../components/common/Card';

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
            { label: 'Keywords checked', value: summary.checked ?? 0 },
            { label: 'We rank (top 100)', value: summary.we_rank ?? 0 },
            { label: 'We are top 10', value: summary.top10 ?? 0 },
            { label: 'Up vs 7 days', value: summary.up ?? 0 },
            { label: 'Down vs 7 days', value: summary.down ?? 0 },
            { label: 'Competitor leads', value: summary.competitor_wins ?? 0 },
          ].map((s) => (
            <div key={s.label} className="stat-tile">
              <div className="stat-tile__label">{s.label}</div>
              <div className="stat-tile__value">{s.value}</div>
            </div>
          ))}
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
                        <span className="text-sm">{row.top10}</span>
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

      <Card title={`Tracked keywords (${keywords.length})`}>
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
              {keywords.map((k) => (
                <tr key={k.id} style={{ opacity: k.enabled ? 1 : 0.55 }}>
                  <td style={{ fontWeight: 500 }}>
                    {k.keyword}
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
