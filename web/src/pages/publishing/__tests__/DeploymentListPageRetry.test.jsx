import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { Routes, Route } from 'react-router-dom';
import { renderWithAuth } from '../../../test/renderWithAuth';
import DeploymentListPage from '../DeploymentListPage.jsx';

const get = vi.fn();
const post = vi.fn();

vi.mock('../../../services/api', () => ({
  default: { get: (...a) => get(...a), post: (...a) => post(...a) },
  api: { get: (...a) => get(...a), post: (...a) => post(...a) },
}));

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view', 'publishing.publish'],
  },
  route: '/publishing/deployments',
};

function deployment(overrides = {}) {
  return {
    id: 5,
    content_item_id: 4,
    content_title: 'Bookkeeping Checklist for Indian SMEs',
    content_type: 'blog',
    operation: 'publish',
    status: 'failed',
    attempt_count: 1,
    updated_at: '2026-08-03T10:16:25Z',
    ...overrides,
  };
}

function render() {
  return renderWithAuth(
    <Routes>
      <Route path="/publishing/deployments" element={<DeploymentListPage />} />
    </Routes>,
    ctx,
  );
}

describe('DeploymentListPage — retry is only offered when it can work', () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
  });

  it('offers Retry for a transient failure', async () => {
    get.mockResolvedValue({
      items: [deployment({ error_category: 'server_error', retryable: true })],
      total: 1,
      last_page: 1,
    });
    render();

    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument());
  });

  it('does not offer Retry for an authentication failure', async () => {
    // Clicking this returned "Error category 'authentication_error' is not
    // retryable" — the button was offering an action the API always refused.
    get.mockResolvedValue({
      items: [deployment({ error_category: 'authentication_error', retryable: false })],
      total: 1,
      last_page: 1,
    });
    render();

    await waitFor(() => expect(screen.getByText(/Bookkeeping Checklist/)).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
  });

  it('does not offer Retry for a version conflict, and says what to do instead', async () => {
    get.mockResolvedValue({
      items: [deployment({ id: 30, error_category: 'version_conflict', retryable: false })],
      total: 1,
      last_page: 1,
    });
    render();

    await waitFor(() => expect(screen.getByText('Publish again')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
    expect(screen.getByText('Publish again').closest('a')).toHaveAttribute('href', '/content/4');
  });

  it('shows neither action on a healthy deployment', async () => {
    get.mockResolvedValue({
      items: [deployment({ status: 'published', error_category: null, retryable: false })],
      total: 1,
      last_page: 1,
    });
    render();

    await waitFor(() => expect(screen.getByText(/Bookkeeping Checklist/)).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
    expect(screen.queryByText('Publish again')).not.toBeInTheDocument();
  });

  it('surfaces the failure reason in the row', async () => {
    get.mockResolvedValue({
      items: [deployment({
        error_category: 'version_conflict',
        redacted_error: 'Content version conflict on the public site.',
        retryable: false,
      })],
      total: 1,
      last_page: 1,
    });
    render();

    await waitFor(() => expect(screen.getByText('version_conflict')).toBeInTheDocument());
    expect(screen.getByText(/Content version conflict/)).toBeInTheDocument();
  });

  it('falls back to the status when an older API omits the flag', async () => {
    get.mockResolvedValue({ items: [deployment({ error_category: 'server_error' })], total: 1, last_page: 1 });
    render();

    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument());
  });
});
