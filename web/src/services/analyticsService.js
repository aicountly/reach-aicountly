import { api } from './api';

export const analyticsService = {
  summary:   () => api.get('v1/analytics/summary'),
  traffic:   () => api.get('v1/analytics/traffic'),
  providers: () => api.get('v1/analytics/providers'),
};
