import { api } from './api';

export const jobService = {
  list:   (params)      => api.get('v1/jobs', params),
  get:    (id, params)  => api.get(`v1/jobs/${id}`, params),
  retry:  (id)          => api.post(`v1/jobs/${id}/retry`),
  cancel: (id, reason)  => api.post(`v1/jobs/${id}/cancel`, reason ? { reason } : null),
};
