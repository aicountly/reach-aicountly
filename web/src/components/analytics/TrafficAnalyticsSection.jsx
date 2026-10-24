import { useState, useEffect, useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { analyticsService } from '../../services/analyticsService';
import { KPICard } from '../charts/KPICard';
import { LineChart } from '../charts/LineChart';
import { PieChart } from '../charts/PieChart';
import { BarChart } from '../charts/BarChart';
import { Card } from '../common/Card';
import { Loader } from '../common/Loader';
import { drillHref } from '../drill';
import { ROUTES } from '../../constants/routes';
import {
  DAYS_OPTIONS, EVENT_LABELS, LEAD_SUBMIT_EVENTS, STREAM_OPTIONS, streamLabel as baseStreamLabel, trafficExplorerHref,
} from './trafficDrill';
import {
  Activity, Users, Eye, MousePointerClick, AlertCircle, CheckCircle2,
} from 'lucide-react';

function formatGaDate(ymd) {
  if (!ymd || String(ymd).length !== 8) return ymd;
  const s = String(ymd);
  return `${s.slice(6, 8)}/${s.slice(4, 6)}`;
}

/**
 * GA4 traffic overview. Every figure drills into TrafficExplorerPage for the
 * same window and stream: KPI cards (by channel / page), a trend day, a
 * source slice, a top page and a lead-event bar, each narrowed to its value.
 */
export function TrafficAnalyticsSection() {
  const navigate = useNavigate();
  const [days, setDays] = useState(30);
  const [stream, setStream] = useState('all');
  const [streamInitialized, setStreamInitialized] = useState(false);
  const [overview, setOverview] = useState(null);
  const [sources, setSources] = useState(null);
  const [leads, setLeads] = useState(null);
  const [config, setConfig] = useState(null);
  const [configLoading, setConfigLoading] = useState(true);
  const [dataLoading, setDataLoading] = useState(true);
  const [unconfigured, setUnconfigured] = useState(false);
  const [unconfiguredReason, setUnconfiguredReason] = useState('');
  const selectedStreamLabel = useMemo(() => baseStreamLabel(stream), [stream]);

  const currentStreamLive = useMemo(() => {
    if (!config) return false;
    if (stream === 'all') return Boolean(config.ready);
    const row = (config.streams || []).find((s) => s.id === stream);
    return row?.api_ok === true;
  }, [config, stream]);

  const currentStreamConfig = useMemo(
    () => (config?.streams || []).find((s) => s.id === stream) ?? null,
    [config, stream],
  );

  const loading = configLoading || dataLoading;

  useEffect(() => {
    let cancelled = false;
    setConfigLoading(true);
    analyticsService.trafficConfigStatus()
      .then((cfg) => {
        if (!cancelled) setConfig(cfg);
      })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setConfigLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (streamInitialized || stream !== 'all' || !config?.default_stream) return;
    // The backend may default to a stream (e.g. marketing_site/portal) that
    // isn't offered in this dropdown — stay on "All sites" rather than
    // switching to a value with no matching <option>.
    if (STREAM_OPTIONS.some((opt) => opt.value === config.default_stream)) {
      setStream(config.default_stream);
    }
    setStreamInitialized(true);
  }, [config, stream, streamInitialized]);

  useEffect(() => {
    if (configLoading && stream === 'all') {
      return undefined;
    }

    if (
      !streamInitialized
      && stream === 'all'
      && config?.default_stream
      && config.default_stream !== 'all'
    ) {
      return undefined;
    }

    let cancelled = false;
    setDataLoading(true);
    const q = { days, stream };
    Promise.all([
      analyticsService.trafficOverview(q),
      analyticsService.trafficSources(q),
      analyticsService.trafficLeads(q),
    ])
      .then(([ov, src, ld]) => {
        if (cancelled) return;
        setOverview(ov);
        setSources(src);
        setLeads(ld);
        setUnconfigured(Boolean(ov?._unconfigured || src?._unconfigured || ld?._unconfigured));
        setUnconfiguredReason(ov?._reason || src?._reason || ld?._reason || '');
      })
      .catch(() => {
        if (!cancelled) {
          setOverview(null);
          setSources(null);
          setLeads(null);
        }
      })
      .finally(() => {
        if (!cancelled) setDataLoading(false);
      });
    return () => { cancelled = true; };
  }, [days, stream, streamInitialized, config, configLoading]);

  if (loading && !overview && !config) {
    return (
      <div className="p-6 text-center">
        <Loader />
        <p className="text-sm text-muted" style={{ marginTop: '0.75rem' }}>Loading traffic analytics…</p>
      </div>
    );
  }

  const totals = overview?.totals || {};
  const comparison = overview?.comparison || {};
  const periodLabel = `${days}d`;
  const allMetricsZero = !unconfigured && !dataLoading && (
    (totals.sessions ?? 0) === 0
    && (totals.users ?? 0) === 0
    && (totals.pageviews ?? 0) === 0
    && (overview?.trend || []).length === 0
  );
  const zeroDataHint = (() => {
    if (!allMetricsZero || !currentStreamConfig) return null;
    if (currentStreamConfig.requires_dedicated_property && !currentStreamConfig.dedicated_property) {
      return 'GA4 API is connected but Reach is not using this product\'s GA4 property. Set GA4_PROPERTY_ID_SAAS_* in api/.env (Property column should show the numeric ID from GA4 Admin, not the shared SaaS hub).';
    }
    if (currentStreamConfig.path_filter_active) {
      return 'GA4 API is connected but page-path filters may not match this app\'s routes. Prefer a dedicated GA4_PROPERTY_ID_SAAS_* for products on their own subdomain.';
    }
    if (currentStreamConfig.property_id) {
      return `GA4 API is connected (property ${currentStreamConfig.property_id}) but reports are empty. If GA4 Realtime shows traffic, wait up to an hour for cached reports to refresh, or confirm the service account has Viewer on this exact property.`;
    }
    return 'GA4 API is connected but no sessions were returned for this date range.';
  })();
  const trend = (overview?.trend || []).map((row) => ({
    date: row.date,
    label: formatGaDate(row.date),
    value: row.sessions,
  }));
  const channels = (sources?.channels || []).map((ch) => ({
    label: ch.channel,
    value: ch.sessions,
  }));
  // Drill into the explorer for this window and stream; nothing to open when GA4 is not answering.
  const live = !unconfigured && !dataLoading;
  const explore = (params) => trafficExplorerHref({ days, stream, ...params });
  const kpiTo = (value, params) => (live && Number(value) > 0 ? explore(params) : undefined);

  const leadEvents = leads && !leads._unconfigured
    ? Object.entries(leads)
        .filter(([key]) => !key.startsWith('_'))
        .map(([key, count]) => ({
          key,
          name: EVENT_LABELS[key] || key,
          open_count: count,
        }))
    : [];

  return (
    <div>
      <div className="page-header flex items-center justify-between" style={{ flexWrap: 'wrap', gap: '0.75rem' }}>
        <div>
          <h1>Traffic Analytics</h1>
          <p className="text-sm text-muted">
            Web traffic from Google Analytics 4
            {!dataLoading && (
              <> · Viewing <strong>{selectedStreamLabel}</strong></>
            )}
          </p>
        </div>
        <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
          <select
            className="input"
            value={stream}
            onChange={(e) => setStream(e.target.value)}
            disabled={dataLoading}
            style={{ width: 'auto', maxWidth: '100%' }}
            aria-busy={dataLoading}
          >
            {STREAM_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </select>
          <select
            className="input"
            value={days}
            onChange={(e) => setDays(Number(e.target.value))}
            disabled={dataLoading}
            style={{ width: 'auto' }}
            aria-busy={dataLoading}
          >
            {DAYS_OPTIONS.map((d) => (
              <option key={d} value={d}>Last {d} days</option>
            ))}
          </select>
        </div>
      </div>

      {dataLoading && (
        <div
          className="progress-bar-indeterminate"
          role="progressbar"
          aria-label={`Loading ${selectedStreamLabel}`}
        >
          <div className="progress-bar-indeterminate__bar" />
        </div>
      )}

      {dataLoading && (
        <div
          className="flex items-center gap-2 text-sm text-muted"
          style={{ marginBottom: '1rem' }}
        >
          <Loader />
          <span>Loading GA4 data for {selectedStreamLabel}…</span>
        </div>
      )}

      {unconfigured && !dataLoading && (
        <div
          className="card"
          style={{
            marginBottom: '1rem',
            padding: '0.75rem 1rem',
            borderColor: 'var(--color-warning)',
            background: 'color-mix(in srgb, var(--color-warning) 8%, transparent)',
          }}
        >
          <div className="flex items-start gap-2 text-sm">
            <AlertCircle size={16} style={{ color: 'var(--color-warning)', flexShrink: 0, marginTop: 2 }} />
            <span>
              <strong>{selectedStreamLabel}:</strong>{' '}
              {unconfiguredReason || 'GA4 is not configured for this stream. Set GA4_PROPERTY_ID_* and GOOGLE_SERVICE_ACCOUNT_JSON_* in api/.env on the server.'}
            </span>
          </div>
        </div>
      )}

      {currentStreamLive && !unconfigured && !dataLoading && (
        <div
          className="card"
          style={{
            marginBottom: '1rem',
            padding: '0.75rem 1rem',
            borderColor: 'var(--color-success)',
            background: 'color-mix(in srgb, var(--color-success) 8%, transparent)',
          }}
        >
          <div className="flex items-center gap-2 text-sm">
            <CheckCircle2 size={16} style={{ color: 'var(--color-success)' }} />
            <span>
              Live GA4 data — <strong>{selectedStreamLabel}</strong>
              {currentStreamConfig?.property_id && (
                <span className="text-muted"> · Property {currentStreamConfig.property_id}</span>
              )}
            </span>
          </div>
        </div>
      )}

      {zeroDataHint && (
        <div
          className="card"
          style={{
            marginBottom: '1rem',
            padding: '0.75rem 1rem',
            borderColor: 'var(--color-warning)',
            background: 'color-mix(in srgb, var(--color-warning) 8%, transparent)',
          }}
        >
          <div className="flex items-start gap-2 text-sm">
            <AlertCircle size={16} style={{ color: 'var(--color-warning)', flexShrink: 0, marginTop: 2 }} />
            <span>{zeroDataHint}</span>
          </div>
        </div>
      )}

      {config && !config.ready && !configLoading && (
        <div style={{ marginBottom: '1.5rem' }}>
          <Card title="GA4 setup checklist">
          <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
            {(config.checklist || []).map((item) => (
              <li
                key={item.id}
                className="flex items-start gap-2 text-sm"
                style={{ marginBottom: '0.5rem' }}
              >
                {item.done ? (
                  <CheckCircle2 size={16} style={{ color: 'var(--color-success)', flexShrink: 0, marginTop: 2 }} />
                ) : (
                  <AlertCircle size={16} style={{ color: 'var(--color-warning)', flexShrink: 0, marginTop: 2 }} />
                )}
                <span>
                  {item.label}
                  {item.note && (
                    <span className="text-muted" style={{ display: 'block', marginTop: 2 }}>{item.note}</span>
                  )}
                </span>
              </li>
            ))}
          </ul>
          {(config.streams || []).length > 0 && (
            <div style={{ marginTop: '1rem', overflowX: 'auto' }}>
              <table className="table" style={{ width: '100%', fontSize: '0.8125rem' }}>
                <thead>
                  <tr>
                    <th>Stream</th>
                    <th>Property</th>
                    <th>API</th>
                  </tr>
                </thead>
                <tbody>
                  {(config.streams || []).map((s) => (
                    <tr key={s.id}>
                      <td>{s.label || baseStreamLabel(s.id)}</td>
                      <td className="text-muted">{s.property_id || '—'}</td>
                      <td>
                        {s.api_ok ? (
                          <span style={{ color: 'var(--color-success)' }}>Live</span>
                        ) : s.property_id ? (
                          <span style={{ color: 'var(--color-warning)' }}>Not connected</span>
                        ) : (
                          <span className="text-muted">Not set</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          </Card>
        </div>
      )}

      <div
        style={{
          opacity: dataLoading ? 0.45 : 1,
          pointerEvents: dataLoading ? 'none' : 'auto',
          transition: 'opacity 150ms ease',
        }}
      >
        <div className="grid grid-4" style={{ marginBottom: '1.5rem' }}>
          <KPICard
            title="Sessions"
            value={totals.sessions ?? 0}
            icon={Activity}
            color="var(--color-primary)"
            comparison={comparison.sessions}
            periodLabel={periodLabel}
            to={kpiTo(totals.sessions, { by: 'channel', sort: 'sessions' })}
          />
          <KPICard
            title="Users"
            value={totals.users ?? 0}
            icon={Users}
            color="var(--color-success)"
            comparison={comparison.users}
            periodLabel={periodLabel}
            to={kpiTo(totals.users, { by: 'channel', sort: 'users' })}
          />
          <KPICard
            title="Pageviews"
            value={totals.pageviews ?? 0}
            icon={Eye}
            color="var(--color-warning)"
            comparison={comparison.pageviews}
            periodLabel={periodLabel}
            to={kpiTo(totals.pageviews, { by: 'page', sort: 'pageviews' })}
          />
          <KPICard
            title="Bounce rate"
            value={`${totals.bounce_rate ?? 0}%`}
            icon={MousePointerClick}
            color="var(--color-danger)"
            comparison={comparison.bounce_rate}
            invert
            periodLabel={periodLabel}
            to={kpiTo(totals.sessions, { by: 'page', sort: 'bounce_rate' })}
          />
        </div>

        <div className="grid grid-2" style={{ marginBottom: '1.5rem', gap: '1.5rem' }}>
          <Card title="Sessions trend">
            {trend.length >= 2 ? (
              <LineChart
                data={trend}
                labelKey="label"
                valueKey="value"
                onSelect={live ? (row) => navigate(explore({ date: row.date, by: 'channel' })) : undefined}
              />
            ) : (
              <p className="text-sm text-muted">
                {dataLoading ? 'Loading…' : 'Not enough trend data'}
              </p>
            )}
          </Card>
          <Card title="Traffic sources">
            {channels.length > 0 ? (
              <PieChart
                data={channels}
                labelKey="label"
                valueKey="value"
                onSelect={live ? (row) => navigate(explore({ channel: row.label, by: 'source' })) : undefined}
              />
            ) : (
              <p className="text-sm text-muted">
                {dataLoading ? 'Loading…' : 'No source data'}
              </p>
            )}
          </Card>
        </div>

        <div className="grid grid-2" style={{ gap: '1.5rem' }}>
          <Card title="Top pages">
            {(overview?.top_pages || []).length > 0 ? (
              <div style={{ overflowX: 'auto' }}>
                <table className="table" style={{ width: '100%', fontSize: '0.875rem' }}>
                  <thead>
                    <tr>
                      <th>Page</th>
                      <th style={{ textAlign: 'right' }}>Views</th>
                    </tr>
                  </thead>
                  <tbody>
                    {overview.top_pages.map((page) => {
                      // A combined overview lists each site's pages: drill into that page's own property.
                      const href = live && page.path
                        ? trafficExplorerHref({ days, stream: page.stream || stream, page: page.path, by: 'date' })
                        : null;
                      return (
                        <tr
                          key={`${page.site || ''}-${page.path}`}
                          className={href ? 'is-drillable' : undefined}
                          onClick={href ? () => navigate(href) : undefined}
                        >
                          <td>
                            {href ? (
                              <Link
                                to={href}
                                className="drill-link"
                                style={{ fontWeight: 500 }}
                                onClick={(e) => e.stopPropagation()}
                                title={`Traffic for ${page.path}`}
                              >
                                {page.title || page.path}
                              </Link>
                            ) : (
                              <div style={{ fontWeight: 500 }}>{page.title || page.path}</div>
                            )}
                            <div className="text-sm text-muted">{page.path}</div>
                          </td>
                          <td style={{ textAlign: 'right' }}>{page.pageviews}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-sm text-muted">
                {dataLoading ? 'Loading…' : 'No page data'}
              </p>
            )}
          </Card>
          <Card title="Lead events (GA4)">
            {leadEvents.length > 0 ? (
              <>
                <BarChart
                  data={leadEvents}
                  labelKey="name"
                  valueKey="open_count"
                  color="var(--color-primary)"
                  onSelect={live ? (row) => { if (row.open_count > 0) navigate(explore({ event: row.key, by: 'date' })); } : undefined}
                />
                {leadEvents.some((e) => LEAD_SUBMIT_EVENTS.has(e.key) && e.open_count > 0) && (
                  <p className="text-sm" style={{ marginTop: '0.5rem' }}>
                    <Link to={drillHref(ROUTES.LEADS, { days })}>Leads Reach captured in the last {days} days ›</Link>
                  </p>
                )}
              </>
            ) : (
              <p className="text-sm text-muted">
                {dataLoading
                  ? 'Loading…'
                  : unconfigured
                    ? 'Configure GA4 for this stream to see lead events'
                    : 'No lead events recorded in this period'}
              </p>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
