import { api } from './api';

/** Claude-routine blog / KB submissions (Gemini vetting decision, cover, publish state). */
export const routineSubmissionService = {
  list: (params) => api.get('v1/routine-submissions', params),
  retry: (id) => api.post(`v1/routine-submissions/${id}/retry`),
  /** step: retry | revet | rewrite (RoutineRemediator); note goes to the routine with a rewrite. */
  remedy: (id, step, note) => api.post(`v1/routine-submissions/${id}/remedy`, note ? { step, note } : { step }),
  /** The bot's next step on every blocked submission, now. */
  fixBlocked: () => api.post('v1/routine-submissions/fix-blocked', {}),
};
