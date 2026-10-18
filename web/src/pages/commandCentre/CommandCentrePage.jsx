import { useCallback, useEffect, useRef, useState } from 'react';
import { Bot, LayoutDashboard, RefreshCw } from 'lucide-react';
import { dashboardService } from '../../services/dashboardService';
import { DashboardPage } from '../DashboardPage';
import { ActivityFeed, HealthStrip, Impact, Projections, Rankings, Recommendations } from './Sections';
import { ago } from './format';

/** Refresh cadence; the API caches each section on its own TTL. */
const REFRESH_MS = 60_000;

/**
 * The owner's AI bot command centre: health, what to do next, what the bot
 * did, impact, rankings, projections — then the pipeline tiles. Every section
 * renders on its own: a failed or empty source shows its own note and never
 * blanks the page.
 */
export function CommandCentrePage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [refreshing, setRefreshing] = useState(false);
  const mounted = useRef(true);

  const load = useCallback((fresh = false) => {
    setRefreshing(true);
    return dashboardService.commandCentre(fresh ? { fresh: 1 } : undefined)
      .then((d) => { if (mounted.current) { setData(d); setError(null); } })
      .catch((e) => { if (mounted.current) setError(e.message); })
      .finally(() => { if (mounted.current) setRefreshing(false); });
  }, []);

  useEffect(() => {
    mounted.current = true;
    load();
    const t = setInterval(() => load(), REFRESH_MS);
    return () => { mounted.current = false; clearInterval(t); };
  }, [load]);

  const d = data || {};

  return (
    <div className="cc-page">
      <div className="page-header">
        <div>
          <h1 className="flex items-center gap-2"><Bot size={22} aria-hidden="true" /> Command centre</h1>
          <p className="text-sm text-muted">What the marketing bot did, what it achieved, where we rank and what to do next — from live data only.</p>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-xs text-muted" aria-live="polite">{d.generated_at ? `Updated ${ago(d.generated_at)}` : ''}</span>
          <button type="button" className="btn btn-sm btn-secondary" onClick={() => load(true)} disabled={refreshing}>
            <RefreshCw size={14} aria-hidden="true" /> {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </div>

      {error && (
        <div className="alert alert-warning" role="alert">
          {data ? `Live refresh failed (${error}). Showing the last successful read.` : `The command centre could not load: ${error}`}
        </div>
      )}

      <HealthStrip section={d.health} />

      <div className="cc-grid">
        <div className="cc-grid__main">
          <Recommendations section={d.recommendations} onDone={() => load(true)} />
          <Impact section={d.impact} />
          <Projections section={d.projections} impact={d.impact} />
        </div>
        <div className="cc-grid__side">
          <ActivityFeed section={d.activity} />
          <Rankings section={d.rankings} />
        </div>
      </div>

      <h2 className="cc-subhead flex items-center gap-2"><LayoutDashboard size={16} aria-hidden="true" /> Pipeline</h2>
      <DashboardPage embedded />
    </div>
  );
}
