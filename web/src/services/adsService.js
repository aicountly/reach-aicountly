import { api } from './api';

/**
 * Social & Ads → Ads (server: AdsController). Reach is not connected to any
 * ads API: campaigns are registered by hand, results typed in or imported
 * from each ads manager's CSV export.
 */
export const adsService = {
  meta:        ()                => api.get('v1/ads/meta'),
  summary:     (params)          => api.get('v1/ads/summary', params),
  list:        (params)          => api.get('v1/ads/campaigns', params),
  get:         (id)              => api.get(`v1/ads/campaigns/${id}`),
  create:      (body)            => api.post('v1/ads/campaigns', body),
  update:      (id, body)        => api.put(`v1/ads/campaigns/${id}`, body),
  archive:     (id)              => api.delete(`v1/ads/campaigns/${id}`),
  launchPlan:  (body)            => api.post('v1/ads/campaigns/launch-plan', body),
  results:     (id)              => api.get(`v1/ads/campaigns/${id}/results`),
  saveResult:  (id, body)        => api.put(`v1/ads/campaigns/${id}/results`, body),
  deleteResult:(resultId)        => api.delete(`v1/ads/results/${resultId}`),
  utmLink:     (body)            => api.post('v1/ads/utm-link', body),
  /** file: a File from <input type="file">; format: google_ads | meta | linkedin | aicountly_signups | auto */
  importCsv: (file, format, dryRun) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('format', format || 'auto');
    fd.append('dry_run', dryRun ? '1' : '0');
    return api.upload('v1/ads/import', fd);
  },
};
