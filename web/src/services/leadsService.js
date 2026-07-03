import { api } from './api';

export const leadsService = {
  list:       (params) => api.get('v1/leads', params),
  get:        (id)     => api.get(`v1/leads/${id}`),
  create:     (body)   => api.post('v1/leads', body),
  update:     (id, b)  => api.put(`v1/leads/${id}`, b),
  remove:     (id)     => api.delete(`v1/leads/${id}`),
  pushHistory:(params) => api.get('v1/engage-push', params),
  push:       (id)     => api.post(`v1/engage-push/${id}`),
  retry:      (id)     => api.post(`v1/engage-push/${id}/retry`),
};
