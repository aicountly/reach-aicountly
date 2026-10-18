import { api } from './api';

/** A fresh idempotency key: one per click / per command draft, so a retried request never runs twice. */
export function newIdempotencyKey(prefix = 'web') {
  const id = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
  return `${prefix}-${id}`;
}

/** Bot → Activities (Run now) and Bot → Ask the bot (owner commands to the reach-command routine). */
export const botActionsService = {
  activities: () => api.get('v1/bot/activities'),
  runActivity: (key, idempotencyKey) =>
    api.post(`v1/bot/activities/${encodeURIComponent(key)}/run`, { idempotency_key: idempotencyKey }),
  commands: (params) => api.get('v1/bot/commands', params),
  sendCommand: ({ instruction, channels, attachments, idempotencyKey }) =>
    api.post('v1/bot/commands', { instruction, channels, attachments, idempotency_key: idempotencyKey }),
};
