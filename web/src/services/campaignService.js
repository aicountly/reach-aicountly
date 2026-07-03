import { api } from './api';

export const campaignService = {
  list:    (params) => api.get('v1/campaigns', params),
  get:     (id)     => api.get(`v1/campaigns/${id}`),
  create:  (body)   => api.post('v1/campaigns', body),
  update:  (id, b)  => api.put(`v1/campaigns/${id}`, b),
  archive: (id)     => api.delete(`v1/campaigns/${id}`),
  approve: (id)     => api.post(`v1/campaigns/${id}/approve`),
  setStatus:(id, status) => api.post(`v1/campaigns/${id}/status`, { status }),
};
