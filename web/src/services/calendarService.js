import { api } from './api';

export const calendarService = {
  list:   (params) => api.get('v1/calendar/items', params),
  create: (body)   => api.post('v1/calendar/items', body),
  update: (id, b)  => api.put(`v1/calendar/items/${id}`, b),
  remove: (id)     => api.delete(`v1/calendar/items/${id}`),
};
