import api from './api.js';

/**
 * AI visibility (GEO) and AI Insights API helpers.
 */

// ── AI visibility ─────────────────────────────────────────────────────────
export function getVisibilitySummary(days = 28) {
  return api.get('v1/intelligence/visibility/summary', { days });
}

export function listVisibilityAnswers(params = {}) {
  return api.get('v1/intelligence/visibility/answers', params);
}

export function listVisibilityProviders() {
  return api.get('v1/intelligence/visibility/providers');
}

export function listVisibilityBatches(params = {}) {
  return api.get('v1/intelligence/visibility/batches', params);
}

export function runVisibilityBatch() {
  return api.post('v1/intelligence/visibility/batches');
}

export function listAllVisibilityPrompts() {
  return api.get('v1/intelligence/visibility/prompts', { status: 'all' });
}

export function createVisibilityPrompt(body) {
  return api.post('v1/intelligence/visibility/prompts', body);
}

export function updateVisibilityPrompt(id, body) {
  return api.put(`v1/intelligence/visibility/prompts/${id}`, body);
}

export function createVisibilityPromptVersion(promptId, promptText) {
  return api.post(`v1/intelligence/visibility/prompts/${promptId}/versions`, { prompt_text: promptText });
}

export function approveVisibilityPromptVersion(promptId, versionId) {
  return api.post(`v1/intelligence/visibility/prompts/${promptId}/versions/${versionId}/approve`);
}

export function queueVisibilityPromptRun(versionId) {
  return api.post('v1/intelligence/visibility/runs', { prompt_version_id: versionId });
}

// ── AI Insights ───────────────────────────────────────────────────────────
export function getLatestInsights() {
  return api.get('v1/intelligence/insights/latest');
}

export function listInsights(params = {}) {
  return api.get('v1/intelligence/insights', params);
}

export function getInsight(id) {
  return api.get(`v1/intelligence/insights/${id}`);
}

export function runInsights() {
  return api.post('v1/intelligence/insights/run');
}

export function listInsightActions(params = {}) {
  return api.get('v1/intelligence/insights/actions', params);
}

export function updateInsightAction(id, status, note) {
  return api.patch(`v1/intelligence/insights/actions/${id}`, note ? { status, note } : { status });
}

export function listStrategyMemos(params = {}) {
  return api.get('v1/intelligence/insights/memos', params);
}

export function getStrategyMemo(id) {
  return api.get(`v1/intelligence/insights/memos/${id}`);
}
