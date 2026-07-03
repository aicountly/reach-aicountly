import { api } from './api';

export const creativeBriefService = {
  list:    (params) => api.get('v1/creative-briefs', params),
  get:     (id)     => api.get(`v1/creative-briefs/${id}`),
  create:  (body)   => api.post('v1/creative-briefs', body),
  update:  (id, b)  => api.put(`v1/creative-briefs/${id}`, b),
  archive: (id)     => api.delete(`v1/creative-briefs/${id}`),
};
