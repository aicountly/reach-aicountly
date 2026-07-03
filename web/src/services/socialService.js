import { api } from './api';

export const socialService = {
  list:      (params) => api.get('v1/social/posts', params),
  get:       (id)     => api.get(`v1/social/posts/${id}`),
  create:    (body)   => api.post('v1/social/posts', body),
  update:    (id, b)  => api.put(`v1/social/posts/${id}`, b),
  archive:   (id)     => api.delete(`v1/social/posts/${id}`),
  approve:   (id)     => api.post(`v1/social/posts/${id}/approve`),
  reject:    (id)     => api.post(`v1/social/posts/${id}/reject`),
  markPosted:(id, externalId) => api.post(`v1/social/posts/${id}/mark-posted`, { external_post_id: externalId }),
  queue:     () => api.get('v1/social/queue'),
};
