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
  return api.get('v1/intelligence/sitemap', params);
}

export function generateSitemapSnapshot(body = {}) {
  return api.post('v1/intelligence/sitemap/generate', body);
}

export function listSearchMetrics(params = {}) {
  return api.get('v1/intelligence/search/metrics', params);
}

export function listSearchConnections(params = {}) {
  return api.get('v1/intelligence/search/connections', params);
}

/** Credential/property diagnosis — why the connector is or is not usable. */
export function getSearchConsoleConfig() {
  return api.get('v1/intelligence/search/config');
}

/** Properties the service account can actually read, straight from Google. */
export function listSearchConsoleProperties() {
  return api.get('v1/intelligence/search/properties');
}

export function checkSearchConnection(id) {
  return api.post(`v1/intelligence/search/connections/${id}/health-check`);
}

export function getSearchConnectionStatus(id) {
  return api.get(`v1/intelligence/search/connections/${id}/status`);
}

export function ingestSearchConnection(id) {
  return api.post(`v1/intelligence/search/connections/${id}/ingest`);
}

export function backfillSearchConnection(id, days = 90) {
  return api.post(`v1/intelligence/search/connections/${id}/backfill`, { days });
}

export function listSearchIngestionRuns(params = {}) {
  return api.get('v1/intelligence/search/runs', params);
}

export function listUnmappedSearchUrls(params = {}) {
  return api.get('v1/intelligence/search/unmapped', params);
}

export function syncContentIdentities(body = {}) {
  return api.post('v1/intelligence/search/sync-identities', body);
}

// Site-wide Search Console view — every row Google returns, not only the
// pages a Reach blog claims. ?from&to default to the 28 days ending at the
// freshest ingested day.
export function getSiteSearchDaily(params = {}) {
  return api.get('v1/intelligence/search/site/daily', params);
}

export function listSiteSearchQueries(params = {}) {
  return api.get('v1/intelligence/search/site/queries', params);
}

export function listSiteSearchPages(params = {}) {
  return api.get('v1/intelligence/search/site/pages', params);
}

export function getSiteSearchPositions(params = {}) {
  return api.get('v1/intelligence/search/site/positions', params);
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

// One drill-down step over the site-wide facts: ?date&query&page&device&country
// &bucket narrow, ?group_by (date|query|page|device|country) groups, and
// ?sort&dir&q&limit&offset page through the groups.
export function exploreSiteSearch(params = {}) {
  return api.get('v1/intelligence/search/site/explore', params);
}
