import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Outlet, useLocation } from 'react-router-dom';
import { AuthContext } from '../context/AuthContext';
import { LEGACY_REDIRECTS, PLANNED_ROUTES } from '../constants/navManifest';

// Pages stay in their loading state: no request ever answers.
vi.mock('../services/api', () => {
  const pending = () => new Promise(() => {});
  const api = { get: pending, getPage: pending, post: pending, put: pending, patch: pending, delete: pending, upload: pending };
  return { api, default: api };
});
// The shell is covered by its own tests; here it only reports where routing landed.
vi.mock('../components/layout/ReachLayout', () => ({
  ReachLayout: () => {
    const l = useLocation();
    return <><div data-testid="where">{l.pathname}</div><div data-testid="query">{l.search}</div><Outlet /></>;
  },
}));

import App from '../App';

const originalFetch = globalThis.fetch;
beforeAll(() => { globalThis.fetch = () => new Promise(() => {}); });
afterAll(() => { globalThis.fetch = originalFetch; });

function renderAt(path) {
  const value = {
    user: { id: 1, email: 'root@aicountly.org', role: 'super_admin' },
    loading: false, ssoPending: false, isAuthenticated: true,
    permissions: new Set(['*']), hasPermission: () => true,
    logout: async () => {}, retryAuth: async () => {},
  };
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthContext.Provider value={value}><App /></AuthContext.Provider>
    </MemoryRouter>,
  );
}

describe('App routing after the 2026-10 IA revamp', () => {
  it.each(LEGACY_REDIRECTS.map((r) => [r.from, r.to]))('%s redirects to %s', (from, to) => {
    renderAt(from);
    // A redirect into a planned page lands there (placeholder until that stream merges).
    expect(screen.getByTestId('where')).toHaveTextContent(new RegExp(`^${to}`));
  });

  it('keeps the query string through a redirect, so drill-down filters survive', () => {
    const r = LEGACY_REDIRECTS.find((x) => !x.to.includes('?'));
    renderAt(`${r.from}?status=failed&channel=linkedin`);
    expect(screen.getByTestId('where')).toHaveTextContent(new RegExp(`^${r.to}`));
    expect(screen.getByTestId('query')).toHaveTextContent('?status=failed&channel=linkedin');
  });

  it.each(PLANNED_ROUTES.map((r) => [r.path]))('%s renders the "coming in this release" placeholder', (path) => {
    renderAt(path);
    expect(screen.getByTestId('where')).toHaveTextContent(path);
    expect(screen.getByTestId('coming-soon')).toHaveTextContent('Coming in this release');
  });

  it('/social opens the Social & Ads hub on its Accounts tab', () => {
    renderAt('/social');
    expect(screen.getByTestId('where')).toHaveTextContent('/social/accounts');
    expect(screen.getByRole('heading', { level: 1, name: 'Social & Ads' })).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Social & Ads navigation' })).toBeInTheDocument();
  });

  it('/social/ads is the paid-ads register and says honestly it is not connected to any ads API', () => {
    renderAt('/social/ads');
    expect(screen.getByTestId('ads-not-connected')).toHaveTextContent('Not connected to the Google Ads, Meta or LinkedIn ads APIs');
  });

  it('Leads pages share one Leads & Engage area with tabs', () => {
    renderAt('/leads/outcomes');
    expect(screen.getByRole('heading', { level: 1, name: 'Leads & Engage' })).toBeInTheDocument();
    const tabs = screen.getByRole('navigation', { name: 'Leads navigation' });
    expect(tabs).toHaveTextContent('Capture');
    expect(tabs).toHaveTextContent('Engage push');
    expect(tabs).toHaveTextContent('Outcomes');
  });

  it('Analytics and Daily reports share one area', () => {
    renderAt('/reports');
    expect(screen.getByRole('heading', { level: 1, name: 'Analytics & reports' })).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Analytics navigation' })).toHaveTextContent('Daily reports');
  });

  it('an unknown path still falls back to the Command centre', () => {
    renderAt('/no-such-page');
    expect(screen.getByTestId('where')).toHaveTextContent(/^\/$/);
  });
});
