import { api } from './api';

export const seoService = {
  listPlans:    (params) => api.get('v1/seo/plans', params),
  getPlan:      (id)     => api.get(`v1/seo/plans/${id}`),
  createPlan:   (body)   => api.post('v1/seo/plans', body),
  updatePlan:   (id, b)  => api.put(`v1/seo/plans/${id}`, b),
  archivePlan:  (id)     => api.delete(`v1/seo/plans/${id}`),
  listKeywords: (params) => api.get('v1/seo/keywords', params),
  createKeyword:(body)   => api.post('v1/seo/keywords', body),
  updateKeyword:(id, b)  => api.put(`v1/seo/keywords/${id}`, b),
  archiveKeyword:(id)    => api.delete(`v1/seo/keywords/${id}`),
};
