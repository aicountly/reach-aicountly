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
  // SEO Tracker (DataForSEO SERP positions vs competitors)
  getTracker:          (params) => api.get('v1/seo/tracker', params),
  runTracker:          (body)   => api.post('v1/seo/tracker/run', body),
  createTrackedKeyword:(body)   => api.post('v1/seo/tracker/keywords', body),
  updateTrackedKeyword:(id, b)  => api.put(`v1/seo/tracker/keywords/${id}`, b),
  // Records behind the SEO Command Centre counts.
  getOverview:            ()           => api.get('v1/seo/overview'),
  listBacklinkSnapshots:  ()           => api.get('v1/seo/backlinks'),
  getBacklinkSnapshot:    (id, params) => api.get(`v1/seo/backlinks/${id}`, params),
  listIndexNowSubmissions:(params)     => api.get('v1/seo/indexnow/submissions', params),
  getIndexNowSubmission:  (id)         => api.get(`v1/seo/indexnow/submissions/${id}`),
  listBlogIndexing:       (params)     => api.get('v1/seo/indexing', params),
  // Sitemap entries answer { data, count, meta } — keep the envelope for meta.
  listSitemapEntries:     (snapshotId, params) => api.getPage(`v1/intelligence/sitemap/${snapshotId}/entries`, params),
};
