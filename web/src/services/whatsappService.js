import { api } from './api';

export const whatsappService = {
  list:    (params) => api.get('v1/whatsapp/campaigns', params),
  get:     (id)     => api.get(`v1/whatsapp/campaigns/${id}`),
  create:  (body)   => api.post('v1/whatsapp/campaigns', body),
  update:  (id, b)  => api.put(`v1/whatsapp/campaigns/${id}`, b),
  markSent:(id, stats) => api.post(`v1/whatsapp/campaigns/${id}/mark-sent`, { stats }),
};
