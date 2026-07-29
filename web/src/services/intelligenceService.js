import api from './api.js';

/**
 * Phase 8 — Intelligence / Attribution API helpers.
 */
export function getAttributionOverview(params = {}) {
  return api.get('v1/intelligence/attribution', params);
}

export function listAttributionConversions(params = {}) {
  return api.get('v1/intelligence/attribution/conversions', params);
}

export function listUtmTemplates(params = {}) {
  return api.get('v1/intelligence/attribution/utm-templates', params);
}

export function createUtmTemplate(body) {
  return api.post('v1/intelligence/attribution/utm-templates', body);
}

export function listVisibilityPrompts(params = {}) {
  return api.get('v1/intelligence/visibility/prompts', params);
}

export function listVisibilityRuns(params = {}) {
  return api.get('v1/intelligence/visibility/runs', params);
}

export function listVisibilityObservations(params = {}) {
  return api.get('v1/intelligence/visibility/observations', params);
}

export function listCompetitors(params = {}) {
  return api.get('v1/intelligence/competitors', params);
}

export function createCompetitor(body) {
  return api.post('v1/intelligence/competitors', body);
}

export function listConnectors(params = {}) {
  return api.get('v1/intelligence/connectors', params);
}

export function upsertConnector(body) {
  return api.post('v1/intelligence/connectors', body);
}

export function healthCheckConnector(id) {
  return api.post(`v1/intelligence/connectors/${id}/health-check`);
}

export function disableConnector(id) {
  return api.post(`v1/intelligence/connectors/${id}/disable`);
}

export function enableConnector(id) {
  return api.post(`v1/intelligence/connectors/${id}/enable`);
}

export function createSearchConnection(body) {
  return api.post('v1/intelligence/search/connections', body);
}

export function createContentConnection(body) {
  return api.post('v1/intelligence/content/connections', body);
}

export function submitIndexNowUrl(body) {
  return api.post('v1/intelligence/indexnow/submit', body);
}

export function retryIndexNowPending() {
  return api.post('v1/intelligence/indexnow/retry-pending');
}

export function getLatestSitemap(params = {}) {
  return api.get('v1/intelligence/sitemaps', params);
}

export function generateSitemapSnapshot(body = {}) {
  return api.post('v1/intelligence/sitemaps/generate', body);
}

export function listSearchMetrics(params = {}) {
  return api.get('v1/intelligence/search/metrics', params);
}

export function listContentMetrics(params = {}) {
  return api.get('v1/intelligence/content/metrics', params);
}

const intelligenceService = {
  getAttributionOverview,
  listAttributionConversions,
  listUtmTemplates,
  createUtmTemplate,
  listVisibilityPrompts,
  listVisibilityRuns,
  listVisibilityObservations,
  listCompetitors,
  createCompetitor,
  listConnectors,
  upsertConnector,
  healthCheckConnector,
  disableConnector,
  enableConnector,
  createSearchConnection,
  createContentConnection,
  submitIndexNowUrl,
  retryIndexNowPending,
  getLatestSitemap,
  generateSitemapSnapshot,
  listSearchMetrics,
  listContentMetrics,
};

export default intelligenceService;
