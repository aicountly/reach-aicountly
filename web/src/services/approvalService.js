import { api } from './api';

export const approvalService = {
  list: (params) => api.get('v1/approvals', params),
  get:  (id)     => api.get(`v1/approvals/${id}`),
  decide:(id, decision, note) => api.post(`v1/approvals/${id}/decide`, { decision, note }),
};
