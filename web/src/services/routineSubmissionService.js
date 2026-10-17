import { api } from './api';

/** Claude-routine blog / KB submissions (Gemini vetting decision, cover, publish state). */
export const routineSubmissionService = {
  list: (params) => api.get('v1/routine-submissions', params),
  retry: (id) => api.post(`v1/routine-submissions/${id}/retry`),
};
