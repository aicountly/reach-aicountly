import { api } from './api';

/** Social → Inbox: what the social inbox bot read from social-api.ai and did with it. */
export const socialInboxService = {
  list:    (params) => api.get('v1/social/inbox', params),
  summary: ()       => api.get('v1/social/inbox/summary'),
  retry:   (id)     => api.post(`v1/social/inbox/${id}/retry`),
};
