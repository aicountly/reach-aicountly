import { useCallback, useEffect, useRef, useState } from 'react';
import {
  CheckCircle,
  ExternalLink,
  Flame,
  Hammer,
  LayoutGrid,
  Megaphone,
  Shield,
  Ticket,
  Users,
} from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import * as controllerAccess from '../../services/controllerAccess';

const ICONS = {
  'layout-grid': LayoutGrid,
  shield: Shield,
  'check-circle': CheckCircle,
  flame: Flame,
  users: Users,
  megaphone: Megaphone,
  hammer: Hammer,
  ticket: Ticket,
};

function AppIcon({ name, size = 18 }) {
  const Icon = ICONS[name] || LayoutGrid;
  return <Icon size={size} color="#3b82f6" />;
}

const styles = {
  wrap: { position: 'relative' },
  button: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    width: '36px',
    height: '36px',
    borderRadius: '8px',
    border: '1px solid var(--color-border)',
    background: 'var(--color-surface)',
    cursor: 'pointer',
    color: '#64748b',
  },
  panel: {
    position: 'absolute',
    top: '100%',
    right: 0,
    marginTop: '8px',
    width: '320px',
    backgroundColor: '#ffffff',
    border: '1px solid #e2e8f0',
    borderRadius: '12px',
    boxShadow: '0 8px 24px rgba(0,0,0,0.12)',
    zIndex: 300,
    overflow: 'hidden',
  },
  header: {
    padding: '12px 16px',
    borderBottom: '1px solid #f1f5f9',
    fontSize: '13px',
    fontWeight: 600,
    color: '#334155',
  },
  grid: {
    display: 'grid',
    gridTemplateColumns: '1fr 1fr',
    gap: '8px',
    padding: '12px',
    maxHeight: '420px',
    overflowY: 'auto',
  },
  appTile: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'flex-start',
    gap: '6px',
    padding: '12px',
    borderRadius: '10px',
    border: '1px solid #e2e8f0',
    background: '#ffffff',
    cursor: 'pointer',
    textAlign: 'left',
    textDecoration: 'none',
    color: 'inherit',
  },
  appTileCurrent: { borderColor: '#3b82f6', backgroundColor: '#eff6ff' },
  appTileLocked: { opacity: 0.72, cursor: 'not-allowed', backgroundColor: '#f8fafc' },
  appName: { fontSize: '13px', fontWeight: 600, color: '#1e293b', margin: 0 },
  appSubtitle: { fontSize: '11px', color: '#64748b', margin: 0, lineHeight: 1.3 },
  empty: { padding: '16px', fontSize: '13px', color: '#64748b', textAlign: 'center' },
  error: {
    margin: '0 12px 12px',
    padding: '10px 12px',
    borderRadius: '8px',
    backgroundColor: '#fef2f2',
    color: '#b91c1c',
    fontSize: '12px',
  },
  hint: {
    margin: '0 12px 12px',
    padding: '10px 12px',
    borderRadius: '8px',
    backgroundColor: '#eff6ff',
    color: '#1d4ed8',
    fontSize: '11px',
  },
};

