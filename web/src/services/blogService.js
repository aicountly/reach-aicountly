import { api } from './api';

export const blogService = {
  list:     (params) => api.get('v1/blog/posts', params),
  get:      (id)     => api.get(`v1/blog/posts/${id}`),
  create:   (body)   => api.post('v1/blog/posts', body),
  update:   (id, b)  => api.put(`v1/blog/posts/${id}`, b),
  archive:  (id)     => api.delete(`v1/blog/posts/${id}`),
  transition:(id, status) => api.post(`v1/blog/posts/${id}/transition`, { status }),
  approve:  (id)     => api.post(`v1/blog/posts/${id}/approve`),
  reject:   (id, note) => api.post(`v1/blog/posts/${id}/reject`, { note }),
  publish:  (id)     => api.post(`v1/blog/posts/${id}/publish`),
  versions: (id)     => api.get(`v1/blog/posts/${id}/versions`),
  version:  (id, v)  => api.get(`v1/blog/posts/${id}/versions/${v}`),
};
