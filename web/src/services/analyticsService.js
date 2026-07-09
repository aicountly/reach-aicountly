import { api } from './api';

function withQuery(path, params = {}) {
  const entries = Object.entries(params).filter(([, v]) => v != null && v !== '');
  if (entries.length === 0) return path;
  const qs = new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString();
  return `${path}?${qs}`;
}

export const analyticsService = {
  summary: () => api.get('v1/analytics/summary'),
  trafficOverview: (params) => api.get(withQuery('v1/analytics/traffic/overview', params)),
  trafficSources: (params) => api.get(withQuery('v1/analytics/traffic/sources', params)),
  trafficLeads: (params) => api.get(withQuery('v1/analytics/traffic/leads', params)),
  trafficConfigStatus: () => api.get('v1/analytics/traffic/config-status'),
  providers: () => api.get('v1/analytics/providers'),
};
