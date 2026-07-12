import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithAuth } from '../../../test/renderWithAuth';
import { NotificationBell } from '../../../components/layout/NotificationBell';

vi.mock('../../../services/contentService', () => ({
  contentService: {
    getNotificationCount: vi.fn().mockResolvedValue({ unread_count: 0 }),
    getNotifications: vi.fn().mockResolvedValue({ notifications: [] }),
    markAllNotificationsRead: vi.fn().mockResolvedValue({ ok: true }),
    markNotificationRead: vi.fn().mockResolvedValue({ ok: true }),
  },
}));

const ctx = {
  auth: {
    user: { id: 1, email: 'reviewer@test.com', role: 'content_reviewer' },
    permissions: ['content.view'],
  },
};

describe('NotificationBell', () => {
  it('renders notification bell button', () => {
    renderWithAuth(<NotificationBell />, ctx);
    const bell = screen.getByRole('button', { name: /notifications/i });
    expect(bell).toBeInTheDocument();
  });

  it('does not show badge when unread count is zero', () => {
    renderWithAuth(<NotificationBell />, ctx);
    const bell = screen.getByRole('button', { name: /notifications/i });
    expect(bell).toBeInTheDocument();
    // count starts at 0, badge does not render
    expect(screen.queryByText('0')).toBeNull();
  });
});
