import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Search, BarChart3, Zap, Eye, Link2, Users2, CheckCircle, AlertCircle } from 'lucide-react';
import {
  getAttributionOverview,
  listCompetitors,
  listConnectors,
  listVisibilityRuns,
} from '../../services/intelligenceService.js';

const LINKS = [
  { to: '/intelligence/search', label: 'Search Intelligence', desc: 'GSC queries, pages, and performance', icon: Search },
  { to: '/intelligence/content', label: 'Content Performance', desc: 'GA4 content analytics', icon: BarChart3 },
  { to: '/intelligence/indexnow', label: 'IndexNow', desc: 'URL submission operations', icon: Zap },
  { to: '/intelligence/visibility', label: 'AI Visibility', desc: 'Prompt runs and brand mentions', icon: Eye },
  { to: '/intelligence/attribution', label: 'Attribution', desc: 'First/last-touch conversion links', icon: Link2 },
  { to: '/intelligence/competitors', label: 'Competitors', desc: 'Tracked competitor observations', icon: Users2 },
];

export default function IntelligenceOverviewPage() {
  const [tiles, setTiles] = useState([
    { label: 'Attributed Conversions', value: '—', sub: 'Live attribution', icon: Link2 },
    { label: 'AI Visibility Runs', value: '—', sub: 'Recent runs', icon: Eye },
    { label: 'Competitors Tracked', value: '—', sub: 'Active monitoring', icon: Users2 },
    { label: 'Connectors', value: '—', sub: 'Configured integrations', icon: Zap },
  ]);
  const [connectors, setConnectors] = useState([]);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    Promise.allSettled([
      getAttributionOverview(),
      listVisibilityRuns(),
      listCompetitors(),
      listConnectors(),
    ]).then(([attrRes, runsRes, compsRes, connRes]) => {
      if (cancelled) return;

      const next = [...tiles];
      if (attrRes.status === 'fulfilled') {
        const s = attrRes.value?.stats || {};
        next[0] = {
          ...next[0],
          value: String(s.attributed ?? 0),
          sub: `${s.attribution_rate ?? 0}% attribution rate`,
        };
      }
      if (runsRes.status === 'fulfilled') {
        const runs = Array.isArray(runsRes.value) ? runsRes.value : (runsRes.value?.runs || runsRes.value?.data || []);
        next[1] = { ...next[1], value: String(runs.length), sub: 'Loaded from API' };
      }
      if (compsRes.status === 'fulfilled') {
        const comps = Array.isArray(compsRes.value) ? compsRes.value : (compsRes.value?.competitors || compsRes.value?.data || []);
        next[2] = { ...next[2], value: String(comps.length), sub: 'Active monitoring' };
      }
      if (connRes.status === 'fulfilled') {
        const conns = Array.isArray(connRes.value) ? connRes.value : (connRes.value?.connectors || connRes.value?.data || []);
        next[3] = { ...next[3], value: String(conns.length), sub: 'Configured integrations' };
        setConnectors(conns);
      }

      const failed = [attrRes, runsRes, compsRes, connRes].filter((r) => r.status === 'rejected');
      if (failed.length === 4) {
        setError(failed[0].reason?.message || 'Failed to load intelligence overview');
      }
      setTiles(next);
    });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div style={{ padding: '1.5rem' }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ fontSize: '1.35rem', fontWeight: 700, margin: 0 }}>Intelligence Control Centre</h1>
        <p className="text-sm text-muted" style={{ marginTop: '0.25rem' }}>
          Phase 8 — Search Intelligence, Attribution &amp; AI Visibility
        </p>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      <div className="grid grid-4" style={{ marginBottom: '1.25rem' }}>
        {tiles.map((c) => (
          <div key={c.label} className="stat-tile" style={{ display: 'flex', gap: '0.75rem', alignItems: 'flex-start' }}>
            <c.icon size={18} style={{ color: 'var(--color-primary)', marginTop: 2, flexShrink: 0 }} />
            <div>
              <div className="stat-tile__value">{c.value}</div>
              <div className="stat-tile__label">{c.label}</div>
              <div className="stat-tile__hint">{c.sub}</div>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-3" style={{ marginBottom: '1.25rem' }}>
        {LINKS.map((l) => (
          <Link
            key={l.to}
            to={l.to}
            className="card"
            style={{ display: 'block', padding: '1rem', textDecoration: 'none', color: 'inherit' }}
          >
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBottom: '0.35rem' }}>
              <l.icon size={16} style={{ color: 'var(--color-primary)' }} />
              <span style={{ fontWeight: 600 }}>{l.label}</span>
            </div>
            <p className="text-sm text-muted" style={{ margin: 0 }}>{l.desc}</p>
          </Link>
        ))}
      </div>

      <div className="card">
        <div className="card-header">Connector Health</div>
        <div className="card-body">
          {connectors.length === 0 ? (
            <p className="text-sm text-muted" style={{ margin: 0 }}>
              No connectors configured yet. Add connectors under Intelligence → Connectors.
            </p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
              {connectors.map((s) => {
                const name = s.name || s.connector_key || s.provider || `Connector #${s.id}`;
                const status = (s.health_status || s.status || 'unknown').toLowerCase();
                const healthy = status === 'healthy' || status === 'ok';
                return (
                  <div
                    key={s.id || name}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      padding: '0.4rem 0',
                      borderBottom: '1px solid var(--color-border)',
                    }}
                  >
                    <span className="text-sm">{name}</span>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.35rem' }}>
                      {healthy
                        ? <CheckCircle size={14} style={{ color: 'var(--color-success)' }} />
                        : <AlertCircle size={14} style={{ color: 'var(--color-warning)' }} />}
                      <span className="text-sm text-muted" style={{ textTransform: 'capitalize' }}>{status}</span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
