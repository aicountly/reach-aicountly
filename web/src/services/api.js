// Base URL without trailing slash or /v1 — services append paths like v1/auth/login.
import { redirectToConsoleLogin } from './consoleAuth';

const API_URL = (import.meta.env.VITE_API_URL || '/api')
  .replace(/\/$/, '')
  .replace(/\/v1$/, '');

/**
 * Uniform request. Backend responds with { ok, data } or { ok:false, error, details? }.
 * We return `data` (for ok responses) and throw an Error with the server message otherwise.
 */
async function request(path, options = {}) {
  const token = localStorage.getItem('reach_token');
  const headers = {
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  };

  const url = `${API_URL}/${String(path).replace(/^\/+/, '')}`;
  let response;
  try {
    response = await fetch(url, {
      credentials: 'include',
      ...options,
      headers,
    });
  } catch (err) {
    throw new Error(err?.message || 'Network error contacting Reach API.');
  }

  let json = null;
  try {
    json = await response.json();
  } catch {
    throw new Error(`Request failed with status ${response.status}`);
  }

  if (response.status === 401 && token) {
    localStorage.removeItem('reach_token');
    redirectToConsoleLogin();
  }

  if (!response.ok || json?.ok === false) {
    const msg = json?.error || `Request failed with status ${response.status}`;
    const err = new Error(msg);
    err.status  = response.status;
    err.details = json?.details;
    throw err;
  }
  return json?.data ?? json;
}

export const api = {
  get:    (path, params) => request(withQuery(path, params)),
  post:   (path, body)   => request(path, { method: 'POST', body: body ? JSON.stringify(body) : null }),
  put:    (path, body)   => request(path, { method: 'PUT',  body: body ? JSON.stringify(body) : null }),
  delete: (path)         => request(path, { method: 'DELETE' }),
};

function withQuery(path, params) {
  if (!params) return path;
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    qs.set(k, String(v));
  });
  const str = qs.toString();
  return str ? `${path}?${str}` : path;
}
