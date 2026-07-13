import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import BlogPublishingListPage from '../BlogPublishingListPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

const makeDeployment = (status) => ({
  id: Math.floor(Math.random() * 1000),
  content_item_id: 1,
  content_title: `${status} Article`,
  status,
  canonical_url: null,
  attempt_count: 1,
  updated_at: null,
});

describe('BlogPublishingListPage status badges', () => {
  it.each([
    ['published', 'badge--success'],
    ['verified', 'badge--success'],
    ['failed', 'badge--error'],
    ['blocked', 'badge--error'],
    ['queued', 'badge--info'],
    ['sending', 'badge--info'],
    ['cancelled', 'badge--neutral'],
    ['draft', 'badge--neutral'],
  ])('status %s renders with %s class', async (status, expectedClass) => {
    api.get.mockResolvedValueOnce({ data: { data: [makeDeployment(status)] } });
    const { unmount } = renderWithAuth(<BlogPublishingListPage />, ctx);
    await waitFor(() => expect(screen.getByText(status)).toBeInTheDocument());
    const badge = screen.getByText(status);
    expect(badge.className).toContain(expectedClass);
    unmount();
  });
});
