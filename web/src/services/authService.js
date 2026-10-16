import { api } from './api';

export const authService = {
  controllerSso: (token) => api.post('v1/auth/controller-sso', { token }),
  consoleSession: () => api.post('v1/auth/console-session'),
  logout: () => api.post('v1/auth/logout'),
  me: () => api.get('v1/me'),
  // Re-checks the caller's role with Console and re-issues the token.
  refresh: (token) => api.post('v1/auth/refresh', { token }),
};
