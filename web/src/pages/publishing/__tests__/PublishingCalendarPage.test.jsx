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

describe('PublishingCalendarPage', () => {
  it('shows loading state', () => {
    api.get.mockReturnValue(new Promise(() => {}));
    renderWithAuth(<PublishingCalendarPage />, ctx);
    expect(screen.getByText(/Loading calendar/i)).toBeInTheDocument();
  });

  it('renders page header', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText('Publication Calendar')).toBeInTheDocument());
  });

  it('shows empty state', async () => {
    api.get.mockResolvedValueOnce({ data: { data: [] } });
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No scheduled publications/i)).toBeInTheDocument());
  });

  it('groups items by date', async () => {
    api.get.mockResolvedValueOnce({
      data: {
        data: [
          { id: 1, content_type: 'blog', content_title: 'GST Article', status: 'scheduled', scheduled_at: '2026-08-01T09:00:00Z' },
          { id: 2, content_type: 'knowledge_base', content_title: 'GST KB', status: 'queued', scheduled_at: '2026-08-01T14:00:00Z' },
          { id: 3, content_type: 'blog', content_title: 'TDS Article', status: 'scheduled', scheduled_at: '2026-08-02T09:00:00Z' },
        ],
      },
    });
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText('2026-08-01')).toBeInTheDocument());
    expect(screen.getByText('2026-08-02')).toBeInTheDocument();
    expect(screen.getByText('GST Article')).toBeInTheDocument();
    expect(screen.getByText('TDS Article')).toBeInTheDocument();
  });

  it('handles API error', async () => {
    api.get.mockRejectedValueOnce(new Error('Calendar error'));
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Calendar error/i)).toBeInTheDocument());
  });
});
