import { api } from './api';

export const dashboardService = {
  summary: () => api.get('v1/dashboard/summary'),
  counts:  () => api.get('v1/dashboard/counts'),
};
