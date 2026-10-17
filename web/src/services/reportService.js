import { api } from './api';

/** Daily analytics report archive (08:00 IST email). */
export const reportService = {
  listDaily:   (params) => api.get('v1/reports/daily', params),
  getDaily:    (id)     => api.get(`v1/reports/daily/${id}`),
  previewDaily:(params) => api.get('v1/reports/daily/preview', params),
  sendDaily:   (body)   => api.post('v1/reports/daily/send', body),
};
