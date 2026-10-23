import { api } from './api';

/** Command centre → Rankings drill-downs (same permission as the command centre). */
export const rankingsDrilldownService = {
  keywords: () => api.get('v1/dashboard/command-centre/rankings/keywords'),
  aiAnswers: (params) => api.get('v1/dashboard/command-centre/rankings/ai-answers', params),
};
