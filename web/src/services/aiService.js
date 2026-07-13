/**
 * Phase 3 — AI Generation and Prompt Service.
 *
 * All requests go through the /api/v1/ai/* endpoints.
 * No provider API keys are ever stored or sent from the frontend.
 */

const BASE = '/api/v1/ai';

function authHeader() {
  const token = localStorage.getItem('reach_token');
  return token ? { Authorization: `Bearer ${token}` } : {};
}

async function request(method, path, body) {
  const opts = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...authHeader(),
    },
  };
  if (body !== undefined) opts.body = JSON.stringify(body);

  const res = await fetch(`${BASE}${path}`, opts);
  const json = await res.json();

  if (!res.ok || !json.ok) {
    const msg = json?.error || `HTTP ${res.status}`;
    throw new Error(msg);
  }

  return json.data ?? json;
}

// --- Generation ---

export function requestGeneration(payload) {
  return request('POST', '/generate', payload);
}

export function listGenerations(page = 1) {
  return request('GET', `/generations?page=${page}`);
}

export function getGeneration(uuid) {
  return request('GET', `/generations/${uuid}`);
}

export function cancelGeneration(uuid, reason) {
  return request('POST', `/generations/${uuid}/cancel`, { reason });
}

// --- Prompts ---

export function listPrompts(page = 1) {
  return request('GET', `/prompts?page=${page}`);
}

export function getPrompt(idOrSlug) {
  return request('GET', `/prompts/${idOrSlug}`);
}

export function createPromptTemplate(data) {
  return request('POST', '/prompts', data);
}

export function listPromptVersions(templateId) {
  return request('GET', `/prompts/${templateId}/versions`);
}

export function createPromptVersion(templateId, data) {
  return request('POST', `/prompts/${templateId}/versions`, data);
}

export function approvePromptVersion(templateId, versionId) {
  return request('POST', `/prompts/${templateId}/versions/${versionId}/approve`, {});
}

export function listSchemaTypes() {
  return request('GET', '/prompts/schema-types');
}

// --- Control Centre ---

export function listAiProviders(page = 1) {
  return request('GET', `/providers?page=${page}`);
}

export function getAiProvider(id) {
  return request('GET', `/providers/${id}`);
}

export function updateAiProviderStatus(id, status) {
  return request('PATCH', `/providers/${id}/status`, { status });
}

export function listAiModels(page = 1) {
  return request('GET', `/models?page=${page}`);
}

export function listAiUsage(filters = {}) {
  const params = new URLSearchParams(filters).toString();
  return request('GET', `/usage?${params}`);
}

export function listAiBudgets() {
  return request('GET', '/budgets');
}

export function updateAiBudget(id, data) {
  return request('PUT', `/budgets/${id}`, data);
}

export function getAiDashboard() {
  return request('GET', '/dashboard');
}

export function getAiHealth() {
  return request('GET', '/health');
}

const aiService = {
  requestGeneration,
  listGenerations,
  getGeneration,
  cancelGeneration,
  listPrompts,
  getPrompt,
  createPromptTemplate,
  listPromptVersions,
  createPromptVersion,
  approvePromptVersion,
  listSchemaTypes,
  listAiProviders,
  getAiProvider,
  updateAiProviderStatus,
  listAiModels,
  listAiUsage,
  listAiBudgets,
  updateAiBudget,
  getAiDashboard,
  getAiHealth,
};

export default aiService;
