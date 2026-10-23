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

  it('calls the v1 publishing calendar endpoint', async () => {
    api.get.mockResolvedValueOnce([]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(api.get).toHaveBeenCalledWith('v1/publishing/calendar'));
  });

  it('renders page header', async () => {
    api.get.mockResolvedValueOnce([]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText('Publication Calendar')).toBeInTheDocument());
  });

  it('shows empty state', async () => {
    api.get.mockResolvedValueOnce([]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText(/No scheduled publications/i)).toBeInTheDocument());
  });

  it('groups items by date', async () => {
    api.get.mockResolvedValueOnce([
      { id: 1, content_type: 'blog', content_title: 'GST Article', status: 'scheduled', scheduled_at: '2026-08-01T09:00:00Z' },
      { id: 2, content_type: 'knowledge_base', content_title: 'GST KB', status: 'queued', scheduled_at: '2026-08-01T14:00:00Z' },
      { id: 3, content_type: 'blog', content_title: 'TDS Article', status: 'scheduled', scheduled_at: '2026-08-02T09:00:00Z' },
    ]);
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText('01-08-2026')).toBeInTheDocument());
    expect(screen.getByText('02-08-2026')).toBeInTheDocument();
    expect(screen.getByText('GST Article')).toBeInTheDocument();
    expect(screen.getByText('TDS Article')).toBeInTheDocument();
  });

  it('handles API error', async () => {
    api.get.mockRejectedValueOnce(new Error('Calendar error'));
    renderWithAuth(<PublishingCalendarPage />, ctx);
    await waitFor(() => expect(screen.getByText(/Calendar error/i)).toBeInTheDocument());
  });
});

describe('PublishingCalendarPage drill-down', () => {
  it('each entry opens its content item and its deployment', async () => {
    api.get.mockResolvedValueOnce([
      { id: 31, status: 'scheduled', scheduled_at: '2026-10-25T04:30:00Z', content_item_id: 12, content_title: 'GST guide', content_type: 'blog' },
    ]);
    renderWithAuth(<PublishingCalendarPage />, ctx);

    expect(await screen.findByRole('link', { name: 'GST guide' })).toHaveAttribute('href', '/content/12');
    expect(screen.getByRole('link', { name: '(scheduled)' })).toHaveAttribute('href', '/publishing/deployments/31');
  });
});
