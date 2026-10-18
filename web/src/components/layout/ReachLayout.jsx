import { useCallback, useEffect, useRef, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Header } from './Header';
import { Sidebar } from './Sidebar';
import { CommandPalette } from './CommandPalette';
import { ReachCountsProvider } from '../../context/ReachCountsContext';
import { useCollapsedPref, useViewportMode } from '../../hooks/useShellLayout';

/**
 * App shell. Desktop (≥1200px): full sidebar the user may collapse to an
 * icon rail (remembered). Tablet (768–1199px): icon rail, expandable until
 * the next navigation. Phone (<768px): off-canvas drawer behind the header's
 * menu button. Ctrl/⌘-K opens the "go to" palette anywhere.
 */
export function ReachLayout() {
  const mode = useViewportMode();
  const [collapsed, setCollapsed] = useCollapsedPref();
  const [navOpen, setNavOpen] = useState(false); // phone drawer / tablet expanded
  const [paletteOpen, setPaletteOpen] = useState(false);
  const menuButtonRef = useRef(null);
  const { pathname } = useLocation();

  // Navigating closes the drawer (and re-collapses the tablet rail).
  useEffect(() => { setNavOpen(false); }, [pathname]);
  useEffect(() => { setNavOpen(false); }, [mode]);

  // Ctrl/⌘-K toggles the palette from anywhere, including inside inputs.
  useEffect(() => {
    const onKey = (e) => {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && String(e.key).toLowerCase() === 'k') {
        e.preventDefault();
        setPaletteOpen((o) => !o);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const closeDrawer = useCallback(() => {
    setNavOpen(false);
    menuButtonRef.current?.focus();
  }, []);

  let variant;
  if (mode === 'phone') variant = 'drawer';
  else if (mode === 'tablet') variant = navOpen ? 'full' : 'rail';
  else variant = collapsed ? 'rail' : 'full';

  const toggleRail = mode === 'tablet'
    ? () => setNavOpen((o) => !o)
    : () => setCollapsed((c) => !c);
  const railToggleLabel = variant === 'rail' ? 'Expand sidebar' : 'Collapse sidebar';

  return (
    <ReachCountsProvider>
      <a className="reach-skip-link" href="#reach-main">Skip to content</a>
      <div className={`reach-shell reach-shell--${mode} reach-shell--nav-${variant}${navOpen ? ' reach-shell--nav-open' : ''}`}>
        <Sidebar
          variant={variant}
          open={mode === 'phone' && navOpen}
          onClose={closeDrawer}
          onToggleRail={mode === 'phone' ? undefined : toggleRail}
          railToggleLabel={railToggleLabel}
        />
        {mode === 'phone' && navOpen && (
          <div className="reach-shell__backdrop" onClick={closeDrawer} aria-hidden="true" data-testid="nav-backdrop" />
        )}
        <div className="reach-shell__main">
          <Header
            mode={mode}
            navOpen={navOpen}
            onOpenNav={() => setNavOpen((o) => !o)}
            onOpenPalette={() => setPaletteOpen(true)}
            menuButtonRef={menuButtonRef}
          />
          <main className="reach-shell__content" id="reach-main" tabIndex={-1}>
            <Outlet />
          </main>
        </div>
      </div>
      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </ReachCountsProvider>
  );
}
