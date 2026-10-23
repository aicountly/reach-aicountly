import { api } from './api';

export const dashboardService = {
  summary: () => api.get('v1/dashboard/summary'),
  counts:  () => api.get('v1/dashboard/counts'),
  commandCentre: (params) => api.get('v1/dashboard/command-centre', params),
  /** Drill-down behind a "What the bot did" counter: { kind, window, page, per_page }. */
  commandCentreActivity: (params) => api.get('v1/dashboard/command-centre/activity', params),
};
