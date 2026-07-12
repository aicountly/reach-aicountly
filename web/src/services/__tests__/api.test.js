import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../consoleAuth', () => ({
  redirectToConsoleLogin: vi.fn(),
}));

import { api } from '../api';
import { redirectToConsoleLogin } from '../consoleAuth';

function jsonResponse(status, body, headers = {}) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: {
      get: (name) => {
        const lower = name.toLowerCase();
        const key = Object.keys(headers).find((k) => k.toLowerCase() === lower);
        return key ? headers[key] : null;
      },
    },
    json: async () => body,
  };
}

describe('api wrapper', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
    localStorage.clear();
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('unwraps { ok:true, data } on success and returns data', async () => {
    global.fetch.mockResolvedValueOnce(jsonResponse(200, { ok: true, data: { hello: 'world' } }));
    const out = await api.get('v1/ping');
    expect(out).toEqual({ hello: 'world' });
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('on 401 with a stored token, clears the token and redirects to Console login', async () => {
    localStorage.setItem('reach_token', 'stale.jwt.token');
    global.fetch.mockResolvedValueOnce(jsonResponse(401, { ok: false, error: 'Unauthorized' }));
    await expect(api.get('v1/me')).rejects.toMatchObject({ status: 401 });
    expect(localStorage.getItem('reach_token')).toBeNull();
    expect(redirectToConsoleLogin).toHaveBeenCalledTimes(1);
  });

  it('surfaces 403 as an Error with .status and .details', async () => {
    global.fetch.mockResolvedValueOnce(
      jsonResponse(403, { ok: false, error: 'Forbidden', details: { required: 'blog.approve' } }),
    );
    await expect(api.post('v1/blog/posts/1/approve')).rejects.toMatchObject({
      status: 403,
      message: 'Forbidden',
      details: { required: 'blog.approve' },
    });
  });

  it('parses Retry-After header on 429 and exposes err.retryAfter', async () => {
    global.fetch.mockResolvedValueOnce(
      jsonResponse(
        429,
        { ok: false, error: 'Too many requests', retry_after: 30 },
        { 'Retry-After': '45', 'X-Request-Id': 'req_abc123' },
      ),
    );
    try {
      await api.post('v1/bot/dispatch', { action: 'generate_blog_draft' });
      throw new Error('should have thrown');
    } catch (err) {
      expect(err.status).toBe(429);
      expect(err.retryAfter).toBe(45); // header wins
      expect(err.requestId).toBe('req_abc123');
    }
  });

  it('falls back to retry_after body value when Retry-After header missing', async () => {
    global.fetch.mockResolvedValueOnce(
      jsonResponse(429, { ok: false, error: 'Too many requests', retry_after: 12 }),
    );
    try {
      await api.get('v1/leads');
      throw new Error('should have thrown');
    } catch (err) {
      expect(err.status).toBe(429);
      expect(err.retryAfter).toBe(12);
    }
  });
});
