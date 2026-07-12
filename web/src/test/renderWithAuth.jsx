import { MemoryRouter } from 'react-router-dom';
import { render } from '@testing-library/react';
import { AuthContext } from '../context/AuthContext';

/**
 * Render a component tree with a synthetic AuthContext value.
 * Bypasses the real bootstrap flow (fetch, localStorage, redirects).
 */
export function renderWithAuth(ui, { auth = {}, route = '/' } = {}) {
  const permissions = new Set(auth.permissions || []);
  const value = {
    user: auth.user ?? null,
    loading: auth.loading ?? false,
    ssoPending: auth.ssoPending ?? false,
    gateReason: auth.gateReason ?? null,
    gateMessage: auth.gateMessage ?? '',
    logout: auth.logout ?? (async () => {}),
    retryAuth: auth.retryAuth ?? (async () => {}),
    isAuthenticated: auth.isAuthenticated ?? !!auth.user,
    permissions,
    hasPermission: (p) => permissions.has('*') || permissions.has(p),
    ...auth.overrides,
  };
  return render(
    <MemoryRouter initialEntries={[route]}>
      <AuthContext.Provider value={value}>{ui}</AuthContext.Provider>
    </MemoryRouter>,
  );
}
