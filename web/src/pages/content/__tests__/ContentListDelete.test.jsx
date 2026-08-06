import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { ContentListPage } from '../ContentListPage';

const ITEM = {
  id: 42,
  content_type: 'blog',
  title: 'Untitled draft',
  slug: 'untitled-draft',
  workflow_status: 'published',
  risk_level: 'low',
  created_at: '2026-08-05T00:00:00Z',
};

/** Fetch stub: the list call returns one item, everything else succeeds empty. */
function mockApi(deleteHandler) {
  global.fetch = vi.fn((url, options = {}) => {
    if ((options.method || 'GET') === 'DELETE') {
      return deleteHandler(url, options);
    }
    return Promise.resolve({
      ok: true,
      json: () => Promise.resolve({ ok: true, data: { items: [ITEM], total: 1 } }),
    });
  });
}

const ok = () => Promise.resolve({
  ok: true,
  json: () => Promise.resolve({ ok: true, data: { deleted: true } }),
});

const auth = (permissions) => ({
  auth: { user: { id: 1, email: 'admin@test.com', role: 'reach_admin' }, permissions },
});

beforeEach(() => {
  vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('ContentListPage delete', () => {
  it('hides the delete button without content.delete', async () => {
    mockApi(ok);
    renderWithAuth(<ContentListPage />, auth(['content.view']));

    await waitFor(() => expect(screen.getByText('Untitled draft')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });

  it('purges the item and reloads the list', async () => {
    mockApi(ok);
    renderWithAuth(<ContentListPage />, auth(['content.view', 'content.delete']));

    await waitFor(() => expect(screen.getByText('Untitled draft')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => {
      const purge = global.fetch.mock.calls.find(([url]) => String(url).includes('/purge'));
      expect(purge).toBeTruthy();
      expect(purge[1].method).toBe('DELETE');
      expect(JSON.parse(purge[1].body).force).toBe(false);
    });
  });

  it('retries with force when the takedown fails and the operator confirms', async () => {
    let attempt = 0;
    mockApi(() => {
      attempt += 1;
      if (attempt === 1) {
        return Promise.resolve({
          ok: false,
          status: 409,
          headers: { get: () => null },
          json: () => Promise.resolve({
            ok: false,
            error: 'This item is still live on AICOUNTLY.com and the takedown failed. '
              + 'Unpublish it first, or delete with force to remove it from Reach only.',
          }),
        });
      }
      return ok();
    });

    renderWithAuth(<ContentListPage />, auth(['content.view', 'content.delete']));

    await waitFor(() => expect(screen.getByText('Untitled draft')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => {
      const forced = global.fetch.mock.calls
        .filter(([url]) => String(url).includes('/purge'))
        .map(([, options]) => JSON.parse(options.body));
      expect(forced).toHaveLength(2);
      expect(forced[1].force).toBe(true);
    });
  });

  it('surfaces the error when the operator declines the force retry', async () => {
    window.confirm.mockReturnValueOnce(true).mockReturnValueOnce(false);
    mockApi(() => Promise.resolve({
      ok: false,
      status: 409,
      headers: { get: () => null },
      json: () => Promise.resolve({ ok: false, error: 'Unpublish it first, or delete with force.' }),
    }));

    renderWithAuth(<ContentListPage />, auth(['content.view', 'content.delete']));

    await waitFor(() => expect(screen.getByText('Untitled draft')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));

    await waitFor(() => expect(screen.getByText(/Unpublish it first/i)).toBeInTheDocument());
    expect(global.fetch.mock.calls.filter(([url]) => String(url).includes('/purge'))).toHaveLength(1);
  });
});
