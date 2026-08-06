import { api } from './api';

export const contentBaseService = {
  /** Shared base across blog, knowledge base and community Q&A. */
  overview: () => api.get('v1/content-base'),
  /** Blog-only slice, used by the Blog Command Centre roadmap tab. */
  blog: () => api.get('v1/blog/content-base'),
};

export default contentBaseService;