export function AppLauncher() {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [apps, setApps] = useState(user?.controller_apps ?? []);
  const [loading, setLoading] = useState(false);
  const [prefetching, setPrefetching] = useState(false);
  const [launchUrls, setLaunchUrls] = useState({});
  const [launchingCode, setLaunchingCode] = useState('');
  const [launchError, setLaunchError] = useState('');
  const ref = useRef(null);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    if (user?.controller_apps?.length) setApps(user.controller_apps);
  }, [user?.controller_apps]);

  const prefetchLaunchUrls = useCallback(async (appList) => {
    const targets = appList.filter(
      (app) => app?.code && !app.is_current && app.can_open !== false,
    );
    if (targets.length === 0) {
      setLaunchUrls({});
      return;
    }
    setPrefetching(true);
    try {
      const entries = await Promise.all(
        targets.map(async (app) => {
          try {
            const data = await controllerAccess.getSsoLaunchUrl(app.code);
            return [app.code, data?.redirect_url || ''];
          } catch {
            return [app.code, ''];
          }
        }),
      );
      setLaunchUrls(Object.fromEntries(entries));
    } finally {
      setPrefetching(false);
    }
  }, []);

  const handleToggle = async () => {
    const next = !open;
    setOpen(next);
    if (!next) return;
    setLaunchError('');
    setLoading(true);
    try {
      const data = await controllerAccess.getLauncherApps();
      const nextApps = data?.apps ?? [];
      setApps(nextApps);
      await prefetchLaunchUrls(nextApps);
    } catch {
      const fallbackApps = user?.controller_apps ?? [];
      setApps(fallbackApps);
      if (fallbackApps.length > 0) await prefetchLaunchUrls(fallbackApps);
    } finally {
      setLoading(false);
    }
  };

  const openInNewTab = async (app) => {
    if (!app?.code || app.is_current || app.can_open === false) return;
    setLaunchError('');
    setLaunchingCode(app.code);
    try {
      let redirectUrl = launchUrls[app.code];
      if (!redirectUrl) {
        const data = await controllerAccess.getSsoLaunchUrl(app.code);
        redirectUrl = data?.redirect_url;
      }
      if (!redirectUrl) throw new Error('Console did not return a launch URL.');
      window.open(redirectUrl, '_blank', 'noopener,noreferrer');
      setOpen(false);
    } catch (err) {
      setLaunchError(err?.message || 'Could not open controller app.');
    } finally {
      setLaunchingCode('');
    }
  };

  const handleTileClick = (event, app) => {
    if (!app?.code || app.is_current || app.can_open === false) {
      setOpen(false);
      return;
    }
    if (launchUrls[app.code]) {
      setOpen(false);
      return;
    }
    event.preventDefault();
    openInNewTab(app);
  };

  return (
    <div style={styles.wrap} ref={ref}>
      <button type="button" style={styles.button} title="Top Controller Apps" aria-label="Top Controller Apps" onClick={handleToggle}>
        <LayoutGrid size={20} />
      </button>
      {open && (
        <div style={styles.panel}>
          <div style={styles.header}>Top Controller Apps</div>
          {prefetching ? <div style={styles.hint}>Preparing secure launch links…</div> : null}
          {launchError ? <div style={styles.error}>{launchError}</div> : null}
          {loading && <div style={styles.empty}>Loading apps…</div>}
          {!loading && apps.length === 0 && <div style={styles.empty}>No controller apps assigned.</div>}
          {!loading && apps.length > 0 && (
            <div style={styles.grid}>
              {apps.map((app) => {
                const redirectUrl = launchUrls[app.code];
                const isLocked = app.can_open === false && !app.is_current;
                const isLaunchable = Boolean(app.code && !app.is_current && !isLocked);
                const TileTag = isLaunchable && redirectUrl ? 'a' : 'button';
                const tileProps =
                  isLaunchable && redirectUrl
                    ? { href: redirectUrl, target: '_blank', rel: 'noopener noreferrer', onClick: (e) => handleTileClick(e, app) }
                    : { type: 'button', disabled: Boolean(launchingCode) || app.is_current || isLocked, onClick: () => openInNewTab(app) };
                return (
                  <TileTag
                    key={app.code}
                    {...tileProps}
                    style={{
                      ...styles.appTile,
                      ...(app.is_current ? styles.appTileCurrent : {}),
                      ...(isLocked ? styles.appTileLocked : {}),
                    }}
                  >
                    <AppIcon name={app.icon} />
                    <p style={styles.appName}>
                      {app.name}
                      {!app.is_current && app.base_url ? (
                        <ExternalLink size={12} style={{ marginLeft: 4, verticalAlign: 'middle' }} />
                      ) : null}
                    </p>
                    {app.subtitle ? <p style={styles.appSubtitle}>{app.subtitle}</p> : null}
                    {app.is_current ? <p style={{ ...styles.appSubtitle, color: '#3b82f6' }}>Current app</p> : null}
                    {isLocked ? <p style={{ ...styles.appSubtitle, color: '#94a3b8' }}>No access</p> : null}
                  </TileTag>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
