import { api } from './api';

export const emailService = {
  list:    (params) => api.get('v1/email/campaigns', params),
  get:     (id)     => api.get(`v1/email/campaigns/${id}`),
  create:  (body)   => api.post('v1/email/campaigns', body),
  update:  (id, b)  => api.put(`v1/email/campaigns/${id}`, b),
  markSent:(id, stats) => api.post(`v1/email/campaigns/${id}/mark-sent`, { stats }),
};
