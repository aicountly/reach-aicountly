import { api } from './api';

export const botService = {
  getSettings:   () => api.get('v1/bot/settings'),
  updateSettings:(body) => api.put('v1/bot/settings', body),
  dispatch:      (action, payload) => api.post('v1/bot/dispatch', { action, payload }),
  queue:         (params) => api.get('v1/bot/queue', params),
  queueItem:     (id) => api.get(`v1/bot/queue/${id}`),
  approveItem:   (id) => api.post(`v1/bot/queue/${id}/approve`),
  rejectItem:    (id, note) => api.post(`v1/bot/queue/${id}/reject`, { note }),
  reports:       (params) => api.get('v1/bot/reports', params),
  report:        (id) => api.get(`v1/bot/reports/${id}`),
};
