import { api } from './api';

export const adminService = {
  settings:        () => api.get('v1/settings'),
  updateSettings:  (body) => api.put('v1/settings', body),
  automationSettings:       () => api.get('v1/settings/automation'),
  updateAutomationSettings: (body) => api.put('v1/settings/automation', body),
  testAutomationProvider:   (target) => api.post('v1/settings/automation/test', { target }),
  geminiModels:             () => api.get('v1/settings/automation/gemini-models'),
  auditLogs:       (params) => api.get('v1/audit-logs', params),
  health:          () => api.get('v1/admin/api-health'),
  consoleSync:     () => api.get('v1/admin/console-sync-status'),
  workerStatus:    () => api.get('v1/admin/worker-status'),
  pingWorker:      () => api.post('v1/admin/worker-status/ping'),
};
