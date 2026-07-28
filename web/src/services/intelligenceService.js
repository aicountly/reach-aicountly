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

export function listConnectors(params = {}) {
  return api.get('v1/intelligence/connectors', params);
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
  listConnectors,
  listSearchMetrics,
  listContentMetrics,
};

export default intelligenceService;
