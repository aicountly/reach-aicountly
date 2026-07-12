/**
 * Phase 2 — Unified Content Studio API service.
 *
 * All calls go to /api/v1/content/* and mirror the knowledgeService.js pattern.
 * Authentication token is read from localStorage (set by AuthContext).
 */

const BASE = '/api/v1';

function authHeader() {
  const token = localStorage.getItem('reach_token');
  return token ? { Authorization: `Bearer ${token}` } : {};
}

async function request(method, path, body) {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', ...authHeader() },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json();
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data.data ?? data;
}

// ── Content Items ──────────────────────────────────────────────────────────

export const contentService = {
  // Items
  listItems: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return request('GET', `/content/items${qs ? `?${qs}` : ''}`);
  },
  getItem: (id) => request('GET', `/content/items/${id}`),
  createItem: (data) => request('POST', '/content/items', data),
  updateItem: (id, data) => request('PUT', `/content/items/${id}`, data),
  deleteItem: (id, reason) => request('DELETE', `/content/items/${id}`, { reason }),
  getTransitions: (id) => request('GET', `/content/items/${id}/transitions`),

  // Workflow
  submitItem: (id) => request('POST', `/content/items/${id}/submit`),
  approveItem: (id, stage, comment) => request('POST', `/content/items/${id}/approve`, { stage, comment }),
  rejectItem: (id, stage, reason) => request('POST', `/content/items/${id}/reject`, { stage, reason }),
  requestChanges: (id, reason) => request('POST', `/content/items/${id}/request-changes`, { reason }),
  archiveItem: (id, reason) => request('POST', `/content/items/${id}/archive`, { reason }),

  // Versions
  listVersions: (id) => request('GET', `/content/items/${id}/versions`),
  createVersion: (id, data) => request('POST', `/content/items/${id}/versions`, data),
  compareVersions: (id, a, b) => request('GET', `/content/items/${id}/versions/compare?a=${a}&b=${b}`),

  // Brief
  getBrief: (id) => request('GET', `/content/items/${id}/brief`),
  upsertBrief: (id, data) => request('POST', `/content/items/${id}/brief`, data),

  // Comments
  listComments: (id, includeResolved = false) =>
    request('GET', `/content/items/${id}/comments?include_resolved=${includeResolved}`),
  addComment: (id, body, options = {}) => request('POST', `/content/items/${id}/comments`, { body, ...options }),
  resolveComment: (id, commentId) => request('POST', `/content/items/${id}/comments/${commentId}/resolve`),
  deleteComment: (id, commentId) => request('DELETE', `/content/items/${id}/comments/${commentId}`),

  // Validations
  listValidations: (id) => request('GET', `/content/items/${id}/validations`),
  storeValidation: (id, data) => request('POST', `/content/items/${id}/validations`, data),
  waiveValidation: (id, validationId, reason) =>
    request('POST', `/content/items/${id}/validations/${validationId}/waive`, { reason }),

  // Assignments
  listAssignments: (id) => request('GET', `/content/items/${id}/assignments`),
  assign: (id, userId, role, options = {}) =>
    request('POST', `/content/items/${id}/assignments`, { user_id: userId, role, ...options }),
  unassign: (id, assignmentId) => request('DELETE', `/content/items/${id}/assignments/${assignmentId}`),

  // Schedules
  listSchedules: (id) => request('GET', `/content/items/${id}/schedules`),
  createSchedule: (id, data) => request('POST', `/content/items/${id}/schedules`, data),
  cancelSchedule: (id, scheduleId, reason) =>
    request('DELETE', `/content/items/${id}/schedules/${scheduleId}`, { reason }),

  // Knowledge mappings
  getMappings: (id) => request('GET', `/content/items/${id}/mappings`),
  syncMappings: (id, mappings) => request('PUT', `/content/items/${id}/mappings`, mappings),
  addMapping: (id, type, entityId) => request('POST', `/content/items/${id}/mappings/${type}`, { entity_id: entityId }),
  removeMapping: (id, type, entityId) => request('DELETE', `/content/items/${id}/mappings/${type}/${entityId}`),

  // Publication targets
  listTargets: () => request('GET', '/content/publication-targets'),
  createTarget: (data) => request('POST', '/content/publication-targets', data),
  updateTarget: (id, data) => request('PUT', `/content/publication-targets/${id}`, data),

  // Daily packs
  listPacks: () => request('GET', '/content/daily-packs'),
  getPack: (id) => request('GET', `/content/daily-packs/${id}`),
  generatePack: (data) => request('POST', '/content/daily-packs/generate', data),
  assignPackItem: (packId, slotId, contentItemId) =>
    request('PUT', `/content/daily-packs/${packId}/items/${slotId}`, { content_item_id: contentItemId }),
  getPackConfig: () => request('GET', '/content/daily-packs/config'),
  updatePackConfig: (config) => request('PUT', '/content/daily-packs/config', config),

  // Approval queue
  getApprovalQueue: (params = {}) => {
    const qs = new URLSearchParams(params).toString();
    return request('GET', `/approval-queue${qs ? `?${qs}` : ''}`);
  },
  getApprovalStats: () => request('GET', '/approval-queue/stats'),
  queueApprove: (id, data = {}) => request('POST', `/approval-queue/${id}/approve`, data),
  queueReject: (id, reason) => request('POST', `/approval-queue/${id}/reject`, { reason }),
  queueReturn: (id, reason) => request('POST', `/approval-queue/${id}/return`, { reason }),
  queueWaiveValidation: (id, validationId, reason) =>
    request('POST', `/approval-queue/${id}/waive-validation`, { validation_id: validationId, reason }),
  bulkApprove: (ids) => request('POST', '/approval-queue/bulk-approve', { ids }),

  // Notifications
  listNotifications: () => request('GET', '/notifications'),
  getUnreadCount: () => request('GET', '/notifications/count'),
  markRead: (id) => request('POST', `/notifications/${id}/read`),
  markAllRead: () => request('POST', '/notifications/read-all'),
};
