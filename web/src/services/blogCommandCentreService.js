import { api } from './api';

export const blogCommandCentreService = {
  getOverview: () => api.get('v1/blog-command-centre/overview'),
  getPortfolioSettings: () => api.get('v1/blog-command-centre/settings/portfolio'),
  savePortfolioSettings: (data) => api.put('v1/blog-command-centre/settings/portfolio', data),
  getSettings: () => api.get('v1/blog-command-centre/settings'),
  saveSettings: (data) => api.put('v1/blog-command-centre/settings', data),

  getRoadmapCandidates: (params) => api.get('v1/blog-command-centre/roadmap/candidates', params),
  getRoadmapScored: (params) => api.get('v1/blog-command-centre/roadmap/scored', params),
  getRoadmapDecisions: (params) => api.get('v1/blog-command-centre/roadmap/decisions', params),
  getOptimizerRuns: (params) => api.get('v1/blog-command-centre/roadmap/optimizer-runs', params),
  getScoringWeights: () => api.get('v1/blog-command-centre/roadmap/scoring-weights'),
  saveScoringWeights: (data) => api.put('v1/blog-command-centre/roadmap/scoring-weights', data),
};
