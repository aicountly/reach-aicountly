import { api } from './api';

export const landingService = {
  list:   (params) => api.get('v1/landing-pages', params),
  get:    (id)     => api.get(`v1/landing-pages/${id}`),
  create: (body)   => api.post('v1/landing-pages', body),
  update: (id, b)  => api.put(`v1/landing-pages/${id}`, b),
  archive:(id)     => api.delete(`v1/landing-pages/${id}`),
};
