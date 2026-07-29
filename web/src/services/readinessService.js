import api from './api.js';

/** Phase 9 — Product readiness / release acceptance API helpers. */

export function getReleasePrerequisites() {
  return api.get('v1/readiness/release/prerequisites');
}

export function getLatestReleaseAcceptance() {
  return api.get('v1/readiness/release/latest');
}

export function createReleaseAcceptance(body) {
  return api.post('v1/readiness/release', body);
}

export function listFindings(params = {}) {
  return api.get('v1/readiness/findings', params);
}

export function listFindingBlockers() {
  return api.get('v1/readiness/findings/blockers');
}

export function listDisasterRecovery() {
  return api.get('v1/readiness/disaster-recovery');
}

export function recordDisasterRecoveryTest(body) {
  return api.post('v1/readiness/disaster-recovery', body);
}

export function listOperationalChecks() {
  return api.get('v1/readiness/operational-checks');
}

export function ensureOperationalCheckDefaults() {
  return api.post('v1/readiness/operational-checks/ensure-defaults');
}

export function upsertOperationalCheck(body) {
  return api.post('v1/readiness/operational-checks', body);
}

export function listTechnicalDebt(params = {}) {
  return api.get('v1/readiness/technical-debt', params);
}

export function getOperationsSummary(params = {}) {
  return api.get('v1/readiness/operations/summary', params);
}

export function listAttributionModels(params = {}) {
  return api.get('v1/readiness/attribution/models', params);
}

export function registerAttributionModel(body) {
  return api.post('v1/readiness/attribution/models', body);
}

export function activateAttributionModel(id) {
  return api.post(`v1/readiness/attribution/models/${id}/activate`);
}

/** Refresh outcomes — observational post-refresh changes only. */
export function listRefreshOutcomes(params = {}) {
  return api.get('v1/readiness/outcomes', params);
}

export function getRefreshOutcome(id) {
  return api.get(`v1/readiness/outcomes/${id}`);
}

export function measureRefreshOutcome(id) {
  return api.post(`v1/readiness/outcomes/${id}/measure`);
}
