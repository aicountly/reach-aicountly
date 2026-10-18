import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';

vi.mock('../../../services/api', () => ({
  default: { get: vi.fn() },
}));
import api from '../../../services/api';
import PublishingCalendarPage from '../PublishingCalendarPage';

const ctx = {
  auth: {
    user: { id: 1, email: 'admin@aicountly.com', role: 'super_admin' },
    permissions: ['publishing.view'],
  },
};

beforeEach(() => {
  api.get.mockReset();
});

describe('PublishingCalendarPage grouping', () => {
  it('shows Unscheduled group for items without scheduled_at', async () => {
    api.get.mockResolvedValueOnce([
      { id: 1, content_type: 'blog', content_title: 'Draft Post', status: 'queued', scheduled_at: null },
    ]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText('Unscheduled')).toBeInTheDocument());
  });

  it('sorts dates ascending', async () => {
    api.get.mockResolvedValueOnce([
      { id: 2, content_type: 'blog', content_title: 'Later Article', status: 'scheduled', scheduled_at: '2026-09-01T09:00:00Z' },
      { id: 1, content_type: 'blog', content_title: 'Earlier Article', status: 'scheduled', scheduled_at: '2026-08-01T09:00:00Z' },
    ]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => {
      const dateHeaders = document.querySelectorAll('.calendar-group__date');
      expect(dateHeaders[0].textContent).toBe('01-08-2026');
      expect(dateHeaders[1].textContent).toBe('01-09-2026');
    });
  });

  it('groups by the IST day, not the UTC date prefix', async () => {
    // 20:00 UTC on 1 Aug is 01:30 IST on 2 Aug.
    api.get.mockResolvedValueOnce([
      { id: 1, content_type: 'blog', content_title: 'Late Post', status: 'scheduled', scheduled_at: '2026-08-01T20:00:00Z' },
      { id: 2, content_type: 'blog', content_title: 'Naive UTC Post', status: 'scheduled', scheduled_at: '2026-08-01 19:00:00' },
    ]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => {
      const dateHeaders = [...document.querySelectorAll('.calendar-group__date')].map((h) => h.textContent);
      expect(dateHeaders).toEqual(['02-08-2026']);
    });
  });

  it('groups multiple items on the same date', async () => {
    api.get.mockResolvedValueOnce([
      { id: 1, content_type: 'blog', content_title: 'Morning Post', status: 'scheduled', scheduled_at: '2026-08-15T09:00:00Z' },
      { id: 2, content_type: 'knowledge_base', content_title: 'KB Article', status: 'queued', scheduled_at: '2026-08-15T14:00:00Z' },
    ]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => {
      const groups = document.querySelectorAll('.calendar-group');
      expect(groups).toHaveLength(1);
    });
    expect(screen.getByText('Morning Post')).toBeInTheDocument();
    expect(screen.getByText('KB Article')).toBeInTheDocument();
  });
});
