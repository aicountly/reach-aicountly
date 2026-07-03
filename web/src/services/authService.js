import { api } from './api';

export const authService = {
  login: (email, password) => api.post('v1/auth/login', { email, password }),
  logout: () => api.post('v1/auth/logout'),
  me: () => api.get('v1/me'),
};
