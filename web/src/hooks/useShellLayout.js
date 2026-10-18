import { useCallback, useEffect, useState } from 'react';

/**
 * Viewport → shell mode. Phone: off-canvas drawer; tablet: icon rail;
 * desktop: full sidebar (the user may collapse it to the rail).
 * Breakpoints match the CSS in index.css (`.reach-shell--*`).
 */
export const SHELL_QUERIES = {
  phone: '(max-width: 767.98px)',
  tablet: '(min-width: 768px) and (max-width: 1199.98px)',
};

export const SIDEBAR_COLLAPSED_KEY = 'reach.shell.sidebarCollapsed';

function query(q) {
  try {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      ? window.matchMedia(q)
      : null;
  } catch {
    return null;
  }
}

function currentMode() {
  if (query(SHELL_QUERIES.phone)?.matches) return 'phone';
  if (query(SHELL_QUERIES.tablet)?.matches) return 'tablet';
  return 'desktop';
}

/** @returns {'phone'|'tablet'|'desktop'} */
export function useViewportMode() {
  const [mode, setMode] = useState(currentMode);
  useEffect(() => {
    const lists = [query(SHELL_QUERIES.phone), query(SHELL_QUERIES.tablet)].filter(Boolean);
    const update = () => setMode(currentMode());
    for (const l of lists) {
      if (typeof l.addEventListener === 'function') l.addEventListener('change', update);
      else if (typeof l.addListener === 'function') l.addListener(update);
    }
    update();
    return () => {
      for (const l of lists) {
        if (typeof l.removeEventListener === 'function') l.removeEventListener('change', update);
        else if (typeof l.removeListener === 'function') l.removeListener(update);
      }
    };
  }, []);
  return mode;
}

export function readCollapsedPref() {
  try {
    return window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
  } catch {
    return false;
  }
}

function writeCollapsedPref(value) {
  try {
    window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, value ? '1' : '0');
  } catch {
    /* private mode / blocked storage: the preference just isn't remembered */
  }
}

/** The desktop "collapse to icon rail" preference, remembered per browser. */
export function useCollapsedPref() {
  const [collapsed, setCollapsedState] = useState(readCollapsedPref);
  const setCollapsed = useCallback((next) => {
    setCollapsedState((prev) => {
      const value = typeof next === 'function' ? next(prev) : next;
      writeCollapsedPref(value);
      return value;
    });
  }, []);
  return [collapsed, setCollapsed];
}
